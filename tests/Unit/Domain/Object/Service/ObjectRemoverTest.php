<?php

declare(strict_types = 1);

namespace Tests\Unit\Domain\Object\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Repository\PropertyRepository;

final class ObjectRemoverTest extends TestCase
{
	private ObjectRemover $remover;
	private \PHPUnit\Framework\MockObject\MockObject $propStorage;
	private \PHPUnit\Framework\MockObject\MockObject $storage;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectUpdater;
	private \PHPUnit\Framework\MockObject\MockObject $indexBuilder;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;

	protected function setUp(): void
	{
		$this->propStorage       = $this->createMock(PropertyRepository::class);
		$this->storage           = $this->createMock(ObjectRepository::class);
		$this->objectFetcher     = $this->createMock(ObjectFetcher::class);
		$this->objectUpdater     = $this->createMock(ObjectUpdater::class);
		$this->indexBuilder      = $this->createMock(IndexBuilder::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);

		$this->remover = new ObjectRemover(
			$this->propStorage,
			$this->storage,
			$this->objectFetcher,
			$this->objectUpdater,
			$this->indexBuilder,
			$this->collectionFetcher
		);
	}

	public function testDeleteObjectSuccessfully(): void
	{
		// Mock collection that doesn't require queueing
		$mockCollection = $this->createMock(CollectionData::class);

		// Set up storage to return success
		$this->storage
			->expects($this->once())
			->method('deleteObject')
			->with('posts', 'test-id')
			->willReturn(true);

		// Set up collection fetcher
		$this->collectionFetcher
			->expects($this->once())
			->method('fetchCollection')
			->with('posts')
			->willReturn($mockCollection);

		// Set up index builder expectation for full rebuild
		$this->indexBuilder
			->expects($this->once())
			->method('smartBuildIndex')
			->with('posts');

		// Execute
		$result = $this->remover->deleteObject('posts', 'test-id');

		// Verify
		expect($result)->toBeTrue();
	}

	public function testDeleteObjectWithQueueRebuildOnSave(): void
	{
		// Mock collection with queueRebuildOnSave enabled
		$mockCollection                     = $this->createMock(CollectionData::class);
		$mockCollection->queueRebuildOnSave = true;

		// Set up storage to return success
		$this->storage
			->expects($this->once())
			->method('deleteObject')
			->with('posts', 'test-id')
			->willReturn(true);

		// Set up collection fetcher
		$this->collectionFetcher
			->expects($this->once())
			->method('fetchCollection')
			->with('posts')
			->willReturn($mockCollection);

		// Set up index builder expectations - should call both methods
		$this->indexBuilder
			->expects($this->once())
			->method('removeObjectFromIndex')
			->with('posts', 'test-id');

		$this->indexBuilder
			->expects($this->once())
			->method('smartBuildIndex')
			->with('posts');

		// Execute
		$result = $this->remover->deleteObject('posts', 'test-id');

		// Verify
		expect($result)->toBeTrue();
	}

	public function testDeleteObjectFailure(): void
	{
		// Set up storage to return failure
		$this->storage
			->expects($this->once())
			->method('deleteObject')
			->with('posts', 'test-id')
			->willReturn(false);

		// Collection fetcher and index builder should not be called on failure
		$this->collectionFetcher
			->expects($this->never())
			->method('fetchCollection');

		$this->indexBuilder
			->expects($this->never())
			->method('smartBuildIndex');

		$this->indexBuilder
			->expects($this->never())
			->method('removeObjectFromIndex');

		// Execute
		$result = $this->remover->deleteObject('posts', 'test-id');

		// Verify
		expect($result)->toBeFalse();
	}

	public function testDeleteObjectWithNonCollectionDataResponse(): void
	{
		// Set up storage to return success
		$this->storage
			->expects($this->once())
			->method('deleteObject')
			->with('posts', 'test-id')
			->willReturn(true);

		// Collection fetcher returns non-CollectionData (null)
		$this->collectionFetcher
			->expects($this->once())
			->method('fetchCollection')
			->with('posts')
			->willReturn(null);

		// Should still call smartBuildIndex but not removeObjectFromIndex
		$this->indexBuilder
			->expects($this->once())
			->method('smartBuildIndex')
			->with('posts');

		$this->indexBuilder
			->expects($this->never())
			->method('removeObjectFromIndex');

		// Execute
		$result = $this->remover->deleteObject('posts', 'test-id');

		// Verify
		expect($result)->toBeTrue();
	}

	public function testDeleteObjectPropertySetsPropertyToNull(): void
	{
		// Create mock object
		$mockObject = $this->createMock(ObjectData::class);
		$mockObject->method('toArray')->willReturn([
			'id'    => 'test-id',
			'title' => 'Test Title',
			'files' => ['file1.jpg', 'file2.jpg'],
		]);

		$updatedObject = $this->createMock(ObjectData::class);

		// Set up object fetcher expectation
		$this->objectFetcher
			->expects($this->once())
			->method('fetchObject')
			->with('posts', 'test-id')
			->willReturn($mockObject);

		// Set up property storage to delete directory
		$this->propStorage
			->expects($this->once())
			->method('deleteDirectory')
			->with('posts', 'test-id', 'files');

		// Set up object updater expectation with nullified property
		$expectedUpdatedData = [
			'id'    => 'test-id',
			'title' => 'Test Title',
			'files' => null,  // Should be null
		];

		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'test-id', $expectedUpdatedData)
			->willReturn($updatedObject);

		// Execute
		$result = $this->remover->deleteObjectProperty('posts', 'test-id', 'files');

		// Verify
		expect($result)->toBe($updatedObject);
	}

	public function testDeleteObjectPropertyCleansUpStorageDirectory(): void
	{
		// Create mock object
		$mockObject = $this->createMock(ObjectData::class);
		$mockObject->method('toArray')->willReturn([
			'id'      => 'test-id',
			'gallery' => ['image1.jpg', 'image2.jpg'],
		]);

		$updatedObject = $this->createMock(ObjectData::class);

		// Set up object fetcher expectation
		$this->objectFetcher
			->expects($this->once())
			->method('fetchObject')
			->with('gallery-collection', 'gallery-id')
			->willReturn($mockObject);

		// Most important: verify storage cleanup is called
		$this->propStorage
			->expects($this->once())
			->method('deleteDirectory')
			->with('gallery-collection', 'gallery-id', 'images');

		// Set up object updater expectation
		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->willReturn($updatedObject);

		// Execute
		$result = $this->remover->deleteObjectProperty('gallery-collection', 'gallery-id', 'images');

		// Verify
		expect($result)->toBe($updatedObject);
	}

	public function testDeleteObjectPropertyMaintainsOtherProperties(): void
	{
		// Create mock object with multiple properties
		$mockObject   = $this->createMock(ObjectData::class);
		$originalData = [
			'id'          => 'test-id',
			'title'       => 'Keep This Title',
			'content'     => 'Keep This Content',
			'attachments' => ['file1.pdf', 'file2.doc'],  // This will be deleted
			'tags'        => ['tag1', 'tag2'],  // This should remain
		];
		$mockObject->method('toArray')->willReturn($originalData);

		$updatedObject = $this->createMock(ObjectData::class);

		// Set up object fetcher expectation
		$this->objectFetcher
			->expects($this->once())
			->method('fetchObject')
			->with('documents', 'doc-123')
			->willReturn($mockObject);

		// Set up property storage expectation
		$this->propStorage
			->expects($this->once())
			->method('deleteDirectory')
			->with('documents', 'doc-123', 'attachments');

		// Verify that only the specified property is nullified
		$expectedUpdatedData = [
			'id'          => 'test-id',
			'title'       => 'Keep This Title',
			'content'     => 'Keep This Content',
			'attachments' => null,  // Only this should be null
			'tags'        => ['tag1', 'tag2'],  // This should remain unchanged
		];

		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with('documents', 'doc-123', $expectedUpdatedData)
			->willReturn($updatedObject);

		// Execute
		$result = $this->remover->deleteObjectProperty('documents', 'doc-123', 'attachments');

		// Verify
		expect($result)->toBe($updatedObject);
	}
}
