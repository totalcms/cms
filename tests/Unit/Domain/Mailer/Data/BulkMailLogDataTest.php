<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mailer\Data;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mailer\Data\BulkMailLogData;

final class BulkMailLogDataTest extends TestCase
{
	public function testCreatesFromArrayWithAllFields(): void
	{
		$data = [
			'id'          => 42,
			'batchId'     => 'bulk_abc123',
			'mailerId'    => 'welcome-email',
			'collection'  => 'subscribers',
			'objectId'    => 'obj-1',
			'sentTo'      => 'user@example.com',
			'status'      => 'sent',
			'error'       => '',
			'scheduledAt' => '2026-03-01 12:00:00',
			'sentAt'      => '2026-03-01 12:00:05',
		];

		$log = BulkMailLogData::fromArray($data);

		$this->assertSame(42, $log->id);
		$this->assertSame('bulk_abc123', $log->batchId);
		$this->assertSame('welcome-email', $log->mailerId);
		$this->assertSame('subscribers', $log->collection);
		$this->assertSame('obj-1', $log->objectId);
		$this->assertSame('user@example.com', $log->sentTo);
		$this->assertSame('sent', $log->status);
		$this->assertSame('', $log->error);
		$this->assertSame('2026-03-01 12:00:00', $log->scheduledAt);
		$this->assertSame('2026-03-01 12:00:05', $log->sentAt);
	}

	public function testCreatesFromArrayWithDefaults(): void
	{
		$log = BulkMailLogData::fromArray([]);

		$this->assertSame(0, $log->id);
		$this->assertSame('', $log->batchId);
		$this->assertSame('', $log->mailerId);
		$this->assertSame('', $log->collection);
		$this->assertSame('', $log->objectId);
		$this->assertSame('', $log->sentTo);
		$this->assertSame('sent', $log->status);
		$this->assertSame('', $log->error);
		$this->assertSame('', $log->scheduledAt);
		$this->assertSame('', $log->sentAt);
	}

	public function testConvertsToArray(): void
	{
		$log = new BulkMailLogData(
			id: 1,
			batchId: 'batch-1',
			mailerId: 'mailer-1',
			collection: 'contacts',
			objectId: 'contact-1',
			sentTo: 'test@example.com',
			status: 'failed',
			error: 'SMTP timeout',
			scheduledAt: '2026-03-01 10:00:00',
			sentAt: '2026-03-01 10:00:03',
		);

		$array = $log->toArray();

		$this->assertSame(1, $array['id']);
		$this->assertSame('batch-1', $array['batchId']);
		$this->assertSame('mailer-1', $array['mailerId']);
		$this->assertSame('contacts', $array['collection']);
		$this->assertSame('contact-1', $array['objectId']);
		$this->assertSame('test@example.com', $array['sentTo']);
		$this->assertSame('failed', $array['status']);
		$this->assertSame('SMTP timeout', $array['error']);
		$this->assertSame('2026-03-01 10:00:00', $array['scheduledAt']);
		$this->assertSame('2026-03-01 10:00:03', $array['sentAt']);
	}

	public function testRoundtripPreservesData(): void
	{
		$original = [
			'id'          => 7,
			'batchId'     => 'bulk_xyz',
			'mailerId'    => 'newsletter',
			'collection'  => 'users',
			'objectId'    => 'user-99',
			'sentTo'      => 'admin@example.com',
			'status'      => 'skipped',
			'error'       => 'duplicate',
			'scheduledAt' => '2026-02-28 08:00:00',
			'sentAt'      => '2026-02-28 08:00:01',
		];

		$roundtrip = BulkMailLogData::fromArray($original)->toArray();

		$this->assertSame($original, $roundtrip);
	}
}
