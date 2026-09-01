<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Index\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;

final class IndexBuilderTest extends TestCase
{
	private IndexBuilder $builder;
	private \PHPUnit\Framework\MockObject\MockObject $storage;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionSaver;
	private \PHPUnit\Framework\MockObject\MockObject $jobQueuer;

	protected function setUp(): void
	{
		$this->storage           = $this->createMock(IndexRepository::class);
		$this->objectFetcher     = $this->createMock(ObjectFetcher::class);
		$this->schemaFetcher     = $this->createMock(SchemaFetcher::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->collectionSaver   = $this->createMock(CollectionSaver::class);
		$this->jobQueuer         = $this->createMock(JobQueuer::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$this->builder = new IndexBuilder(
			$this->storage,
			$this->objectFetcher,
			$this->schemaFetcher,
			$this->collectionFetcher,
			$this->collectionSaver,
			$this->jobQueuer,
			$loggerFactory,
		);
	}

	private function createSchemaWithIndex(array $indexProps): SchemaData
	{
		$schema        = new SchemaData();
		$schema->index = $indexProps;

		return $schema;
	}

	public function testIdOnlyIndexSkipsObjectFetching(): void
	{
		$objectIds = ['post-1', 'post-2', 'post-3'];

		$this->storage
			->method('fetchObjectIdsFromDisk')
			->with('blog')
			->willReturn($objectIds);

		$this->schemaFetcher
			->method('fetchSchemaForCollection')
			->with('blog')
			->willReturn($this->createSchemaWithIndex(['id']));

		// The key assertion: objectFetcher should NEVER be called
		$this->objectFetcher
			->expects($this->never())
			->method('fetchObjectFromDisk');

		$this->storage
			->expects($this->once())
			->method('saveIndex')
			->with('blog', $this->callback(function (IndexData $index): true {
				$objects = $index->objects->toArray();
				expect($objects)->toHaveCount(3);
				expect($objects[0])->toBe(['id' => 'post-1']);
				expect($objects[1])->toBe(['id' => 'post-2']);
				expect($objects[2])->toBe(['id' => 'post-3']);

				return true;
			}));

		$result = $this->builder->buildIndex('blog');

		expect($result->objects)->toHaveCount(3);
	}

	public function testEmptyIndexPropsSkipsObjectFetching(): void
	{
		$objectIds = ['item-1', 'item-2'];

		$this->storage
			->method('fetchObjectIdsFromDisk')
			->with('text')
			->willReturn($objectIds);

		$this->schemaFetcher
			->method('fetchSchemaForCollection')
			->with('text')
			->willReturn($this->createSchemaWithIndex([]));

		// Empty index props should also use the id-only fast path
		$this->objectFetcher
			->expects($this->never())
			->method('fetchObjectFromDisk');

		$this->storage
			->expects($this->once())
			->method('saveIndex');

		$result = $this->builder->buildIndex('text');

		expect($result->objects)->toHaveCount(2);
		expect($result->objects[0])->toBe(['id' => 'item-1']);
		expect($result->objects[1])->toBe(['id' => 'item-2']);
	}

	public function testIdOnlyIndexWithNoObjects(): void
	{
		$this->storage
			->method('fetchObjectIdsFromDisk')
			->with('empty')
			->willReturn([]);

		$this->schemaFetcher
			->method('fetchSchemaForCollection')
			->with('empty')
			->willReturn($this->createSchemaWithIndex(['id']));

		$this->objectFetcher
			->expects($this->never())
			->method('fetchObjectFromDisk');

		$this->storage
			->expects($this->once())
			->method('saveIndex');

		$result = $this->builder->buildIndex('empty');

		expect($result->objects)->toHaveCount(0);
	}

	public function testMultiPropIndexStillFetchesObjects(): void
	{
		$objectIds = ['post-1'];

		$this->storage
			->method('fetchObjectIdsFromDisk')
			->with('blog')
			->willReturn($objectIds);

		$this->schemaFetcher
			->method('fetchSchemaForCollection')
			->with('blog')
			->willReturn($this->createSchemaWithIndex(['id', 'title', 'date']));

		// With multiple index props, objectFetcher SHOULD be called
		$this->objectFetcher
			->expects($this->once())
			->method('fetchObjectFromDisk')
			->with('blog', 'post-1');

		$this->builder->buildIndex('blog');
	}

	/**
	 * @param array<string,mixed> $properties
	 */
	private function objectWith(array $properties): \TotalCMS\Domain\Object\Data\ObjectData
	{
		$object             = $this->createMock(\TotalCMS\Domain\Object\Data\ObjectData::class);
		$object->properties = collect(array_map(
			static fn (mixed $value): object => new class($value) {
				public function __construct(private mixed $value)
				{
				}

				public function transform(): mixed
				{
					return $this->value;
				}
			},
			$properties,
		));

		return $object;
	}

	/** @return array<int,string> */
	private function ids(int $count, string $prefix = 'post-'): array
	{
		return array_map(static fn (int $n): string => $prefix . $n, range(1, $count));
	}

	public function testALargeCollectionUsesTheStreamingPath(): void
	{
		// Above 500 objects a completely different code path builds the index.
		// Nothing asserted which one ran, or that they agree.
		$ids = $this->ids(501);

		$this->storage->method('fetchObjectIdsFromDisk')->willReturn($ids);
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->createSchemaWithIndex(['id', 'title']));
		$this->objectFetcher->method('fetchObjectFromDisk')
			->willReturn($this->objectWith(['title' => 'A post', 'body' => 'ignored']));

		$handle = fopen('php://memory', 'r+');
		$this->storage->method('openIndexStream')->willReturn($handle);
		// Streamed straight to the file rather than assembled in memory, which
		// is the whole point of the path.
		$this->storage->expects($this->exactly(501))->method('writeIndexEntry');
		$this->storage->expects($this->once())->method('closeIndexStream');
		$this->storage->expects($this->never())->method('saveIndex');

		$this->builder->buildIndex('blog');

		fclose($handle);
	}

	public function testStreamingIndexesTheSamePropertiesAsTheStandardPath(): void
	{
		// The two paths must agree on what an index entry contains. They are
		// separate implementations of the same extraction, so a change to one
		// silently makes large collections index differently from small ones.
		$schema = $this->createSchemaWithIndex(['id', 'title']);
		$object = $this->objectWith(['title' => 'A post', 'body' => 'not indexed']);

		// Standard path: one object.
		$this->storage->method('fetchObjectIdsFromDisk')->willReturn(['post-1']);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);
		$this->objectFetcher->method('fetchObjectFromDisk')->willReturn($object);

		$standardEntry = null;
		$this->storage->method('saveIndex')
			->willReturnCallback(function (string $collection, IndexData $index) use (&$standardEntry): void {
				$standardEntry = $index->objects->toArray()[0] ?? null;
			});

		$this->builder->buildIndex('blog');

		// Streaming path: same object, same schema, over the threshold.
		$this->setUp();
		$streamedEntry = null;
		$this->storage->method('fetchObjectIdsFromDisk')->willReturn($this->ids(501));
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);
		$this->objectFetcher->method('fetchObjectFromDisk')->willReturn($object);
		$handle = fopen('php://memory', 'r+');
		$this->storage->method('openIndexStream')->willReturn($handle);
		$this->storage->method('writeIndexEntry')
			->willReturnCallback(function ($h, array $entry, bool $isFirst) use (&$streamedEntry): void {
				$streamedEntry ??= $entry;
			});

		$this->builder->buildIndex('blog');
		fclose($handle);

		// Same keys, same values — only the id differs, since these are
		// different objects by name.
		$this->assertNotNull($standardEntry);
		$this->assertNotNull($streamedEntry);
		$this->assertSame(array_keys($standardEntry), array_keys($streamedEntry));
		$this->assertSame($standardEntry['title'], $streamedEntry['title']);
		$this->assertArrayNotHasKey('body', $streamedEntry);
	}

	public function testStreamingSkipsAnUnreadableObjectAndKeepsGoing(): void
	{
		// One corrupt object must not abandon the rest of the index — the
		// failure mode would be a collection that silently indexes short.
		$this->storage->method('fetchObjectIdsFromDisk')->willReturn($this->ids(501));
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->createSchemaWithIndex(['id', 'title']));

		$calls = 0;
		$this->objectFetcher->method('fetchObjectFromDisk')
			->willReturnCallback(function () use (&$calls) {
				$calls++;
				if ($calls === 3) {
					throw new \RuntimeException('unreadable');
				}

				return $this->objectWith(['title' => 'A post']);
			});

		$handle = fopen('php://memory', 'r+');
		$this->storage->method('openIndexStream')->willReturn($handle);
		$this->storage->expects($this->exactly(500))->method('writeIndexEntry');
		$this->storage->expects($this->once())->method('closeIndexStream');

		$this->builder->buildIndex('blog');

		fclose($handle);
	}

	// ── Incremental maintenance: runs on every save and delete ───────────────

	public function testAppendingReplacesAnExistingEntryRatherThanDuplicatingIt(): void
	{
		// This is the update path. A second entry for the same id would show
		// the object twice in every listing.
		$this->storage->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'post-1', 'title' => 'Old'],
			['id' => 'post-2', 'title' => 'Other'],
		]));

		$object     = $this->createMock(\TotalCMS\Domain\Object\Data\ObjectData::class);
		$object->id = 'post-1';
		$object->method('toArray')->willReturn(['id' => 'post-1', 'title' => 'New']);

		$saved = null;
		$this->storage->method('saveIndex')
			->willReturnCallback(function (string $c, IndexData $i) use (&$saved): void {
				$saved = $i->objects->values()->toArray();
			});

		$this->builder->appendObjectToIndex('blog', $object);

		$this->assertCount(2, $saved);
		$this->assertSame(['post-2', 'post-1'], array_column($saved, 'id'));
		$this->assertSame('New', $saved[1]['title']);
	}

	public function testAppendingToACollectionWithNoIndexYetStartsOne(): void
	{
		$this->storage->method('fetchIndex')->willReturn(null);

		$object     = $this->createMock(\TotalCMS\Domain\Object\Data\ObjectData::class);
		$object->id = 'post-1';
		$object->method('toArray')->willReturn(['id' => 'post-1']);

		$this->storage->expects($this->once())->method('saveIndex');

		$this->builder->appendObjectToIndex('blog', $object);
	}

	public function testAnAppendedEntryCarriesTheWholeObjectNotJustIndexedProperties(): void
	{
		// Documenting a divergence rather than endorsing it. A rebuild stores
		// only the schema's index properties; this path stores toArray(), so
		// between a save and the queued rebuild the entry is fatter and
		// contains fields a rebuild will later drop. Anything reading a
		// non-indexed property from the index works right after a save and
		// stops working once the rebuild lands.
		$this->storage->method('fetchIndex')->willReturn(new IndexData());

		$object     = $this->createMock(\TotalCMS\Domain\Object\Data\ObjectData::class);
		$object->id = 'post-1';
		$object->method('toArray')->willReturn(['id' => 'post-1', 'title' => 'T', 'body' => 'not indexed']);

		$saved = null;
		$this->storage->method('saveIndex')
			->willReturnCallback(function (string $c, IndexData $i) use (&$saved): void {
				$saved = $i->objects->values()->toArray();
			});

		$this->builder->appendObjectToIndex('blog', $object);

		$this->assertArrayHasKey('body', $saved[0]);
	}

	public function testRemovingDropsOnlyTheNamedObject(): void
	{
		// The delete path. Leaving the entry behind keeps a deleted object
		// visible in listings until the next full rebuild.
		$this->storage->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'post-1'],
			['id' => 'post-2'],
			['id' => 'post-3'],
		]));

		$saved = null;
		$this->storage->method('saveIndex')
			->willReturnCallback(function (string $c, IndexData $i) use (&$saved): void {
				$saved = $i->objects->values()->toArray();
			});

		$this->builder->removeObjectFromIndex('blog', 'post-2');

		$this->assertSame(['post-1', 'post-3'], array_column($saved, 'id'));
	}

	public function testRemovingFromACollectionWithNoIndexWritesNothing(): void
	{
		// No index means nothing to remove — writing one here would create an
		// index that claims the collection is empty.
		$this->storage->method('fetchIndex')->willReturn(null);
		$this->storage->expects($this->never())->method('saveIndex');

		$this->builder->removeObjectFromIndex('blog', 'post-1');
	}

	// ── smartBuildIndex: which strategy a save triggers ──────────────────────

	private function collectionWithQueueing(bool $queue): \TotalCMS\Domain\Collection\Data\CollectionData
	{
		$collection                     = new \TotalCMS\Domain\Collection\Data\CollectionData();
		$collection->id                 = 'blog';
		$collection->name               = 'blog';
		$collection->schema             = 'blog';
		$collection->queueRebuildOnSave = $queue;

		return $collection;
	}

	public function testASaveOnAQueueingCollectionAppendsNowAndRebuildsLater(): void
	{
		// Both halves matter: the append is what makes the new object visible
		// immediately, the queued job is what restores a correct index. Losing
		// the append means new content does not appear until the queue runs.
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collectionWithQueueing(true));
		$this->storage->method('fetchIndex')->willReturn(new IndexData());

		$object     = $this->createMock(\TotalCMS\Domain\Object\Data\ObjectData::class);
		$object->id = 'post-1';
		$object->method('toArray')->willReturn(['id' => 'post-1']);

		$this->storage->expects($this->once())->method('saveIndex');
		$this->jobQueuer->expects($this->once())->method('queueBuildIndex')->with('blog');
		$this->storage->expects($this->never())->method('fetchObjectIdsFromDisk');

		$this->builder->smartBuildIndex('blog', $object);
	}

	public function testAQueueingCollectionWithNoObjectJustQueues(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collectionWithQueueing(true));

		$this->jobQueuer->expects($this->once())->method('queueBuildIndex')->with('blog');
		$this->storage->expects($this->never())->method('saveIndex');
		$this->storage->expects($this->never())->method('fetchObjectIdsFromDisk');

		$this->builder->smartBuildIndex('blog');
	}

	public function testANonQueueingCollectionRebuildsImmediately(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collectionWithQueueing(false));
		$this->storage->method('fetchObjectIdsFromDisk')->willReturn(['post-1']);
		$this->schemaFetcher->method('fetchSchemaForCollection')
			->willReturn($this->createSchemaWithIndex(['id']));

		$this->jobQueuer->expects($this->never())->method('queueBuildIndex');
		$this->storage->expects($this->once())->method('saveIndex');

		$this->builder->smartBuildIndex('blog');
	}

	public function testRefusesToBuildForACollectionThatDoesNotExist(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);

		$this->expectException(\DomainException::class);

		$this->builder->smartBuildIndex('nope');
	}
}
