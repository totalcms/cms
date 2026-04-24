<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Event\Listener;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Listener\CacheInvalidationListener;
use TotalCMS\Domain\Event\Payload\CollectionEventPayload;
use TotalCMS\Domain\Event\Payload\ImportEventPayload;
use TotalCMS\Domain\Event\Payload\SchemaEventPayload;

final class CacheInvalidationListenerTest extends TestCase
{
	private EventDispatcher $dispatcher;
	private \PHPUnit\Framework\MockObject\MockObject $cacheManager;

	protected function setUp(): void
	{
		$this->cacheManager = $this->createMock(CacheManager::class);
		$this->dispatcher   = new EventDispatcher(new NullLogger());

		(new CacheInvalidationListener($this->cacheManager))->register($this->dispatcher);
	}

	public function testCollectionCreatedClearsCollectionIndex(): void
	{
		$this->cacheManager
			->expects($this->once())
			->method('clearCollectionIndex')
			->with('posts');

		$this->dispatcher->dispatch('collection.created', new CollectionEventPayload('posts'));
	}

	public function testCollectionUpdatedClearsCollectionIndex(): void
	{
		$this->cacheManager
			->expects($this->once())
			->method('clearCollectionIndex')
			->with('posts');

		$this->dispatcher->dispatch('collection.updated', new CollectionEventPayload('posts'));
	}

	public function testCollectionDeletedClearsCollectionIndex(): void
	{
		$this->cacheManager
			->expects($this->once())
			->method('clearCollectionIndex')
			->with('team');

		$this->dispatcher->dispatch('collection.deleted', new CollectionEventPayload('team'));
	}

	public function testImportCompletedClearsCollectionIndex(): void
	{
		$this->cacheManager
			->expects($this->once())
			->method('clearCollectionIndex')
			->with('products');

		$this->dispatcher->dispatch('import.completed', new ImportEventPayload('products', 50));
	}

	public function testSchemaSavedClearsFlattenedSchemaCache(): void
	{
		$this->cacheManager
			->expects($this->once())
			->method('clearComputedData')
			->with('schema_flattened:blog');

		$this->dispatcher->dispatch('schema.saved', new SchemaEventPayload('blog'));
	}
}
