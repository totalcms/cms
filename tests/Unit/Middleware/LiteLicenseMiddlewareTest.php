<?php

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Middleware\LiteLicenseMiddleware;

final class LiteLicenseMiddlewareTest extends TestCase
{
	private LiteLicenseMiddleware $middleware;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $handler;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->middleware = new LiteLicenseMiddleware();
		$this->request    = $this->createMock(ServerRequestInterface::class);
		$this->handler    = $this->createMock(RequestHandlerInterface::class);
		$this->response   = $this->createMock(ResponseInterface::class);
	}

	public function testImplementsMiddlewareInterface(): void
	{
		$this->assertInstanceOf(MiddlewareInterface::class, $this->middleware);
	}

	public function testProcessPassesThroughToHandler(): void
	{
		$this->handler->expects($this->once())
			->method('handle')
			->with($this->request)
			->willReturn($this->response);

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertSame($this->response, $result);
	}

	public function testProcessReturnsResponseFromHandler(): void
	{
		$this->handler->method('handle')->willReturn($this->response);

		$result = $this->middleware->process($this->request, $this->handler);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testProcessCallsHandlerWithSameRequest(): void
	{
		$this->handler->expects($this->once())
			->method('handle')
			->with($this->identicalTo($this->request))
			->willReturn($this->response);

		$this->middleware->process($this->request, $this->handler);
	}
}
