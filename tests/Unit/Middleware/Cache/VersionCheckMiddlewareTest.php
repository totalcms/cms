<?php

namespace Tests\Unit\Middleware\Cache;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Middleware\Cache\VersionCheckMiddleware;

final class VersionCheckMiddlewareTest extends TestCase
{
	private VersionCheckMiddleware $middleware;
	private \PHPUnit\Framework\MockObject\MockObject $cacheManager;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $handler;

	protected function setUp(): void
	{
		$this->cacheManager = $this->createMock(CacheManager::class);
		$this->request      = $this->createMock(ServerRequestInterface::class);
		$this->handler      = $this->createMock(RequestHandlerInterface::class);

		$this->middleware = new VersionCheckMiddleware($this->cacheManager);
	}

	public function testCallsClearIfVersionChanged(): void
	{
		$this->cacheManager->expects($this->once())
			->method('clearIfVersionChanged');

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())
			->method('handle')
			->with($this->request)
			->willReturn($expectedResponse);

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($expectedResponse, $result);
	}

	public function testPassesRequestToHandlerWhenVersionUnchanged(): void
	{
		$this->cacheManager->method('clearIfVersionChanged')->willReturn(false);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())
			->method('handle')
			->with($this->request)
			->willReturn($expectedResponse);

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($expectedResponse, $result);
	}

	public function testPassesRequestToHandlerWhenVersionChanged(): void
	{
		$this->cacheManager->method('clearIfVersionChanged')->willReturn(true);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())
			->method('handle')
			->with($this->request)
			->willReturn($expectedResponse);

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($expectedResponse, $result);
	}
}
