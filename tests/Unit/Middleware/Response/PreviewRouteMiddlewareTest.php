<?php

namespace Tests\Unit\Middleware\Response;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Middleware\Response\PreviewRouteMiddleware;

final class PreviewRouteMiddlewareTest extends TestCase
{
	private Psr17Factory $factory;

	protected function setUp(): void
	{
		$this->factory = new Psr17Factory();
	}

	public function testProcessPassesThroughWithoutApiPath(): void
	{
		$middleware = new PreviewRouteMiddleware('tcms/public');

		$request  = $this->factory->createServerRequest('GET', '/some/path');
		$response = $this->factory->createResponse();

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->willReturn($response);

		$result = $middleware->process($request, $handler);

		$this->assertSame($response, $result);
	}

	public function testProcessExtractsRouteFromApiPath(): void
	{
		$middleware = new PreviewRouteMiddleware('tcms/public');

		$request  = $this->factory->createServerRequest('GET', '/tcms/public/collections/blog');
		$response = $this->factory->createResponse();

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->with($this->callback(fn (ServerRequestInterface $req): bool => $req->getUri()->getPath() === '/collections/blog'))
			->willReturn($response);

		$middleware->process($request, $handler);
	}

	public function testProcessHandlesEmptyApiPath(): void
	{
		$middleware = new PreviewRouteMiddleware('');

		$request  = $this->factory->createServerRequest('GET', '/path');
		$response = $this->factory->createResponse();

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->willReturn($response);

		$result = $middleware->process($request, $handler);

		$this->assertSame($response, $result);
	}

	public function testProcessWithCustomApiPath(): void
	{
		$middleware = new PreviewRouteMiddleware('api/v1');

		$request  = $this->factory->createServerRequest('GET', '/api/v1/users/123');
		$response = $this->factory->createResponse();

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->expects($this->once())
			->method('handle')
			->with($this->callback(fn (ServerRequestInterface $req): bool => $req->getUri()->getPath() === '/users/123'))
			->willReturn($response);

		$middleware->process($request, $handler);
	}

	public function testProcessReturnsHandlerResponse(): void
	{
		$middleware = new PreviewRouteMiddleware('tcms/public');

		$request          = $this->factory->createServerRequest('GET', '/tcms/public/test');
		$expectedResponse = $this->factory->createResponse(201);

		$handler = $this->createMock(RequestHandlerInterface::class);
		$handler->method('handle')->willReturn($expectedResponse);

		$result = $middleware->process($request, $handler);

		$this->assertSame(201, $result->getStatusCode());
	}
}
