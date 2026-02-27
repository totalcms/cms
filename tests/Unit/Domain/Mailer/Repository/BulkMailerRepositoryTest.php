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

		$config          = $this->createMock(Config::class);
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
		$this->repository->log([
			'batchId'    => 'batch-1',
			'mailerId'   => 'mailer-1',
			'collection' => 'subscribers',
			'objectId'   => 'obj-1',
			'sentTo'     => 'user@example.com',
			'status'     => 'sent',
		]);

		$this->assertTrue($this->repository->hasBeenSent('mailer-1', 'obj-1'));
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
}
