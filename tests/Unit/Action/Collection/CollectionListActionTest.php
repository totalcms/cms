<?php

namespace Tests\Unit\Action\Collection;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Collection\CollectionListAction;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\CollectionMetaTransformer;

final class CollectionListActionTest extends TestCase
{
	private CollectionListAction $action;
	private JsonRenderer $renderer;
	private CollectionLister $collectionListService;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->renderer               = $this->createMock(JsonRenderer::class);
		$this->collectionListService  = $this->createMock(CollectionLister::class);
		$this->request                = $this->createMock(ServerRequestInterface::class);
		$this->response               = $this->createMock(ResponseInterface::class);

		$this->action = new CollectionListAction($this->renderer, $this->collectionListService);
	}

	public function testReturnsJsonCollectionResponse(): void
	{
		$collections = [$this->createCollectionData('blog'), $this->createCollectionData('products')];

		$this->collectionListService->expects($this->once())
			->method('listAllCollections')
			->willReturn($collections);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonCollection')
			->with(
				$this->response,
				$collections,
				$this->isInstanceOf(CollectionMetaTransformer::class)
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testCallsListAllCollections(): void
	{
		$this->collectionListService->expects($this->once())
			->method('listAllCollections')
			->willReturn([]);

		$this->renderer->method('jsonCollection')->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testUsesCollectionMetaTransformer(): void
	{
		$this->collectionListService->method('listAllCollections')->willReturn([]);

		$this->renderer->expects($this->once())
			->method('jsonCollection')
			->with(
				$this->anything(),
				$this->anything(),
				$this->isInstanceOf(CollectionMetaTransformer::class)
			)
			->willReturn($this->response);

		($this->action)($this->request, $this->response);
	}

	public function testHandlesEmptyCollectionList(): void
	{
		$this->collectionListService->method('listAllCollections')->willReturn([]);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonCollection')
			->with($this->response, [], $this->anything())
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testHandlesMultipleCollections(): void
	{
		$collections = [
			$this->createCollectionData('blog'),
			$this->createCollectionData('products'),
			$this->createCollectionData('pages'),
		];

		$this->collectionListService->method('listAllCollections')->willReturn($collections);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonCollection')
			->with($this->response, $collections, $this->anything())
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $result);
	}

	public function testReturnsResponseInterface(): void
	{
		$this->collectionListService->method('listAllCollections')->willReturn([]);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('jsonCollection')->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	private function createCollectionData(string $id): CollectionData
	{
		$collection = new CollectionData();
		$collection->id = $id;
		$collection->name = ucfirst($id);
		$collection->schema = $id;
		$collection->description = "Test {$id} collection";

		return $collection;
	}
}
