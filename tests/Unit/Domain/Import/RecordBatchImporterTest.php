<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Import\RecordBatchImporter;
use TotalCMS\Domain\JobQueue\Data\JobData;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;

// The batch write pipeline every record importer shares: suspend per-object
// index rebuilds and events, create-or-update each record (directly or via
// the job queue), keep a skip list with reasons, and fire import.completed
// once. CsvImporter and JsonImporter each carried a byte-for-byte copy.
final class RecordBatchImporterTest extends TestCase
{
	private function importer(ObjectFetcher $fetcher, ObjectImporter $objects, JobQueuer $queuer, EventDispatcher $dispatcher): RecordBatchImporter
	{
		return new RecordBatchImporter($fetcher, $objects, $dispatcher, $queuer);
	}

	public function testCreatesNewRecordsSlugifyingTheirIdsAndFiresImportCompletedOnce(): void
	{
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('existsObject')->willReturn(false);
		$objects = $this->createMock(ObjectImporter::class);
		$objects->expects($this->exactly(2))->method('importObject')
			->with('blog', $this->callback(fn (array $r): bool => in_array($r['id'], ['hello-world', 'second'], true)));

		$fired      = 0;
		$dispatcher = new EventDispatcher(new NullLogger());
		$dispatcher->listen(CoreEvent::IMPORT_COMPLETED, function (array $payload) use (&$fired): void {
			$fired++;
			$this->assertSame(['hello-world', 'second'], $payload['created']);
		});

		$result = $this->importer($fetcher, $objects, $this->createMock(JobQueuer::class), $dispatcher)
			->import('blog', [['id' => 'Hello World!', 'title' => 'a'], ['id' => 'second', 'title' => 'b']], false, false, new NullLogger());

		$this->assertSame(2, $result->imported);
		$this->assertSame([], $result->skipped);
		$this->assertSame(1, $fired);
	}

	public function testSkipsAnExistingRecordOnCreateAndAMissingOneOnUpdateWithReasons(): void
	{
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('existsObject')->willReturnCallback(fn (string $c, string $id): bool => $id === 'taken');
		$objects = $this->createMock(ObjectImporter::class);
		$objects->expects($this->never())->method('importObject');
		$objects->expects($this->never())->method('updateObject');
		$batch = $this->importer($fetcher, $objects, $this->createMock(JobQueuer::class), new EventDispatcher(new NullLogger()));

		$create = $batch->import('blog', [['id' => 'taken']], false, false, new NullLogger());
		$this->assertSame(0, $create->imported);
		$this->assertSame('Object with id taken already exists in blog', $create->skipped[0]['reason']);

		$update = $batch->import('blog', [['id' => 'ghost'], ['title' => 'no id']], true, false, new NullLogger());
		$this->assertSame(0, $update->imported);
		$this->assertSame('No existing object with id ghost to update', $update->skipped[0]['reason']);
		$this->assertStringContainsString('no id', $update->skipped[1]['reason']);
	}

	public function testAFailingRecordIsSkippedAndTheRestStillImport(): void
	{
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('existsObject')->willReturn(false);
		$objects = $this->createMock(ObjectImporter::class);
		$objects->method('importObject')->willReturnCallback(function (string $c, array $r): ObjectData {
			if ($r['id'] === 'bad') {
				throw new \RuntimeException('boom');
			}

			return new ObjectData($r['id'], []);
		});

		$result = $this->importer($fetcher, $objects, $this->createMock(JobQueuer::class), new EventDispatcher(new NullLogger()))
			->import('blog', [['id' => 'bad'], ['id' => 'good']], false, false, new NullLogger());

		$this->assertSame(1, $result->imported);
		$this->assertSame([['offset' => 0, 'id' => 'bad', 'reason' => 'boom']], $result->skipped);
	}

	public function testQueuedModeHandsRecordsToTheJobQueueInstead(): void
	{
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('existsObject')->willReturnCallback(fn (string $c, string $id): bool => $id === 'there');
		$objects = $this->createMock(ObjectImporter::class);
		$objects->expects($this->never())->method('importObject');
		$objects->expects($this->never())->method('updateObject');
		$queuer = $this->createMock(JobQueuer::class);
		$queuer->expects($this->once())->method('queueImport')->with('blog', ['id' => 'new'])->willReturn(new JobData());
		$queuer->expects($this->once())->method('queueUpdate')->with('blog', ['id' => 'there'])->willReturn(new JobData());
		$batch = $this->importer($fetcher, $objects, $queuer, new EventDispatcher(new NullLogger()));

		$this->assertSame(1, $batch->import('blog', [['id' => 'new']], false, true, new NullLogger())->imported);
		$this->assertSame(1, $batch->import('blog', [['id' => 'there']], true, true, new NullLogger())->imported);
	}
}
