<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Collection\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\License\Service\EditionFeatureService;

final class CollectionSaverTest extends TestCase
{
	private CollectionSaver $saver;
	private \PHPUnit\Framework\MockObject\MockObject $repository;
	private \PHPUnit\Framework\MockObject\MockObject $factory;
	private \PHPUnit\Framework\MockObject\MockObject $indexRepository;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $editionFeatures;

	protected function setUp(): void
	{
		$this->repository         = $this->createMock(CollectionRepository::class);
		$this->factory            = $this->createMock(CollectionFactory::class);
		$this->indexRepository    = $this->createMock(IndexRepository::class);
		$this->collectionFetcher  = $this->createMock(CollectionFetcher::class);
		$this->editionFeatures    = $this->createMock(EditionFeatureService::class);

		// Setup factory mock to return valid CollectionData from arrays
		$this->factory
			->method('generateCollection')
			->willReturnCallback(function (array $data): CollectionData {
				$collection               = new CollectionData();
				$collection->id           = $data['id'] ?? '';
				$collection->schema       = $data['schema'] ?? 'blog';
				$collection->count        = $data['count'] ?? 0;
				$collection->totalObjects = $data['totalObjects'] ?? 0;
				$collection->lastUpdated  = $data['lastUpdated'] ?? '';

				return $collection;
			});

		$this->saver = new CollectionSaver(
			$this->repository,
			$this->factory,
			$this->indexRepository,
			$this->collectionFetcher,
			$this->editionFeatures
		);
	}

	public function testIncrementTotalObjects(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->totalObjects = 5;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify totalObjects incremented
				expect($collection->totalObjects)->toBe(6);

				// Verify lastUpdated was updated (should be recent)
				$updatedDate = new \DateTime($collection->lastUpdated);
				$now         = new \DateTime();
				$diff        = $now->getTimestamp() - $updatedDate->getTimestamp();
				expect($diff)->toBeLessThan(5); // Within 5 seconds

				return true;
			}));

		$result = $this->saver->incrementTotalObjects('test-collection');

		expect($result)->toBeInstanceOf(CollectionData::class);
		expect($result->totalObjects)->toBe(6);
	}

	public function testDecrementTotalObjects(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->totalObjects = 5;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify totalObjects decremented
				expect($collection->totalObjects)->toBe(4);

				// Verify lastUpdated was updated
				expect($collection->lastUpdated)->not()->toBe('2025-01-01T00:00:00+00:00');

				return true;
			}));

		$result = $this->saver->decrementTotalObjects('test-collection');

		expect($result)->toBeInstanceOf(CollectionData::class);
		expect($result->totalObjects)->toBe(4);
	}

	public function testDecrementTotalObjectsDoesNotGoBelowZero(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->totalObjects = 0; // Already at zero

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Should remain at 0, not go negative
				expect($collection->totalObjects)->toBe(0);

				return true;
			}));

		$result = $this->saver->decrementTotalObjects('test-collection');

		expect($result->totalObjects)->toBe(0);
	}

	public function testUpdateLastUpdated(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->totalObjects = 5;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify totalObjects unchanged
				expect($collection->totalObjects)->toBe(5);

				// Verify lastUpdated was updated (should be now)
				$updatedDate = new \DateTime($collection->lastUpdated);
				$now         = new \DateTime();
				$diff        = $now->getTimestamp() - $updatedDate->getTimestamp();
				expect($diff)->toBeLessThan(5); // Within 5 seconds

				return true;
			}));

		$result = $this->saver->updateLastUpdated('test-collection');

		expect($result)->toBeInstanceOf(CollectionData::class);
		expect($result->totalObjects)->toBe(5); // Should not change
	}

	public function testIncrementTotalObjectsThrowsExceptionForMissingCollection(): void
	{
		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('nonexistent')
			->willReturn(null);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Error fetching Collection with id nonexistent');

		$this->saver->incrementTotalObjects('nonexistent');
	}

	public function testDecrementTotalObjectsThrowsExceptionForMissingCollection(): void
	{
		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('nonexistent')
			->willReturn(null);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Error fetching Collection with id nonexistent');

		$this->saver->decrementTotalObjects('nonexistent');
	}

	public function testUpdateLastUpdatedThrowsExceptionForMissingCollection(): void
	{
		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('nonexistent')
			->willReturn(null);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Error fetching Collection with id nonexistent');

		$this->saver->updateLastUpdated('nonexistent');
	}

	public function testIncrementTotalObjectsHandlesMissingTotalObjects(): void
	{
		$collectionData         = new CollectionData();
		$collectionData->id     = 'test-collection';
		$collectionData->schema = 'blog';
		// totalObjects not set (defaults to 0)
		$collectionData->totalObjects = 0;

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				expect($collection->totalObjects)->toBe(1);

				return true;
			}));

		$result = $this->saver->incrementTotalObjects('test-collection');

		expect($result->totalObjects)->toBe(1);
	}

	public function testDecrementTotalObjectsHandlesMissingTotalObjects(): void
	{
		$collectionData         = new CollectionData();
		$collectionData->id     = 'test-collection';
		$collectionData->schema = 'blog';
		// totalObjects not set (defaults to 0)
		$collectionData->totalObjects = 0;

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Should remain at 0
				expect($collection->totalObjects)->toBe(0);

				return true;
			}));

		$result = $this->saver->decrementTotalObjects('test-collection');

		expect($result->totalObjects)->toBe(0);
	}

	public function testIncrementTotalObjectsWithIncrementBy(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->totalObjects = 5;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify totalObjects incremented by 10
				expect($collection->totalObjects)->toBe(15);

				return true;
			}));

		$result = $this->saver->incrementTotalObjects('test-collection', 10);

		expect($result)->toBeInstanceOf(CollectionData::class);
		expect($result->totalObjects)->toBe(15);
	}

	public function testIncrementCountWithIncrementBy(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->count        = 5;
		$collectionData->totalObjects = 5;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		// fetchCollection is called twice: once in incrementCount, once in updateCollection
		$this->repository
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify count incremented by 10
				expect($collection->count)->toBe(15);

				return true;
			}));

		$result = $this->saver->incrementCount('test-collection', 10);

		expect($result)->toBeInstanceOf(CollectionData::class);
	}

	public function testUpdateCollectionRecalculatesTotalObjectsWhenNotProvided(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->count        = 10;
		$collectionData->totalObjects = 5; // Old value
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		// fetchCollection should NOT be called since we pass existingCollection
		$this->repository
			->expects($this->never())
			->method('fetchCollection');

		// Mock index repository to return 8 object IDs (the "real" count)
		$this->indexRepository
			->expects($this->atLeastOnce())
			->method('fetchObjectIds')
			->with('test-collection')
			->willReturn(['obj1', 'obj2', 'obj3', 'obj4', 'obj5', 'obj6', 'obj7', 'obj8']);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify totalObjects was recalculated from index (8 objects)
				expect($collection->totalObjects)->toBe(8);

				return true;
			}));

		// Update without providing totalObjects - should trigger recalculation
		$result = $this->saver->updateCollection('test-collection', [
			'id'     => 'test-collection',
			'schema' => 'blog',
			'name'   => 'Updated Name',
			// Note: totalObjects NOT provided
		], $collectionData);

		expect($result)->toBeInstanceOf(CollectionData::class);
		expect($result->totalObjects)->toBe(8);
	}

	public function testUpdateCollectionCorrectsCountWhenLessThanTotalObjects(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->count        = 3; // Less than totalObjects (incorrect)
		$collectionData->totalObjects = 10;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		// fetchCollection should NOT be called since we pass existingCollection
		$this->repository
			->expects($this->never())
			->method('fetchCollection');

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify count was corrected to match totalObjects
				expect($collection->count)->toBe(10);

				return true;
			}));

		// Update with totalObjects provided but count is wrong
		$result = $this->saver->updateCollection('test-collection', [
			'id'           => 'test-collection',
			'schema'       => 'blog',
			'count'        => 3, // Wrong value
			'totalObjects' => 10,
		], $collectionData);

		expect($result)->toBeInstanceOf(CollectionData::class);
	}
}
