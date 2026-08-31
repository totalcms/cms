<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JobQueue\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Support\Config;

/**
 * VACUUM rewrites the entire database file. Running it on every pass was
 * tolerable at one CLI run per five minutes; a URL cron firing every minute
 * makes it ~1440 rewrites a day to reclaim space SQLite is already reusing from
 * its free list. Pruning is a single DELETE and stays on every pass.
 */
final class JobRepositoryMaintenanceTest extends TestCase
{
	private string $datadir;

	protected function setUp(): void
	{
		$this->datadir = \tcmsTestTempDir('tcms-jq-maint');
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

	public function testTheFirstMaintenanceRunVacuums(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog'); // force the database into existence

		$this->assertTrue($repo->maintenance(15)['vacuumed']);
	}

	public function testASecondRunSkipsTheVacuum(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');

		$repo->maintenance(15);

		$this->assertFalse($repo->maintenance(15)['vacuumed']);
	}

	public function testPruningStillRunsOnEveryPass(): void
	{
		$repo = $this->repository();
		$repo->markFailed($repo->queueJob(JobData::TYPE_IMPORT, 'blog'), 'boom');

		$repo->maintenance(15); // first pass: vacuums, prunes nothing (job is fresh)

		// Age the job past retention rather than waiting. SQLite's CURRENT_TIMESTAMP
		// has one-second resolution, so a just-failed job is not `< now` and cannot
		// be aged out by shrinking the retention window alone.
		$this->backdateFailedJobs(20);

		$second = $repo->maintenance(15);

		$this->assertFalse($second['vacuumed'], 'the vacuum is throttled');
		$this->assertSame(1, $second['pruned'], 'but pruning is not');
	}

	private function backdateFailedJobs(int $days): void
	{
		$db = new \PDO('sqlite:' . $this->datadir . '/.system/jobqueue');
		$db->exec("UPDATE jobqueue SET updatedAt = datetime('now', '-{$days} days') WHERE status = 'failed'");
	}

	public function testAnExpiredMarkerAllowsAnotherVacuum(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$repo->maintenance(15);

		// Age the marker past the interval rather than waiting a day for it.
		touch($this->datadir . '/.system/.jobqueue-vacuum', time() - 86_401);

		$this->assertTrue($repo->maintenance(15)['vacuumed']);
	}

	public function testMaintenanceOnAnAbsentDatabaseDoesNotCreateOne(): void
	{
		$result = $this->repository()->maintenance(15);

		$this->assertFalse($result['vacuumed']);
		$this->assertSame(0, $result['pruned']);
		$this->assertFileDoesNotExist($this->datadir . '/.system/jobqueue');
	}
}
