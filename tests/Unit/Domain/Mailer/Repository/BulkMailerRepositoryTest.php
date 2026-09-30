<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mailer\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mailer\Data\BulkMailLogData;
use TotalCMS\Domain\Mailer\Repository\BulkMailerRepository;
use TotalCMS\Support\Config;

final class BulkMailerRepositoryTest extends TestCase
{
	private string $tmpDir;
	private BulkMailerRepository $repository;

	protected function setUp(): void
	{
		$this->tmpDir = sys_get_temp_dir() . '/tcms-test-' . uniqid('', true);
		mkdir($this->tmpDir, 0755, true);

		// Real (unconstructed) Config, not a mock: the repository calls
		// $config->systemDir(), and a mock stubs that method to null. Setting the
		// public datadir lets systemDir() compute <datadir>/.system correctly.
		$config          = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->datadir = $this->tmpDir;

		$this->repository = new BulkMailerRepository($config);
	}

	protected function tearDown(): void
	{
		// Clean up temp files
		$dbPath = $this->tmpDir . '/.system/bulkmailer';
		if (file_exists($dbPath)) {
			unlink($dbPath);
		}

		$systemDir = $this->tmpDir . '/.system';
		if (is_dir($systemDir)) {
			rmdir($systemDir);
		}

		if (is_dir($this->tmpDir)) {
			rmdir($this->tmpDir);
		}
	}

	public function testLogsAndRetrievesSendResult(): void
	{
		$this->repository->log([
			'batchId'    => 'batch-1',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-1',
			'sentTo'     => 'user@example.com',
			'status'     => 'sent',
		]);

		$logs = $this->repository->fetchBatchLog('batch-1');

		$this->assertCount(1, $logs);
		$this->assertInstanceOf(BulkMailLogData::class, $logs[0]);
		$this->assertSame('batch-1', $logs[0]->batchId);
		$this->assertSame('mailer-1', $logs[0]->mailerId);
		$this->assertSame('obj-1', $logs[0]->objectId);
		$this->assertSame('user@example.com', $logs[0]->sentTo);
		$this->assertSame('sent', $logs[0]->status);
	}

	public function testHasBeenSentReturnsFalseWhenNoRecords(): void
	{
		$this->assertFalse($this->repository->hasBeenSent('mailer-1', 'obj-1'));
	}

	public function testHasBeenSentReturnsTrueAfterLogging(): void
	{
		// Real deliveries are logged with an empty sentTo (JobRunner only
		// records the address when it is an override)
		$this->repository->log([
			'batchId'    => 'batch-1',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-1',
			'sentTo'     => '',
			'status'     => 'sent',
		]);

		$this->assertTrue($this->repository->hasBeenSent('mailer-1', 'obj-1'));
	}

	public function testHasBeenSentIgnoresOverrideTestSends(): void
	{
		$this->repository->log([
			'batchId'    => 'batch-test',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-1',
			'sentTo'     => 'proofer@example.com',
			'status'     => 'sent',
		]);

		$this->assertFalse($this->repository->hasBeenSent('mailer-1', 'obj-1'));
	}

	public function testHasBeenSentReturnsFalseForFailedRecords(): void
	{
		$this->repository->log([
			'batchId'    => 'batch-1',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-1',
			'sentTo'     => 'user@example.com',
			'status'     => 'failed',
		]);

		$this->assertFalse($this->repository->hasBeenSent('mailer-1', 'obj-1'));
	}

	public function testFetchBatchStatsReturnsCorrectCounts(): void
	{
		$this->repository->log([
			'batchId'    => 'batch-1',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-1',
			'status'     => 'sent',
		]);
		$this->repository->log([
			'batchId'    => 'batch-1',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-2',
			'status'     => 'sent',
		]);
		$this->repository->log([
			'batchId'    => 'batch-1',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-3',
			'status'     => 'failed',
			'error'      => 'SMTP timeout',
		]);
		$this->repository->log([
			'batchId'    => 'batch-1',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-4',
			'status'     => 'skipped',
		]);

		$stats = $this->repository->fetchBatchStats('batch-1');

		$this->assertSame(4, $stats['total']);
		$this->assertSame(2, $stats['sent']);
		$this->assertSame(1, $stats['failed']);
		$this->assertSame(1, $stats['skipped']);
	}

	public function testFetchBatchStatsReturnsZerosForUnknownBatch(): void
	{
		// Force DB creation by logging something
		$this->repository->log([
			'batchId'    => 'other-batch',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-1',
			'status'     => 'sent',
		]);

		$stats = $this->repository->fetchBatchStats('nonexistent');

		$this->assertSame(0, $stats['total']);
		$this->assertSame(0, $stats['sent']);
		$this->assertSame(0, $stats['failed']);
		$this->assertSame(0, $stats['skipped']);
	}

	public function testFetchBatchLogReturnsEntriesInOrder(): void
	{
		$this->repository->log([
			'batchId'    => 'batch-1',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-1',
			'status'     => 'sent',
		]);
		$this->repository->log([
			'batchId'    => 'batch-1',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-2',
			'status'     => 'failed',
		]);

		$logs = $this->repository->fetchBatchLog('batch-1');

		$this->assertCount(2, $logs);
		$this->assertSame('obj-1', $logs[0]->objectId);
		$this->assertSame('obj-2', $logs[1]->objectId);
		$this->assertTrue($logs[0]->id < $logs[1]->id);
	}

	public function testFetchMailerStatsReturnsAggregateStats(): void
	{
		$this->repository->log([
			'batchId'    => 'batch-1',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-1',
			'status'     => 'sent',
		]);
		$this->repository->log([
			'batchId'    => 'batch-2',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-2',
			'status'     => 'sent',
		]);
		$this->repository->log([
			'batchId'    => 'batch-2',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-3',
			'status'     => 'failed',
		]);

		$stats = $this->repository->fetchMailerStats('mailer-1');

		$this->assertSame(3, $stats['total']);
		$this->assertSame(2, $stats['sent']);
		$this->assertSame(1, $stats['failed']);
		$this->assertSame(0, $stats['skipped']);
	}

	/** @param array<string,string> $row */
	private function logRow(array $row): void
	{
		$this->repository->log($row + ['mailerId' => 'mailer-1', 'collection' => 'subscribers', 'sentTo' => '']);
	}

	public function testFetchDeliveredObjectIdsCountsOnlyRealDeliveries(): void
	{
		$this->logRow(['batchId' => 'b1', 'objectId' => 'obj-1', 'status' => 'sent']);
		$this->logRow(['batchId' => 'b1', 'objectId' => 'obj-2', 'status' => 'failed']);
		$this->logRow(['batchId' => 'b2', 'objectId' => 'obj-3', 'status' => 'sent', 'sentTo' => 'me@example.com']);
		$this->logRow(['batchId' => 'b3', 'objectId' => 'obj-4', 'status' => 'sent', 'mailerId' => 'mailer-2']);

		$this->assertSame(['obj-1' => true], $this->repository->fetchDeliveredObjectIds('mailer-1'));
	}

	public function testFetchMailerBatchesReportsCountsAndPending(): void
	{
		$this->repository->recordBatch('b1', 'mailer-1', 'subscribers', 4, 1, null, null);
		$this->logRow(['batchId' => 'b1', 'objectId' => 'obj-1', 'status' => 'sent']);
		// Failed, then succeeded on retry: counts once, as sent
		$this->logRow(['batchId' => 'b1', 'objectId' => 'obj-2', 'status' => 'failed']);
		$this->logRow(['batchId' => 'b1', 'objectId' => 'obj-2', 'status' => 'sent']);
		$this->logRow(['batchId' => 'b1', 'objectId' => 'obj-3', 'status' => 'skipped']);

		$batches = $this->repository->fetchMailerBatches('mailer-1');

		$this->assertCount(1, $batches);
		$this->assertSame('b1', $batches[0]->batchId);
		$this->assertSame(4, $batches[0]->queued);
		$this->assertSame(1, $batches[0]->excluded);
		$this->assertSame(2, $batches[0]->sent);
		$this->assertSame(0, $batches[0]->failed);
		$this->assertSame(1, $batches[0]->skipped);
		$this->assertSame(1, $batches[0]->pending());
		$this->assertFalse($batches[0]->isTest());
	}

	public function testFetchMailerBatchesMarksOverrideBatchesAsTests(): void
	{
		$this->repository->recordBatch('b1', 'mailer-1', 'subscribers', 2, 0, 'me@example.com', null);

		$batches = $this->repository->fetchMailerBatches('mailer-1');

		$this->assertTrue($batches[0]->isTest());
		$this->assertSame(2, $batches[0]->pending());
	}

	public function testFetchMailerBatchesListsLegacyBatchesFromTheLogAlone(): void
	{
		// A batch sent before bulk_batches existed has log rows only
		$this->logRow(['batchId' => 'old', 'objectId' => 'obj-1', 'status' => 'sent', 'sentTo' => 'me@example.com']);

		$batches = $this->repository->fetchMailerBatches('mailer-1');

		$this->assertCount(1, $batches);
		$this->assertNull($batches[0]->queued);
		$this->assertNull($batches[0]->pending());
		$this->assertSame(1, $batches[0]->sent);
		$this->assertTrue($batches[0]->isTest());
	}

	public function testAddsBatchTableToAPre362Database(): void
	{
		mkdir($this->tmpDir . '/.system', 0755, true);
		$pdo = new \PDO('sqlite:' . $this->tmpDir . '/.system/bulkmailer');
		$pdo->exec("CREATE TABLE bulk_send_log (id INTEGER PRIMARY KEY AUTOINCREMENT, batchId TEXT NOT NULL, mailerId TEXT NOT NULL, collection TEXT NOT NULL, objectId TEXT NOT NULL, sentTo TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'sent', error TEXT DEFAULT NULL, scheduledAt DATETIME DEFAULT NULL, sentAt DATETIME DEFAULT CURRENT_TIMESTAMP)");
		unset($pdo);

		$this->repository->recordBatch('b1', 'mailer-1', 'subscribers', 1, 0, null, null);

		$this->assertCount(1, $this->repository->fetchMailerBatches('mailer-1'));
	}

	public function testFetchMailerBatchesIsEmptyWithoutADatabase(): void
	{
		$this->assertSame([], $this->repository->fetchMailerBatches('mailer-1'));
	}
}
