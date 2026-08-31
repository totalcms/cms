<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JobQueue\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Support\Config;

/**
 * The core queue mechanics: claiming a job, scheduling one for later, retrying,
 * clearing, and counting. Sibling files already cover the index, maintenance
 * and clear-failed paths.
 *
 * These run against a real SQLite file in a per-worker temp dir rather than a
 * mocked PDO — the behaviour under test *is* the SQL (which row `fetchNextJob`
 * picks, what `scheduledAt <= CURRENT_TIMESTAMP` excludes), and a mock would
 * only assert the query string we already wrote.
 */
final class JobRepositoryQueueTest extends TestCase
{
	private string $datadir;

	protected function setUp(): void
	{
		$this->datadir = \tcmsTestTempDir('tcms-jq-queue');
	}

	protected function tearDown(): void
	{
		foreach (['jobqueue', '.jobqueue-vacuum'] as $file) {
			$path = $this->datadir . '/.system/' . $file;
			if (file_exists($path)) {
				unlink($path);
			}
		}
		foreach ([$this->datadir . '/.system', $this->datadir] as $dir) {
			if (is_dir($dir)) {
				rmdir($dir);
			}
		}
	}

	private function repository(): JobRepository
	{
		return new JobRepository(new Config([
			'env'        => 'test',
			'template'   => sys_get_temp_dir(),
			'dashboard'  => [],
			'datadir'    => $this->datadir,
			'tmpdir'     => sys_get_temp_dir(),
			'cachedir'   => sys_get_temp_dir() . '/cache',
			'cache'      => [],
			'logger'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'url'        => 'http://test.com',
			'api'        => 'http://test.com',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'imageworks' => [],
			'smtp'       => [],
			'mailer'     => [],
		]));
	}

	// ── Claiming work ────────────────────────────────────────────────────────

	public function testClaimingAJobTakesItOutOfThePendingPool(): void
	{
		// Two cron workers can run at once. If fetchNextJob left the row
		// pending, both would claim the same job and do the work twice.
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog', '{"a":1}');

		$claimed = $repo->fetchNextJob();

		$this->assertSame(JobData::STATUS_IN_PROGRESS, $claimed->status);
		$this->assertFalse($repo->hasPendingJobs());
	}

	public function testClaimingCountsAnAttempt(): void
	{
		// The attempt counter is what eventually stops a poisoned job being
		// retried forever, so it has to move on the claim, not on the failure.
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');

		$this->assertSame(1, $repo->fetchNextJob()->attempts);
	}

	public function testJobsAreClaimedInTheOrderTheyWereQueued(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'first');
		$repo->queueJob(JobData::TYPE_IMPORT, 'second');

		$this->assertSame('first', $repo->fetchNextJob()->collection);
		$this->assertSame('second', $repo->fetchNextJob()->collection);
	}

	public function testAnEmptyQueueRefusesToHandOutAJob(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$repo->fetchNextJob();

		$this->expectException(\DomainException::class);
		$repo->fetchNextJob();
	}

	// ── Scheduling ───────────────────────────────────────────────────────────

	public function testAJobScheduledForLaterIsNotClaimedYet(): void
	{
		// This is what makes deferred work deferred. If the schedule were
		// ignored, a job queued for next week would run on the next cron tick.
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_EMAIL, 'blog', '{}', date('Y-m-d H:i:s', strtotime('+1 day')));

		$this->assertFalse($repo->hasPendingJobs());
	}

	public function testAJobWhoseScheduleHasPassedIsClaimed(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_EMAIL, 'blog', '{}', date('Y-m-d H:i:s', strtotime('-1 hour')));

		$this->assertTrue($repo->hasPendingJobs());
		$this->assertSame(JobData::TYPE_EMAIL, $repo->fetchNextJob()->type);
	}

	public function testAScheduledJobDoesNotBlockAnUnscheduledOneBehindIt(): void
	{
		// fetchNextJob orders by id, so a future-dated job queued first must be
		// skipped rather than stalling everything queued after it.
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_EMAIL, 'later', '{}', date('Y-m-d H:i:s', strtotime('+1 day')));
		$repo->queueJob(JobData::TYPE_IMPORT, 'now');

		$this->assertSame('now', $repo->fetchNextJob()->collection);
	}

	// ── Deduplication ────────────────────────────────────────────────────────

	public function testRecognisesAnAlreadyQueuedJob(): void
	{
		// Callers use this to avoid queueing a second rebuild of a collection
		// that already has one waiting.
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_REBUILD, 'blog');

		$this->assertTrue($repo->hasPendingJob(JobData::TYPE_REBUILD, 'blog'));
		$this->assertFalse($repo->hasPendingJob(JobData::TYPE_REBUILD, 'gallery'));
		$this->assertFalse($repo->hasPendingJob(JobData::TYPE_IMPORT, 'blog'));
	}

	public function testDeduplicationCanBeNarrowedByPayload(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog', '{"id":"a"}');

		$this->assertTrue($repo->hasPendingJob(JobData::TYPE_IMPORT, 'blog', '{"id":"a"}'));
		$this->assertFalse($repo->hasPendingJob(JobData::TYPE_IMPORT, 'blog', '{"id":"b"}'));
	}

	public function testAClaimedJobNoLongerCountsAsQueued(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_REBUILD, 'blog');
		$repo->fetchNextJob();

		// Otherwise a rebuild that is currently running would suppress the
		// queueing of the one needed for the change that just landed.
		$this->assertFalse($repo->hasPendingJob(JobData::TYPE_REBUILD, 'blog'));
	}

	public function testDeletesOnlyTheMatchingPendingJobs(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_REBUILD, 'blog');
		$repo->queueJob(JobData::TYPE_REBUILD, 'gallery');

		$repo->deletePendingJob(JobData::TYPE_REBUILD, 'blog');

		$this->assertFalse($repo->hasPendingJob(JobData::TYPE_REBUILD, 'blog'));
		$this->assertTrue($repo->hasPendingJob(JobData::TYPE_REBUILD, 'gallery'));
	}

	// ── Failure and retry ────────────────────────────────────────────────────

	public function testAFailedJobKeepsItsErrorForTheOperator(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$job = $repo->fetchNextJob();

		$repo->markFailed($job, 'schema validation failed');

		$failed = $repo->fetchFailedJobs();
		$this->assertCount(1, $failed);
		$this->assertSame('schema validation failed', $failed[0]->lastError);
	}

	public function testRetryingPutsAFailedJobBackInThePendingPool(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$repo->markFailed($repo->fetchNextJob(), 'transient');

		$this->assertFalse($repo->hasPendingJobs());

		$repo->resetJobStatus($repo->fetchFailedJobs()[0]);

		$this->assertTrue($repo->hasPendingJobs());
	}

	public function testAFinishedJobIsRemovedEntirely(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$job = $repo->fetchNextJob();

		$this->assertTrue($repo->delete($job));

		$this->expectException(\DomainException::class);
		$repo->fetchJobById((int)$job->id);
	}

	// ── Recovering from a crashed worker ─────────────────────────────────────

	public function testReturnsJobsStrandedInProgressByACrashedWorker(): void
	{
		// A killed worker leaves its job in_progress with nothing to finish it.
		// Without this the queue quietly stops making progress.
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$repo->queueJob(JobData::TYPE_IMPORT, 'gallery');
		$repo->fetchNextJob();
		$repo->fetchNextJob();

		$this->assertCount(2, $repo->fetchInProgressJobs());
		$this->assertSame(2, $repo->resetInProgressJobs());
		$this->assertTrue($repo->hasPendingJobs());
		$this->assertSame([], $repo->fetchInProgressJobs());
	}

	public function testResettingReportsZeroWhenNothingIsStranded(): void
	{
		$this->assertSame(0, $this->repository()->resetInProgressJobs());
	}

	// ── Listing and counting ─────────────────────────────────────────────────

	public function testPendingListingRespectsALimit(): void
	{
		$repo = $this->repository();
		foreach (['a', 'b', 'c'] as $collection) {
			$repo->queueJob(JobData::TYPE_IMPORT, $collection);
		}

		$this->assertCount(3, $repo->fetchPendingJobs());
		$this->assertCount(2, $repo->fetchPendingJobs(2));
	}

	public function testCountsTheQueueByTypeAndByStatus(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$repo->queueJob(JobData::TYPE_IMPORT, 'gallery');
		$repo->queueJob(JobData::TYPE_REBUILD, 'blog');
		$repo->markFailed($repo->fetchNextJob(), 'nope');

		// Both return display-cased keys ('View Update', 'In Progress'), not the
		// JobData constants — these feed the admin queue panel directly. A
		// caller indexing by the constants silently reads zero.
		$byType = $repo->queueByType();
		$this->assertSame(2, $byType['Import']);
		$this->assertSame(1, $byType['Rebuild']);
		$this->assertSame(0, $byType['Email']);

		$byStatus = $repo->queueByStatus();
		$this->assertSame(1, $byStatus['Failed']);
		$this->assertSame(2, $byStatus['Pending']);
	}

	public function testCountsCanBeNarrowedToOneCollection(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$repo->queueJob(JobData::TYPE_IMPORT, 'gallery');

		$this->assertSame(1, $repo->queueByTypeForCollection('blog')['Import']);
		$this->assertSame(1, $repo->queueByStatusForCollection('blog')['Pending']);
	}

	// ── Clearing ─────────────────────────────────────────────────────────────

	public function testClearingOneCollectionLeavesTheRestOfTheQueueAlone(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$repo->queueJob(JobData::TYPE_IMPORT, 'gallery');

		$repo->clearQueueForCollection('blog');

		$remaining = $repo->fetchPendingJobs();
		$this->assertCount(1, $remaining);
		$this->assertSame('gallery', $remaining[0]->collection);
	}

	public function testClearingTheQueueEmptiesIt(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$repo->queueJob(JobData::TYPE_REBUILD, 'gallery');

		$this->assertTrue($repo->clearQueue());
		$this->assertFalse($repo->hasPendingJobs());
	}

	// ── Diagnostics ──────────────────────────────────────────────────────────

	public function testReportsWhereTheDatabaseLivesBeforeItExists(): void
	{
		// The support path for "jobs are not running": the answer is usually
		// that the datadir is not where the operator thinks it is.
		$info = $this->repository()->getDatabaseInfo();

		$this->assertFalse($info['exists']);
		$this->assertSame($this->datadir, $info['datadir']);
		$this->assertStringEndsWith('jobqueue', $info['path']);
	}

	public function testReportsTheDatabaseOnceJobsHaveBeenQueued(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');

		$this->assertTrue($repo->getDatabaseInfo()['exists']);

		$raw = $repo->getRawJobCount();
		$this->assertSame(1, $raw['total']);
		$this->assertSame(1, $raw['pendingJobs']);
		$this->assertSame([JobData::STATUS_PENDING => 1], $raw['allStatuses']);
	}
}
