<?php

namespace Tests\Unit\Action\Collection;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Action\Collection\CollectionExistsAction;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;

final class CollectionExistsActionTest extends TestCase
{
	private CollectionExistsAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->request           = $this->createMock(ServerRequestInterface::class);
		$this->response          = $this->createMock(ResponseInterface::class);

		$this->action = new CollectionExistsAction($this->collectionFetcher);
	}

	public function testReturnsResponseWhenCollectionExists(): void
	{
		$this->collectionFetcher->expects($this->once())
			->method('collectionExists')
			->with('blog')
			->willReturn(true);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'blog']);

		$this->assertSame($this->response, $result);
	}

	public function testThrowsNotFoundWhenCollectionDoesNotExist(): void
	{
		$this->collectionFetcher->expects($this->once())
			->method('collectionExists')
			->with('nonexistent')
			->willReturn(false);

		$this->expectException(HttpNotFoundException::class);
		$this->expectExceptionMessage('Collection not found');

		($this->action)($this->request, $this->response, ['collection' => 'nonexistent']);
	}

	public function testChecksCollectionFromArgs(): void
	{
		$this->collectionFetcher->expects($this->once())
			->method('collectionExists')
			->with('products')
			->willReturn(true);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testReturnsOriginalResponse(): void
	{
		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'blog']);

		$this->assertInstanceOf(ResponseInterface::class, $result);
		$this->assertSame($this->response, $result);
	}

	public function testHandlesMultipleCollectionChecks(): void
	{
		$this->collectionFetcher->method('collectionExists')->willReturn(true);

		$result1 = ($this->action)($this->request, $this->response, ['collection' => 'blog']);
		$result2 = ($this->action)($this->request, $this->response, ['collection' => 'products']);

		$this->assertSame($this->response, $result1);
		$this->assertSame($this->response, $result2);
	}
}
