<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JumpStart;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Factory\LoggerFactory;

final class JumpStartExportExcludesCalculatedFieldsTest extends TestCase
{
	private JumpStartExporter $exporter;
	private \PHPUnit\Framework\MockObject\MockObject $collectionLister;
	private \PHPUnit\Framework\MockObject\MockObject $schemaLister;
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $indexReader;
	private \PHPUnit\Framework\MockObject\MockObject $templateLister;
	private \PHPUnit\Framework\MockObject\MockObject $templateFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $jumpstart;
	private \PHPUnit\Framework\MockObject\MockObject $cacheManager;
	private \PHPUnit\Framework\MockObject\MockObject $loggerFactory;

	protected function setUp(): void
	{
		$this->collectionLister = $this->createMock(CollectionLister::class);
		$this->schemaLister     = $this->createMock(SchemaLister::class);
		$this->schemaFetcher    = $this->createMock(SchemaFetcher::class);
		$this->objectFetcher    = $this->createMock(ObjectFetcher::class);
		$this->indexReader      = $this->createMock(IndexReader::class);
		$this->templateLister   = $this->createMock(TemplateLister::class);
		$this->templateFetcher  = $this->createMock(TemplateFetcher::class);
		$this->jumpstart        = $this->createMock(JumpStartData::class);
		$this->cacheManager     = $this->createMock(CacheManager::class);
		$this->loggerFactory    = $this->createMock(LoggerFactory::class);

		// Mock logger factory to return itself for chaining
		$this->loggerFactory
			->method('addFileHandler')
			->willReturnSelf();
		$this->loggerFactory
			->method('createLogger')
			->willReturn($this->createMock(\Psr\Log\LoggerInterface::class));

		$this->exporter = new JumpStartExporter(
			$this->collectionLister,
			$this->schemaLister,
			$this->schemaFetcher,
			$this->objectFetcher,
			$this->indexReader,
			$this->templateLister,
			$this->templateFetcher,
			$this->jumpstart,
			$this->cacheManager,
			$this->loggerFactory
		);
	}

	public function testExportExcludesTotalObjectsFromCollections(): void
	{
		// Create a collection with totalObjects, lastUpdated, and count set
		$collection               = new CollectionData();
		$collection->id           = 'test-collection';
		$collection->name         = 'Test Collection';
		$collection->schema       = 'custom-schema';
		$collection->totalObjects = 42;
		$collection->lastUpdated  = '2025-01-01T00:00:00+00:00';
		$collection->count        = 100;

		$this->collectionLister
			->expects($this->exactly(2))
			->method('listAllCollections')
			->willReturn([$collection]);

		// Expect that addCustomCollection is called WITHOUT calculated fields
		$this->jumpstart
			->expects($this->once())
			->method('addCustomCollection')
			->with($this->callback(function (array $data): bool {
				// Verify calculated fields are excluded
				expect($data)->not()->toHaveKey('totalObjects');
				expect($data)->not()->toHaveKey('lastUpdated');
				expect($data)->not()->toHaveKey('count');

				// Verify other fields are present
				expect($data)->toHaveKey('id');
				expect($data)->toHaveKey('name');
				expect($data)->toHaveKey('schema');
				expect($data['id'])->toBe('test-collection');

				return true;
			}));

		$this->schemaLister
			->expects($this->once())
			->method('listCustomSchemas')
			->willReturn([]);

		$this->indexReader
			->expects($this->once())
			->method('fetchIndex')
			->with('test-collection')
			->willReturn(new IndexData([]));

		$this->templateLister
			->expects($this->once())
			->method('listCustomTemplates')
			->willReturn([]);

		// Export (this will call the methods we're testing)
		$result = $this->exporter->exportCurrentData();

		expect($result)->toBeInstanceOf(JumpStartData::class);
	}

	public function testExportHandlesReservedCollections(): void
	{
		// Create a reserved collection (these should be added via addReservedCollection)
		$collection               = new CollectionData();
		$collection->id           = 'auth';
		$collection->name         = 'Auth';
		$collection->schema       = 'auth';
		$collection->totalObjects = 5;
		$collection->lastUpdated  = '2025-01-01T00:00:00+00:00';

		$this->collectionLister
			->expects($this->exactly(2))
			->method('listAllCollections')
			->willReturn([$collection]);

		// Expect that addReservedCollection is called (not addCustomCollection)
		$this->jumpstart
			->expects($this->once())
			->method('addReservedCollection')
			->with('auth');

		$this->jumpstart
			->expects($this->never())
			->method('addCustomCollection');

		$this->schemaLister
			->expects($this->once())
			->method('listCustomSchemas')
			->willReturn([]);

		$this->indexReader
			->expects($this->once())
			->method('fetchIndex')
			->with('auth')
			->willReturn(new IndexData([]));

		$this->templateLister
			->expects($this->once())
			->method('listCustomTemplates')
			->willReturn([]);

		$result = $this->exporter->exportCurrentData();

		expect($result)->toBeInstanceOf(JumpStartData::class);
	}

	public function testExportHandlesMixOfReservedAndCustomCollections(): void
	{
		$reservedCollection               = new CollectionData();
		$reservedCollection->id           = 'auth';
		$reservedCollection->schema       = 'auth';
		$reservedCollection->totalObjects = 5;
		$reservedCollection->lastUpdated  = '2025-01-01T00:00:00+00:00';
		$reservedCollection->count        = 10;

		$customCollection               = new CollectionData();
		$customCollection->id           = 'products';
		$customCollection->name         = 'Products';
		$customCollection->schema       = 'custom-products';
		$customCollection->totalObjects = 42;
		$customCollection->lastUpdated  = '2025-01-02T00:00:00+00:00';
		$customCollection->count        = 100;

		$this->collectionLister
			->expects($this->exactly(2))
			->method('listAllCollections')
			->willReturn([$reservedCollection, $customCollection]);

		// Expect addReservedCollection for auth
		$this->jumpstart
			->expects($this->once())
			->method('addReservedCollection')
			->with('auth');

		// Expect addCustomCollection for products WITHOUT calculated fields
		$this->jumpstart
			->expects($this->once())
			->method('addCustomCollection')
			->with($this->callback(function (array $data): bool {
				expect($data)->not()->toHaveKey('totalObjects');
				expect($data)->not()->toHaveKey('lastUpdated');
				expect($data)->not()->toHaveKey('count');
				expect($data['id'])->toBe('products');

				return true;
			}));

		$this->schemaLister
			->expects($this->once())
			->method('listCustomSchemas')
			->willReturn([]);

		$this->indexReader
			->expects($this->exactly(2))
			->method('fetchIndex')
			->willReturnCallback(fn (string $collectionId): IndexData => new IndexData([]));

		$this->templateLister
			->expects($this->once())
			->method('listCustomTemplates')
			->willReturn([]);

		$result = $this->exporter->exportCurrentData();

		expect($result)->toBeInstanceOf(JumpStartData::class);
	}
}
