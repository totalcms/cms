<?php

namespace Tests\Unit\Action\Cache;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Cache\CollectionImageCacheDeleteAction;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Renderer\JsonRenderer;

final class CollectionImageCacheDeleteActionTest extends TestCase
{
	private CollectionImageCacheDeleteAction $action;
	private ImageCacheService $imageCacheService;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->imageCacheService = $this->createMock(ImageCacheService::class);
		$this->renderer          = $this->createMock(JsonRenderer::class);
		$this->request           = $this->createMock(ServerRequestInterface::class);
		$this->response          = $this->createMock(ResponseInterface::class);

		$this->action = new CollectionImageCacheDeleteAction(
			$this->imageCacheService,
			$this->renderer
		);
	}

	public function testClearsCollectionImageCacheSuccessfully(): void
	{
		$collection = 'products';
		$statsBefore = ['cached_files' => 50, 'total_size_mb' => 12.5];
		$statsAfter = ['cached_files' => 0, 'total_size_mb' => 0.0];

		$this->request->method('getAttribute')->with('collection')->willReturn($collection);
		$this->request->method('getParsedBody')->willReturn([]);

		$this->imageCacheService->expects($this->exactly(2))
			->method('getCollectionImageCacheStats')
			->with($collection)
			->willReturnOnConsecutiveCalls($statsBefore, $statsAfter);

		$this->imageCacheService->expects($this->once())
			->method('clearCollectionImageCache')
			->with($collection)
			->willReturn(true);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$this->response,
				$this->callback(function ($data) use ($collection, $statsBefore) {
					return $data['deleted'] === true
						&& $data['collection'] === $collection
						&& $data['stats']['files_removed'] === $statsBefore['cached_files']
						&& $data['stats']['size_freed_mb'] === $statsBefore['total_size_mb'];
				})
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testGetsCollectionFromRequestAttribute(): void
	{
		$collection = 'gallery';

		$this->request->method('getAttribute')->with('collection')->willReturn($collection);
		$this->request->method('getParsedBody')->willReturn([]);

		$this->imageCacheService->method('getCollectionImageCacheStats')
			->willReturn(['cached_files' => 0, 'total_size_mb' => 0.0]);
		$this->imageCacheService->method('clearCollectionImageCache')->willReturn(false);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		// Verify it completed successfully
		$this->assertSame($jsonResponse, $result);
	}

	public function testGetsCollectionFromParsedBody(): void
	{
		$collection = 'images';

		$this->request->method('getAttribute')->with('collection')->willReturn(null);
		$this->request->method('getParsedBody')->willReturn(['collection' => $collection]);

		$this->imageCacheService->method('getCollectionImageCacheStats')
			->willReturn(['cached_files' => 0, 'total_size_mb' => 0.0]);
		$this->imageCacheService->method('clearCollectionImageCache')->willReturn(false);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		// Verify it completed successfully
		$this->assertSame($jsonResponse, $result);
	}

	public function testReturns400WhenCollectionMissing(): void
	{
		$this->request->method('getAttribute')->with('collection')->willReturn(null);
		$this->request->method('getParsedBody')->willReturn([]);

		$errorResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($errorResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$errorResponse,
				$this->callback(function ($data) {
					return isset($data['error'])
						&& str_contains($data['error'], 'Collection parameter is required');
				})
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testReturns400WhenCollectionEmpty(): void
	{
		$this->request->method('getAttribute')->with('collection')->willReturn('');
		$this->request->method('getParsedBody')->willReturn([]);

		$errorResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($errorResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with($errorResponse, $this->arrayHasKey('error'))
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testReturns500WhenClearingFails(): void
	{
		$collection = 'products';

		$this->request->method('getAttribute')->with('collection')->willReturn($collection);
		$this->request->method('getParsedBody')->willReturn([]);

		$this->imageCacheService->method('getCollectionImageCacheStats')
			->willReturn(['cached_files' => 50, 'total_size_mb' => 12.5]);

		$this->imageCacheService->expects($this->once())
			->method('clearCollectionImageCache')
			->with($collection)
			->willThrowException(new \RuntimeException('Permission denied'));

		$errorResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($errorResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$errorResponse,
				$this->callback(function ($data) {
					return isset($data['error'])
						&& str_contains($data['error'], 'Failed to clear collection image cache')
						&& str_contains($data['error'], 'Permission denied');
				})
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testReturns500WhenGetStatsFails(): void
	{
		$collection = 'products';

		$this->request->method('getAttribute')->with('collection')->willReturn($collection);
		$this->request->method('getParsedBody')->willReturn([]);

		$this->imageCacheService->expects($this->once())
			->method('getCollectionImageCacheStats')
			->with($collection)
			->willThrowException(new \RuntimeException('Directory not accessible'));

		$errorResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($errorResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with($errorResponse, $this->arrayHasKey('error'))
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testIncludesStatsBeforeAndAfter(): void
	{
		$collection = 'blog';
		$statsBefore = ['cached_files' => 100, 'total_size_mb' => 25.0];
		$statsAfter = ['cached_files' => 0, 'total_size_mb' => 0.0];

		$this->request->method('getAttribute')->with('collection')->willReturn($collection);
		$this->request->method('getParsedBody')->willReturn([]);

		$this->imageCacheService->method('getCollectionImageCacheStats')
			->willReturnOnConsecutiveCalls($statsBefore, $statsAfter);

		$this->imageCacheService->method('clearCollectionImageCache')->willReturn(true);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$this->response,
				$this->callback(function ($data) use ($statsBefore, $statsAfter) {
					return isset($data['stats']['before'])
						&& isset($data['stats']['after'])
						&& $data['stats']['before'] === $statsBefore
						&& $data['stats']['after'] === $statsAfter;
				})
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testCalculatesFilesRemovedAndSizeFreed(): void
	{
		$collection = 'media';
		$statsBefore = ['cached_files' => 75, 'total_size_mb' => 18.5];
		$statsAfter = ['cached_files' => 0, 'total_size_mb' => 0.0];

		$this->request->method('getAttribute')->with('collection')->willReturn($collection);
		$this->request->method('getParsedBody')->willReturn([]);

		$this->imageCacheService->method('getCollectionImageCacheStats')
			->willReturnOnConsecutiveCalls($statsBefore, $statsAfter);

		$this->imageCacheService->method('clearCollectionImageCache')->willReturn(true);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$this->response,
				$this->callback(function ($data) {
					return $data['deleted'] === true
						&& $data['stats']['files_removed'] === 75
						&& $data['stats']['size_freed_mb'] === 18.5;
				})
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testHandlesEmptyCache(): void
	{
		$collection = 'empty';
		$stats = ['cached_files' => 0, 'total_size_mb' => 0.0];

		$this->request->method('getAttribute')->with('collection')->willReturn($collection);
		$this->request->method('getParsedBody')->willReturn([]);

		$this->imageCacheService->method('getCollectionImageCacheStats')
			->willReturn($stats);

		$this->imageCacheService->method('clearCollectionImageCache')->willReturn(false);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$this->response,
				$this->callback(function ($data) {
					return $data['deleted'] === false
						&& $data['stats']['files_removed'] === 0
						&& $data['stats']['size_freed_mb'] === 0.0;
				})
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testIncludesCollectionNameInResponse(): void
	{
		$collection = 'products';

		$this->request->method('getAttribute')->with('collection')->willReturn($collection);
		$this->request->method('getParsedBody')->willReturn([]);

		$this->imageCacheService->method('getCollectionImageCacheStats')
			->willReturn(['cached_files' => 0, 'total_size_mb' => 0.0]);

		$this->imageCacheService->method('clearCollectionImageCache')->willReturn(false);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with(
				$this->response,
				$this->callback(function ($data) use ($collection) {
					return isset($data['collection'])
						&& $data['collection'] === $collection;
				})
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testHandlesNullParsedBody(): void
	{
		$collection = 'test';

		$this->request->method('getAttribute')->with('collection')->willReturn($collection);
		$this->request->method('getParsedBody')->willReturn(null);

		$this->imageCacheService->method('getCollectionImageCacheStats')
			->willReturn(['cached_files' => 0, 'total_size_mb' => 0.0]);

		$this->imageCacheService->method('clearCollectionImageCache')->willReturn(false);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('json')->willReturn($jsonResponse);

		// Should not throw exception with null parsed body
		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}
}
