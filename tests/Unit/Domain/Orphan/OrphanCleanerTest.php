<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Orphan\Data\OrphanEntry;
use TotalCMS\Domain\Orphan\Data\OrphanReport;
use TotalCMS\Domain\Orphan\Service\OrphanCleaner;
use TotalCMS\Factory\LoggerFactory;

// OrphanCleaner rewrites live objects to drop relational references whose
// target no longer exists. It is reachable at POST /api/orphan/cleanup, so a
// mistake here silently destroys customer data — these tests pin down what it
// is allowed to touch, and what it must leave alone.

/**
 * Build a cleaner over mocked storage.
 *
 * $stored maps "collection/id" to the object's current data. Every write is
 * appended to $writes so a test can assert on exactly what was persisted.
 *
 * @param array<string,array<string,mixed>> $stored
 * @param array<int,array{0:string,1:string,2:mixed}> $writes
 */
function orphanCleanerFor(array $stored, array &$writes, bool $failWrites = false): OrphanCleaner
{
	$fetcher = test()->createMock(ObjectFetcher::class);
	$fetcher->method('fetchObject')->willReturnCallback(
		function (string $collection, string $id) use ($stored): ObjectData {
			$key = "$collection/$id";
			if (!isset($stored[$key])) {
				throw new RuntimeException("Object not found: $key");
			}

			$object = test()->createMock(ObjectData::class);
			$object->method('toArray')->willReturn($stored[$key]);

			return $object;
		}
	);

	$updater = test()->createMock(ObjectUpdater::class);
	$updater->method('updateObject')->willReturnCallback(
		function (string $collection, string $id, mixed $data) use (&$writes, $failWrites): ObjectData {
			if ($failWrites) {
				throw new RuntimeException('write failed');
			}
			$writes[] = [$collection, $id, $data];

			return new ObjectData($id, is_array($data) ? $data : []);
		}
	);

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

	return new OrphanCleaner($fetcher, $updater, $loggerFactory);
}

describe('OrphanCleaner::cleanProperty', function (): void {
	it('drops only the orphaned ids from an array property and keeps the valid ones', function (): void {
		// The property that matters most. Over-deleting here would quietly
		// destroy relations that were perfectly intact.
		$writes  = [];
		$cleaner = orphanCleanerFor([
			'blog/post-1' => [
				'id'      => 'post-1',
				'title'   => 'Post One',
				'authors' => ['alice', 'ghost', 'bob', 'vanished'],
			],
		], $writes);

		$result = $cleaner->cleanProperty('blog', 'post-1', 'authors', ['ghost', 'vanished'], true);

		expect($result->success)->toBeTrue();
		expect($writes)->toHaveCount(1);
		expect($writes[0][2]['authors'])->toBe(['alice', 'bob']);
		// Untouched fields must survive the rewrite.
		expect($writes[0][2]['title'])->toBe('Post One');
	});

	it('reindexes the array so the cleaned value has no gaps', function (): void {
		// array_filter preserves keys; a hole would serialise as a JSON object
		// instead of a list.
		$writes  = [];
		$cleaner = orphanCleanerFor([
			'blog/post-1' => ['id' => 'post-1', 'authors' => ['ghost', 'alice', 'gone', 'bob']],
		], $writes);

		$cleaner->cleanProperty('blog', 'post-1', 'authors', ['ghost', 'gone'], true);

		expect(array_keys($writes[0][2]['authors']))->toBe([0, 1]);
	});

	it('nulls a scalar property rather than emptying it to a string', function (): void {
		$writes  = [];
		$cleaner = orphanCleanerFor([
			'blog/post-1' => ['id' => 'post-1', 'author' => 'ghost', 'title' => 'Post One'],
		], $writes);

		$result = $cleaner->cleanProperty('blog', 'post-1', 'author', ['ghost'], false);

		expect($result->success)->toBeTrue();
		expect($writes[0][2]['author'])->toBeNull();
		expect($writes[0][2]['title'])->toBe('Post One');
	});

	it('clears a scalar property even when the value is not in the orphan list', function (): void {
		// Documents current behaviour: the scalar branch ignores $orphanedIds
		// entirely and always nulls the field. Worth knowing before anyone
		// calls cleanProperty() directly with a stale report.
		$writes  = [];
		$cleaner = orphanCleanerFor([
			'blog/post-1' => ['id' => 'post-1', 'author' => 'alice'],
		], $writes);

		$cleaner->cleanProperty('blog', 'post-1', 'author', ['someone-else'], false);

		expect($writes[0][2]['author'])->toBeNull();
	});

	it('returns a failure and writes nothing when the object cannot be read', function (): void {
		$writes  = [];
		$cleaner = orphanCleanerFor([], $writes);

		$result = $cleaner->cleanProperty('blog', 'missing', 'authors', ['ghost'], true);

		expect($result->success)->toBeFalse();
		// The reason lives in message(); error() is left null by
		// OperationResult::failure() when called with one argument.
		expect($result->message)->toContain('Object not found');
		expect($writes)->toBe([]);
	});
});

describe('OrphanCleaner report-level cleaning', function (): void {
	$report = function (): OrphanReport {
		$report = new OrphanReport();
		$report->addEntry(new OrphanEntry('blog', 'post-1', 'authors', ['ghost'], true, 'authors'));
		$report->addEntry(new OrphanEntry('blog', 'post-2', 'editor', ['gone'], false, 'authors'));
		$report->addEntry(new OrphanEntry('pages', 'page-1', 'authors', ['ghost'], true, 'authors'));

		return $report;
	};

	$stored = [
		'blog/post-1'  => ['id' => 'post-1', 'authors' => ['alice', 'ghost']],
		'blog/post-2'  => ['id' => 'post-2', 'editor' => 'gone'],
		'pages/page-1' => ['id' => 'page-1', 'authors' => ['bob', 'ghost']],
	];

	it('cleanAll writes every entry in the report', function () use ($report, $stored): void {
		$writes  = [];
		$cleaner = orphanCleanerFor($stored, $writes);

		$result = $cleaner->cleanAll($report());

		expect($result->success)->toBeTrue();
		expect($result->data['cleaned'])->toBe(3);
		expect($result->data['failed'])->toBe(0);
		expect($writes)->toHaveCount(3);
	});

	it('cleanByCollection touches only that collection', function () use ($report, $stored): void {
		$writes  = [];
		$cleaner = orphanCleanerFor($stored, $writes);

		$result = $cleaner->cleanByCollection($report(), 'pages');

		expect($result->data['cleaned'])->toBe(1);
		expect(array_column($writes, 0))->toBe(['pages']);
		expect($writes[0][1])->toBe('page-1');
	});

	it('cleanByCollectionProperty narrows to one property within a collection', function () use ($report, $stored): void {
		$writes  = [];
		$cleaner = orphanCleanerFor($stored, $writes);

		$result = $cleaner->cleanByCollectionProperty($report(), 'blog', 'authors');

		expect($result->data['cleaned'])->toBe(1);
		expect($writes)->toHaveCount(1);
		expect($writes[0][1])->toBe('post-1');
	});

	it('reports failures per entry instead of aborting the run', function () use ($report, $stored): void {
		$writes  = [];
		$cleaner = orphanCleanerFor($stored, $writes, failWrites: true);

		$result = $cleaner->cleanAll($report());

		// The batch still "succeeds" — the counts carry the outcome.
		expect($result->success)->toBeTrue();
		expect($result->data['cleaned'])->toBe(0);
		expect($result->data['failed'])->toBe(3);
		expect($result->data['errors'])->toHaveCount(3);
		expect($result->data['errors'][0])->toContain('blog/post-1.authors');
		// The per-entry error must carry the reason, not just the location.
		expect($result->data['errors'][0])->toContain('write failed');
	});

	it('cleaning an empty report is a no-op', function (): void {
		$writes  = [];
		$cleaner = orphanCleanerFor([], $writes);

		$result = $cleaner->cleanAll(new OrphanReport());

		expect($result->data['cleaned'])->toBe(0);
		expect($writes)->toBe([]);
	});
});
