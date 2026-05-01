<?php

namespace Tests\Unit\Action\Property;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Property\PropertyFileClearCacheAction;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\PropertyCacheCleaner;
use TotalCMS\Renderer\JsonRenderer;

final class PropertyFileClearCacheActionTest extends TestCase
{
	private PropertyFileClearCacheAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $service;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $storage;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->service  = $this->createMock(PropertyCacheCleaner::class);
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->storage  = $this->createMock(PropertyRepository::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new PropertyFileClearCacheAction($this->renderer, $this->service, $this->storage);
	}

	private function expectFlatPath(): void
	{
		// Path is a gallery filename, not a directory — dispatch falls through
		// to the existing file-cache flow.
		$this->storage->method('directoryExists')->willReturn(false);
	}

	private function expectNestedPath(): void
	{
		// Path resolves to an on-disk subdirectory — dispatch routes into the
		// nested property-cache flow (card child or deck-item child).
		$this->storage->method('directoryExists')->willReturn(true);
	}

	public function testClearsFileCacheSuccessfully(): void
	{
		$this->expectFlatPath();

		$args = [
			'collection' => 'products',
			'id'         => 'product-1',
			'property'   => 'gallery',
			'name'       => 'image1.jpg',
		];

		$this->service->expects($this->once())
			->method('deleteFileCache')
			->with('products', 'product-1', 'gallery', 'image1.jpg')
			->willReturn(true);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['deleted' => true])
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($this->response, $result);
	}

	public function testPassesAllArgsToService(): void
	{
		$this->expectFlatPath();

		$args = [
			'collection' => 'blog',
			'id'         => 'post-5',
			'property'   => 'images',
			'name'       => 'header.png',
		];

		$this->service->expects($this->once())
			->method('deleteFileCache')
			->with('blog', 'post-5', 'images', 'header.png')
			->willReturn(true);

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturnsJsonWithDeletedStatus(): void
	{
		$this->expectFlatPath();

		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'files',
			'name'       => 'file.pdf',
		];

		$this->service->method('deleteFileCache')->willReturn(true);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $this->callback(fn ($data): bool => isset($data['deleted']) && $data['deleted'] === true))
			->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testReturns500WhenDeleteFails(): void
	{
		$this->expectFlatPath();

		$args = [
			'collection' => 'test',
			'id'         => 'test-1',
			'property'   => 'prop',
			'name'       => 'file.jpg',
		];

		$this->service->method('deleteFileCache')->willReturn(false);

		$response500 = $this->createMock(ResponseInterface::class);

		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($response500);

		$this->renderer->expects($this->once())
			->method('json')
			->with($response500, ['deleted' => false])
			->willReturn($response500);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($response500, $result);
	}

	public function testCardNestedPathRoutesToPropertyCacheClear(): void
	{
		$this->expectNestedPath();

		// Card child: `/coll/id/mycard/image/cache` → path = "image".
		$args = [
			'collection' => 'pages',
			'id'         => 'home',
			'property'   => 'mycard',
			'path'       => 'image',
		];

		// Nested branch should call deletePropertyCache (clears `prop/{path}/.cache/`)
		// rather than deleteFileCache (which clears `prop/.cache/{name}/`).
		$this->service->expects($this->once())
			->method('deletePropertyCache')
			->with('pages', 'home', 'mycard', 'image')
			->willReturn(true);
		$this->service->expects($this->never())->method('deleteFileCache');

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}

	public function testDeckNestedMultiSegmentPathRoutesToPropertyCacheClear(): void
	{
		$this->expectNestedPath();

		// Deck child: `/coll/id/mydeck/one/image/cache` → path = "one/image".
		// The trait must walk the multi-segment path to the actual on-disk dir.
		$args = [
			'collection' => 'pages',
			'id'         => 'home',
			'property'   => 'mydeck',
			'path'       => 'one/image',
		];

		$this->service->expects($this->once())
			->method('deletePropertyCache')
			->with('pages', 'home', 'mydeck', 'one/image')
			->willReturn(true);
		$this->service->expects($this->never())->method('deleteFileCache');

		$this->renderer->method('json')->willReturn($this->response);

		($this->action)($this->request, $this->response, $args);
	}
}
