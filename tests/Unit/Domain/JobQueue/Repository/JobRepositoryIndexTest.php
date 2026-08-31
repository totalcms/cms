<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JobQueue\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Support\Config;

/**
 * The jobqueue table shipped with no indexes at all — `id` was the only key. That
 * makes fetchNextJob() a full table scan, and it runs once per job processed, so
 * draining N jobs is quadratic in table size. Invisible on a handful of jobs;
 * dominant on an import backlog, and worst under the HTTP cron endpoint where a
 * short request window means per-job overhead decides how many jobs fit.
 */
final class JobRepositoryIndexTest extends TestCase
{
	private string $datadir;

	protected function setUp(): void
	{
		$this->datadir = \tcmsTestTempDir('tcms-jq-idx');
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

	private function db(): \PDO
	{
		return new \PDO('sqlite:' . $this->datadir . '/.system/jobqueue');
	}

	/** @return list<string> */
	private function indexNames(): array
	{
		$stmt = $this->db()->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'jobqueue' AND name NOT LIKE 'sqlite_%'");

		return $stmt === false ? [] : array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
	}

	public function testTheHotPathQueryUsesAnIndex(): void
	{
		// fetchNextJob() runs once per job processed, so a scan here makes a whole
		// drain quadratic. This is the query that actually matters.
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');

		$stmt = $this->db()->query("EXPLAIN QUERY PLAN SELECT * FROM jobqueue WHERE status = 'pending' "
			. 'AND (scheduledAt IS NULL OR scheduledAt <= CURRENT_TIMESTAMP) ORDER BY id LIMIT 1');
		$plan = $stmt === false ? '' : implode(' ', array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN, 3)));

		$this->assertStringContainsString('USING INDEX', $plan, "query plan was: {$plan}");
	}

	public function testTheCollectionQueriesUseAnIndex(): void
	{
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog');

		$stmt = $this->db()->query("EXPLAIN QUERY PLAN SELECT * FROM jobqueue WHERE collection = 'blog'");
		$plan = $stmt === false ? '' : implode(' ', array_map(strval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN, 3)));

		$this->assertStringContainsString('USING INDEX', $plan, "query plan was: {$plan}");
	}

	public function testAnIndexIsAddedToAPreExistingDatabase(): void
	{
		// CREATE TABLE only runs when the database file is absent, so every
		// existing install reaches the index code by a path the fresh-install case
		// never exercises. This is exactly where a silent no-op would hide.
		$repo = $this->repository();
		$repo->queueJob(JobData::TYPE_IMPORT, 'blog'); // creates the database

		foreach ($this->indexNames() as $name) {
			$this->db()->exec("DROP INDEX {$name}");   // simulate a pre-index install
		}
		$this->assertSame([], $this->indexNames(), 'precondition: indexes removed');

		$fresh = $this->repository();                  // new connection, existing file
		$fresh->queueJob(JobData::TYPE_IMPORT, 'blog');

		$this->assertNotSame([], $this->indexNames(), 'an existing database must gain the indexes');
	}
}
