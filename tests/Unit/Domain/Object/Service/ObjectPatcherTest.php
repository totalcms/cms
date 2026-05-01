<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Object\Service;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\PropertyData;

final class ObjectPatcherTest extends TestCase
{
	private ObjectPatcher $patcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectUpdater;

	protected function setUp(): void
	{
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->objectUpdater = $this->createMock(ObjectUpdater::class);

		$this->patcher = new ObjectPatcher(
			$this->objectFetcher,
			$this->objectUpdater
		);
	}

	public function testPatchObjectMergesDataCorrectly(): void
	{
		// Create mock object data
		$existingObject = $this->createMockObjectData([
			'id'     => 'test-id',
			'title'  => 'Original Title',
			'status' => 'draft',
			'tags'   => ['tag1', 'tag2'],
		]);

		$newData = [
			'title'    => 'Updated Title',
			'category' => 'news',
			'tags'     => ['tag3', 'tag4'],
		];

		$expectedMergedData = [
			'id'       => 'test-id',
			'title'    => 'Updated Title',  // Should be overridden
			'status'   => 'draft',         // Should be preserved
			'category' => 'news',        // Should be added
			'tags'     => ['tag3', 'tag4'],   // Should be overridden
		];

		$updatedObject = $this->createMockObjectData($expectedMergedData);

		// Set up expectations
		$this->objectFetcher
			->expects($this->once())
			->method('fetchObject')
			->with('posts', 'test-id')
			->willReturn($existingObject);

		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'test-id', $expectedMergedData)
			->willReturn($updatedObject);

		// Execute
		$result = $this->patcher->patchObject('posts', 'test-id', $newData);

		// Verify
		expect($result)->toBe($updatedObject);
	}

	public function testPatchObjectPropertyMergesPropertyData(): void
	{
		// Create mock object with nested property data
		$existingObject = $this->createMockObjectData([
			'id'   => 'test-id',
			'meta' => [
				'author'  => 'John Doe',
				'created' => '2024-01-01',
			],
			'title' => 'Test Post',
		]);

		$newPropertyData = [
			'author'  => 'Jane Doe',  // Should override
			'updated' => '2024-01-15', // Should be added
		];

		$expectedObjectData = [
			'id'   => 'test-id',
			'meta' => [
				'author'  => 'Jane Doe',
				'created' => '2024-01-01',
				'updated' => '2024-01-15',
			],
			'title' => 'Test Post',
		];

		$updatedObject = $this->createMockObjectData($expectedObjectData);

		// Set up expectations
		$this->objectFetcher
			->expects($this->once())
			->method('fetchObject')
			->with('posts', 'test-id')
			->willReturn($existingObject);

		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'test-id', $expectedObjectData)
			->willReturn($updatedObject);

		// Execute
		$result = $this->patcher->patchObjectProperty('posts', 'test-id', 'meta', $newPropertyData);

		// Verify
		expect($result)->toBe($updatedObject);
	}

	public function testPatchObjectPropertyMetaWithRegularProperty(): void
	{
		// Mock a regular (non-depot) property
		$mockProperty = $this->createMockPropertyData([
			['name' => 'item1', 'value' => 'old_value1'],
			['name' => 'item2', 'value' => 'old_value2'],
		]);

		$existingObject = $this->createMockObjectDataWithProperties([
			'id'    => 'test-id',
			'title' => 'Test Post',
		], ['files' => $mockProperty]);

		$newMetaData = ['value' => 'new_value1', 'description' => 'Updated item'];

		$expectedTransformedData = [
			['name' => 'item1', 'value' => 'new_value1', 'description' => 'Updated item'],
			['name' => 'item2', 'value' => 'old_value2'],
		];

		$expectedObjectData = [
			'id'    => 'test-id',
			'title' => 'Test Post',
			'files' => $expectedTransformedData,
		];

		$updatedObject = $this->createMockObjectData($expectedObjectData);

		// Set up expectations
		$this->objectFetcher
			->expects($this->once())
			->method('fetchObject')
			->with('posts', 'test-id')
			->willReturn($existingObject);

		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'test-id', $expectedObjectData)
			->willReturn($updatedObject);

		// Execute
		$result = $this->patcher->patchObjectPropertyMeta('posts', 'test-id', 'files', 'item1', $newMetaData);

		// Verify
		expect($result)->toBe($updatedObject);
	}

	public function testPatchObjectPropertyMetaWithDepotProperty(): void
	{
		// Mock a depot property
		$mockDepotProperty = $this->createMock(DepotData::class);

		// Mock the transform method to return data before and after patch
		$initialTransformData = [
			['name' => 'file1.jpg', 'size' => 1024],
			['name' => 'file2.pdf', 'size' => 2048],
		];

		$updatedTransformData = [
			['name' => 'file1.jpg', 'size' => 1024, 'description' => 'Updated file'],
			['name' => 'file2.pdf', 'size' => 2048],
		];

		$mockDepotProperty
			->expects($this->exactly(2))
			->method('transform')
			->willReturnOnConsecutiveCalls($initialTransformData, $updatedTransformData);

		$existingObject = $this->createMockObjectDataWithProperties([
			'id'    => 'test-id',
			'title' => 'Test Post',
		], ['depot' => $mockDepotProperty]);

		$newMetaData = ['description' => 'Updated file'];

		$expectedObjectData = [
			'id'    => 'test-id',
			'title' => 'Test Post',
			'depot' => $updatedTransformData,
		];

		$updatedObject = $this->createMockObjectData($expectedObjectData);

		// Set up expectations
		$this->objectFetcher
			->expects($this->once())
			->method('fetchObject')
			->with('posts', 'test-id')
			->willReturn($existingObject);

		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'test-id', $expectedObjectData)
			->willReturn($updatedObject);

		// Execute
		$result = $this->patcher->patchObjectPropertyMeta('posts', 'test-id', 'depot', 'file1.jpg', $newMetaData);

		// Verify
		expect($result)->toBe($updatedObject);
	}

	public function testPatchNestedPropertyMergesIntoChildPreservingSiblings(): void
	{
		// Existing card with two children: a `title` text field and an `image` image field.
		// Patching only the image child must preserve the title.
		$existingObject = $this->createMockObjectData([
			'id'      => 'test-id',
			'mycard'  => [
				'title' => 'Existing title',
				'image' => ['name' => 'old.jpg', 'size' => 1000],
			],
		]);

		$newImageData = ['name' => 'new.jpg', 'size' => 2048, 'mime' => 'image/jpeg'];

		$expectedObjectData = [
			'id'     => 'test-id',
			'mycard' => [
				'title' => 'Existing title',
				'image' => ['name' => 'new.jpg', 'size' => 2048, 'mime' => 'image/jpeg'],
			],
		];

		$updatedObject = $this->createMockObjectData($expectedObjectData);

		$this->objectFetcher
			->expects($this->once())
			->method('fetchObject')
			->with('posts', 'test-id')
			->willReturn($existingObject);

		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'test-id', $expectedObjectData)
			->willReturn($updatedObject);

		$result = $this->patcher->patchNestedProperty('posts', 'test-id', 'mycard', 'image', $newImageData);

		expect($result)->toBe($updatedObject);
	}

	public function testPatchNestedPropertyMergesPartialUpdateIntoExistingChild(): void
	{
		// Updating just the `featured` flag on a card-nested image should preserve
		// the rest of the file metadata (name, size, etc.).
		$existingObject = $this->createMockObjectData([
			'id'     => 'test-id',
			'mycard' => [
				'image' => ['name' => 'photo.jpg', 'size' => 5000, 'featured' => false, 'alt' => 'desc'],
			],
		]);

		$expectedObjectData = [
			'id'     => 'test-id',
			'mycard' => [
				'image' => ['name' => 'photo.jpg', 'size' => 5000, 'featured' => true, 'alt' => 'desc'],
			],
		];

		$updatedObject = $this->createMockObjectData($expectedObjectData);

		$this->objectFetcher
			->method('fetchObject')
			->willReturn($existingObject);

		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'test-id', $expectedObjectData)
			->willReturn($updatedObject);

		$this->patcher->patchNestedProperty('posts', 'test-id', 'mycard', 'image', ['featured' => true]);
	}

	public function testPatchNestedPropertyCreatesParentSlotWhenMissing(): void
	{
		// First-time write: parent card slot doesn't exist on the object yet.
		// Patcher should create it rather than failing.
		$existingObject = $this->createMockObjectData([
			'id' => 'test-id',
		]);

		$newData = ['name' => 'first.jpg'];

		$expectedObjectData = [
			'id'     => 'test-id',
			'mycard' => ['image' => ['name' => 'first.jpg']],
		];

		$updatedObject = $this->createMockObjectData($expectedObjectData);

		$this->objectFetcher
			->method('fetchObject')
			->willReturn($existingObject);

		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->with('posts', 'test-id', $expectedObjectData)
			->willReturn($updatedObject);

		$this->patcher->patchNestedProperty('posts', 'test-id', 'mycard', 'image', $newData);
	}

	public function testPatchObjectPropertyMetaThrowsExceptionForMissingProperty(): void
	{
		// Create object without the requested property
		$existingObject = $this->createMockObjectDataWithProperties([
			'id'    => 'test-id',
			'title' => 'Test Post',
		], []); // No properties

		$this->objectFetcher
			->expects($this->once())
			->method('fetchObject')
			->with('posts', 'test-id')
			->willReturn($existingObject);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Unable to locate object property to patch');

		$this->patcher->patchObjectPropertyMeta('posts', 'test-id', 'nonexistent', 'item', []);
	}

	public function testPatchObjectPropertyMetaWithSubpath(): void
	{
		// Mock a depot property for subpath testing
		$mockDepotProperty = $this->createMock(DepotData::class);

		$transformData = [
			['name' => 'file1.jpg', 'path' => 'images/'],
		];

		$mockDepotProperty
			->expects($this->exactly(2))
			->method('transform')
			->willReturn($transformData);

		$existingObject = $this->createMockObjectDataWithProperties([
			'id' => 'test-id',
		], ['depot' => $mockDepotProperty]);

		$newMetaData = ['alt' => 'Image description'];

		$updatedObject = $this->createMockObjectData([
			'id'    => 'test-id',
			'depot' => $transformData,
		]);

		// Set up expectations
		$this->objectFetcher
			->expects($this->once())
			->method('fetchObject')
			->with('posts', 'test-id')
			->willReturn($existingObject);

		$this->objectUpdater
			->expects($this->once())
			->method('updateObject')
			->willReturn($updatedObject);

		// Execute with subpath
		$result = $this->patcher->patchObjectPropertyMeta(
			'posts',
			'test-id',
			'depot',
			'file1.jpg',
			$newMetaData,
			'images/'
		);

		// Verify
		expect($result)->toBe($updatedObject);
	}

	private function createMockObjectData(array $data): ObjectData
	{
		$mock = $this->createMock(ObjectData::class);
		$mock->method('toArray')->willReturn($data);

		return $mock;
	}

	private function createMockPropertyData(array $transformData): PropertyData
	{
		$mock = $this->createMock(PropertyData::class);
		$mock->method('transform')->willReturn($transformData);

		return $mock;
	}

	private function createMockObjectDataWithProperties(array $data, array $properties): ObjectData
	{
		$mock = $this->createMock(ObjectData::class);
		$mock->method('toArray')->willReturn($data);

		// Create mock properties collection
		$propertiesCollection = $this->createMock(Collection::class);

		foreach ($properties as $key => $property) {
			$propertiesCollection
				->method('get')
				->with($key)
				->willReturn($property);
		}

		$mock->properties = $propertiesCollection;

		return $mock;
	}
}
