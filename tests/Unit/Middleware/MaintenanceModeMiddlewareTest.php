<?php

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Update\Service\MaintenanceMode;
use TotalCMS\Middleware\MaintenanceModeMiddleware;

final class MaintenanceModeMiddlewareTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $maintenanceMode;
	private \PHPUnit\Framework\MockObject\MockObject $handler;
	private MaintenanceModeMiddleware $middleware;

	protected function setUp(): void
	{
		$this->maintenanceMode = $this->createMock(MaintenanceMode::class);
		$this->handler         = $this->createMock(RequestHandlerInterface::class);
		$this->middleware      = new MaintenanceModeMiddleware($this->maintenanceMode);
	}

	public function testPassesThroughWhenNotInMaintenance(): void
	{
		$this->maintenanceMode->method('isEnabled')->willReturn(false);

		$request          = $this->createRequest('/api/data');
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($expectedResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($expectedResponse, $result);
	}

	public function testReturns503WhenInMaintenance(): void
	{
		$this->maintenanceMode->method('isEnabled')->willReturn(true);

		$request = $this->createRequest('/api/data');

		$this->handler->expects($this->never())->method('handle');

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame(503, $result->getStatusCode());
		$this->assertSame('text/html', $result->getHeaderLine('Content-Type'));
		$this->assertSame('60', $result->getHeaderLine('Retry-After'));

		$body = (string) $result->getBody();
		$this->assertStringContainsString('Updating Total CMS', $body);
	}

	public function testAllowsAdminRoutesDuringMaintenance(): void
	{
		$this->maintenanceMode->method('isEnabled')->willReturn(true);

		$request          = $this->createRequest('/admin/utils/update');
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($expectedResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($expectedResponse, $result);
	}

	public function testAllowsSetupRoutesDuringMaintenance(): void
	{
		$this->maintenanceMode->method('isEnabled')->willReturn(true);

		$request          = $this->createRequest('/setup/environment');
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($expectedResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($expectedResponse, $result);
	}

	public function testBlocksPublicRoutesDuringMaintenance(): void
	{
		$this->maintenanceMode->method('isEnabled')->willReturn(true);

		$request = $this->createRequest('/blog/my-post');

		$this->handler->expects($this->never())->method('handle');

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame(503, $result->getStatusCode());
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
