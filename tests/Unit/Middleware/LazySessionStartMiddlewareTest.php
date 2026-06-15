<?php

namespace Tests\Unit\Middleware;

use Odan\Session\SessionManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Middleware\LazySessionStartMiddleware;

final class LazySessionStartMiddlewareTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $session;
	private \PHPUnit\Framework\MockObject\MockObject $handler;
	private LazySessionStartMiddleware $middleware;

	protected function setUp(): void
	{
		$this->session    = $this->createMock(SessionManagerInterface::class);
		$this->handler    = $this->createMock(RequestHandlerInterface::class);
		$this->middleware = new LazySessionStartMiddleware($this->session);
	}

	public function testSkipsSessionForImageworksPath(): void
	{
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($response);

		// The whole point of the perf fix: no session lifecycle on the image path.
		$this->session->expects($this->never())->method('start');
		$this->session->expects($this->never())->method('save');

		$result = $this->middleware->process($this->createRequest('/imageworks/blog/cover.jpg'), $this->handler);

		$this->assertSame($response, $result);
	}

	public function testSkipsSessionForImageworksUnderSubPath(): void
	{
		// This middleware runs outside BasePathMiddleware, so the path can carry
		// a sub-path prefix when T3 is installed in a sub-directory.
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($response);

		$this->session->expects($this->never())->method('start');
		$this->session->expects($this->never())->method('save');

		$this->middleware->process($this->createRequest('/mysite/imageworks/photo.png'), $this->handler);
	}

	public function testStartsAndSavesSessionForNormalPath(): void
	{
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($response);

		$this->session->method('isStarted')->willReturn(false);
		$this->session->expects($this->once())->method('start');
		$this->session->expects($this->once())->method('save');

		$result = $this->middleware->process($this->createRequest('/admin/dashboard'), $this->handler);

		$this->assertSame($response, $result);
	}

	public function testDoesNotRestartAnAlreadyStartedSession(): void
	{
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->once())->method('handle')->willReturn($response);

		$this->session->method('isStarted')->willReturn(true);
		$this->session->expects($this->never())->method('start');
		// save() still runs so the handler's writes are persisted.
		$this->session->expects($this->once())->method('save');

		$this->middleware->process($this->createRequest('/admin/dashboard'), $this->handler);
	}

	public function testDoesNotTreatNonImageworksPathAsSessionless(): void
	{
		// Guard against an over-broad regex: a path that merely contains the
		// substring elsewhere must still get a session.
		$response = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($response);

		$this->session->method('isStarted')->willReturn(false);
		$this->session->expects($this->once())->method('start');
		$this->session->expects($this->once())->method('save');

		$this->middleware->process($this->createRequest('/admin/imageworks-settings'), $this->handler);
	}

	private function createRequest(string $path): ServerRequestInterface
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn($path);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);

		return $request;
	}
}
