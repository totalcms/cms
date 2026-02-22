<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Index\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
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
	private \PHPUnit\Framework\MockObject\MockObject $jobQueuer;

	protected function setUp(): void
	{
		$this->storage           = $this->createMock(IndexRepository::class);
		$this->objectFetcher     = $this->createMock(ObjectFetcher::class);
		$this->schemaFetcher     = $this->createMock(SchemaFetcher::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->jobQueuer         = $this->createMock(JobQueuer::class);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new NullLogger());

		$this->builder = new IndexBuilder(
			$this->storage,
			$this->objectFetcher,
			$this->schemaFetcher,
			$this->collectionFetcher,
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
			->method('fetchObjectIds')
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
			->with('blog', $this->callback(function (IndexData $index) {
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
			->method('fetchObjectIds')
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
			->method('fetchObjectIds')
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
			->method('fetchObjectIds')
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
}
