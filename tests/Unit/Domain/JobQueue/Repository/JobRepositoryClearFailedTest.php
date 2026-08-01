<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JobQueue\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Support\Config;

/**
 * Failed jobs are diagnostic output, and until now the only way to remove them
 * was clearQueue(), which also discards pending work. These cover the targeted
 * clear against a real SQLite database so the SQL itself is exercised.
 */
final class JobRepositoryClearFailedTest extends TestCase
{
	private string $datadir;

	protected function setUp(): void
	{
		$this->datadir = sys_get_temp_dir() . '/tcms-jobqueue-test-' . uniqid();
	}

	protected function tearDown(): void
	{
		$db = $this->datadir . '/.system/jobqueue';
		if (file_exists($db)) {
			unlink($db);
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
			'api'        => 'http://test.com/api',
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

	public function testRemovesFailedJobsAndReportsHowMany(): void
	{
		$repo = $this->repository();

		$repo->markFailed($repo->queueJob(JobData::TYPE_IMPORT, 'blog'), 'boom');
		$repo->markFailed($repo->queueJob(JobData::TYPE_IMPORT, 'blog'), 'boom');

		$this->assertSame(2, $repo->clearFailedJobs());
		$this->assertSame(0, $repo->queueByStatus()['Failed']);
	}

	public function testLeavesPendingJobsUntouched(): void
	{
		$repo = $this->repository();

		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');
		$repo->markFailed($repo->queueJob(JobData::TYPE_IMPORT, 'blog'), 'boom');

		$this->assertSame(1, $repo->clearFailedJobs());

		$status = $repo->queueByStatus();
		$this->assertSame(2, $status['Pending'], 'a half-drained import must survive clearing errors');
		$this->assertSame(2, $status['Total']);
	}

	public function testReturnsZeroWithoutCreatingADatabase(): void
	{
		$repo = $this->repository();

		$this->assertSame(0, $repo->clearFailedJobs());
		$this->assertFalse(
			file_exists($this->datadir . '/.system/jobqueue'),
			'clearing an unused queue must not create the database file'
		);
	}
}
