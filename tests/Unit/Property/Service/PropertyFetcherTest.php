<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Service;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;

class PropertyFetcherTest extends TestCase
{
	private PropertyFetcher $propertyFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $mockObjectFetcher;

	protected function setUp(): void
	{
		$this->mockObjectFetcher = $this->createMock(ObjectFetcher::class);

		$this->propertyFetcher = new PropertyFetcher(
			$this->mockObjectFetcher
		);
	}

	public function testFetchPropertyWhenDataExists(): void
	{
		$collection = 'test-collection';
		$objectID = 'test-object';
		$property = 'title';

		// Create mock PropertyData object
		$mockPropertyDataObject = $this->createMock(PropertyData::class);

		// Create mock object with properties collection
		$propertiesCollection = new Collection([$property => $mockPropertyDataObject]);
		$mockObject = $this->createMock(ObjectData::class);
		$mockObject->properties = $propertiesCollection;

		// Mock ObjectFetcher to return the mock object
		$this->mockObjectFetcher->expects($this->once())
			->method('fetchObject')
			->with($collection, $objectID)
			->willReturn($mockObject);

		$result = $this->propertyFetcher->fetchProperty($collection, $objectID, $property);

		$this->assertInstanceOf(PropertyData::class, $result);
		$this->assertEquals($mockPropertyDataObject, $result);
	}

	public function testFetchPropertyThrowsExceptionWhenDataNotFound(): void
	{
		$collection = 'test-collection';
		$objectID = 'test-object';
		$property = 'nonexistent';

		// Create mock object with empty properties collection
		$propertiesCollection = new Collection([]); // No properties
		$mockObject = $this->createMock(ObjectData::class);
		$mockObject->properties = $propertiesCollection;

		$this->mockObjectFetcher->expects($this->once())
			->method('fetchObject')
			->willReturn($mockObject);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Unable to locate object property nonexistent');

		$this->propertyFetcher->fetchProperty($collection, $objectID, $property);
	}

	public function testFetchPropertyThrowsExceptionWhenObjectNotFound(): void
	{
		$collection = 'test-collection';
		$objectID = 'nonexistent-object';
		$property = 'title';

		// ObjectFetcher throws exception when object doesn't exist
		$this->mockObjectFetcher->expects($this->once())
			->method('fetchObject')
			->willThrowException(new \UnexpectedValueException('Object not found'));

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Object not found');

		$this->propertyFetcher->fetchProperty($collection, $objectID, $property);
	}

	public function testFetchPropertyThrowsExceptionWhenPropertyIsNotPropertyData(): void
	{
		$collection = 'test-collection';
		$objectID = 'test-object';
		$property = 'invalid-property';

		// Create mock object with properties collection containing non-PropertyData
		$propertiesCollection = new Collection([$property => 'not-a-property-data-object']);
		$mockObject = $this->createMock(ObjectData::class);
		$mockObject->properties = $propertiesCollection;

		$this->mockObjectFetcher->expects($this->once())
			->method('fetchObject')
			->willReturn($mockObject);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Unable to locate object property invalid-property');

		$this->propertyFetcher->fetchProperty($collection, $objectID, $property);
	}

	public function testFetchPropertyWithValidPropertyData(): void
	{
		$collection = 'articles';
		$objectID = 'article-123';
		$property = 'metadata';

		// Create mock PropertyData object
		$mockPropertyDataObject = $this->createMock(PropertyData::class);

		// Create mock object with properties collection
		$propertiesCollection = new Collection([
			$property => $mockPropertyDataObject,
			'other-property' => $this->createMock(PropertyData::class)
		]);
		$mockObject = $this->createMock(ObjectData::class);
		$mockObject->properties = $propertiesCollection;

		$this->mockObjectFetcher->expects($this->once())
			->method('fetchObject')
			->with($collection, $objectID)
			->willReturn($mockObject);

		$result = $this->propertyFetcher->fetchProperty($collection, $objectID, $property);

		$this->assertInstanceOf(PropertyData::class, $result);
		$this->assertEquals($mockPropertyDataObject, $result);
	}
}