<?php

namespace Tests\Unit\Action\Cache;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Cache\CacheDeleteAction;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Renderer\JsonRenderer;

final class CacheDeleteActionTest extends TestCase
{
	private CacheDeleteAction $action;
	private CacheManager $cacheManager;
	private JsonRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->cacheManager = $this->createMock(CacheManager::class);
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->request = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new CacheDeleteAction(
			$this->cacheManager,
			$this->renderer
		);
	}

	public function testClearsAllCachesSuccessfully(): void
	{
		$result = [
			'success' => true,
			'cleared' => ['twig', 'redis', 'apcu'],
		];

		$this->cacheManager->expects($this->once())
			->method('clearAllCaches')
			->willReturn($result);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['deleted' => $result])
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $actualResponse);
	}

	public function testReturns500WhenCacheClearFails(): void
	{
		$result = [
			'success' => false,
			'error' => 'Failed to clear cache',
		];

		$this->cacheManager->expects($this->once())
			->method('clearAllCaches')
			->willReturn($result);

		$errorResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($errorResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with($errorResponse, ['deleted' => $result])
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $actualResponse);
	}

	public function testReturns500WhenSuccessKeyMissing(): void
	{
		$result = [
			'cleared' => [],
		];

		$this->cacheManager->expects($this->once())
			->method('clearAllCaches')
			->willReturn($result);

		$errorResponse = $this->createMock(ResponseInterface::class);
		$this->response->expects($this->once())
			->method('withStatus')
			->with(500)
			->willReturn($errorResponse);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with($errorResponse, ['deleted' => $result])
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $actualResponse);
	}

	public function testHandlesPartialSuccess(): void
	{
		$result = [
			'success' => true,
			'cleared' => ['twig'],
			'failed' => ['redis' => 'Connection failed'],
		];

		$this->cacheManager->expects($this->once())
			->method('clearAllCaches')
			->willReturn($result);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['deleted' => $result])
			->willReturn($jsonResponse);

		$actualResponse = ($this->action)($this->request, $this->response);

		$this->assertSame($jsonResponse, $actualResponse);
	}
}
