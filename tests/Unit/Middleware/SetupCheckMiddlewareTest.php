<?php

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Middleware\SetupCheckMiddleware;
use TotalCMS\Renderer\RedirectRenderer;
use TotalCMS\Support\Config;

final class SetupCheckMiddlewareTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private \PHPUnit\Framework\MockObject\MockObject $redirectRenderer;
	private \PHPUnit\Framework\MockObject\MockObject $setupState;
	private \PHPUnit\Framework\MockObject\MockObject $handler;
	private SetupCheckMiddleware $middleware;

	protected function setUp(): void
	{
		$this->config           = $this->createMock(Config::class);
		$this->redirectRenderer = $this->createMock(RedirectRenderer::class);
		$this->setupState       = $this->createMock(SetupStateManager::class);
		$this->handler          = $this->createMock(RequestHandlerInterface::class);

		$this->setupState->method('getCurrentStep')->willReturn('setup-welcome');
		$this->setupState->method('isStepComplete')->willReturn(false);

		$this->middleware = new SetupCheckMiddleware(
			$this->config,
			$this->redirectRenderer,
			$this->setupState,
		);
	}

	public function testSkipsSetupCheckInPreviewEnvironment(): void
	{
		$this->config->env = 'preview';

		$request          = $this->createRequestWithRoute('admin-index', '/admin');
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($expectedResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($expectedResponse, $result);
	}

	public function testAllowsSetupRoutes(): void
	{
		$this->config->env     = 'prod';
		$this->config->datadir = '';

		$request          = $this->createRequestWithRoute('setup-welcome', '/setup/data-path');
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($expectedResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($expectedResponse, $result);
	}

	public function testAllowsPublicAssetRoute(): void
	{
		$this->config->env     = 'prod';
		$this->config->datadir = '';

		$request          = $this->createRequestWithRoute('public-asset', '/public/asset');
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($expectedResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($expectedResponse, $result);
	}

	public function testRedirectsLoginToWizardWhenSetupIncomplete(): void
	{
		$this->config->env     = 'prod';
		$this->config->datadir = sys_get_temp_dir(); // exists but no auth collection
		$this->config->auth    = ['collection' => 'nonexistent-auth-' . uniqid()];

		$request       = $this->createRequestWithRoute('login', '/login[/{collection}]');
		$setupResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->never())
			->method('handle');

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-welcome')
			->willReturn($setupResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($setupResponse, $result);
	}

	public function testRedirectsToSetupWhenNoDataDir(): void
	{
		$this->config->env     = 'prod';
		$this->config->datadir = '/nonexistent/path/tcms-data';
		$this->config->auth    = ['collection' => 'auth'];

		$request       = $this->createRequestWithRoute('admin-index', '/admin');
		$setupResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->never())
			->method('handle');

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-welcome')
			->willReturn($setupResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($setupResponse, $result);
	}

	public function testRedirectsToSetupWhenDataDirExistsButNoAuthCollection(): void
	{
		$this->config->env     = 'prod';
		$this->config->datadir = sys_get_temp_dir(); // exists
		$this->config->auth    = ['collection' => 'nonexistent-auth-' . uniqid()];

		// Non-login route should redirect to setup
		$request       = $this->createRequestWithRoute('admin-index', '/admin');
		$setupResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->never())
			->method('handle');

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'setup-welcome')
			->willReturn($setupResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($setupResponse, $result);
	}

	public function testAllowsNormalAccessWhenAuthCollectionExists(): void
	{
		// Create a temp auth directory
		$tempDir  = sys_get_temp_dir() . '/tcms-test-' . uniqid();
		$authDir  = $tempDir . '/auth';
		mkdir($authDir, 0755, true);

		try {
			$this->config->env     = 'prod';
			$this->config->datadir = $tempDir;
			$this->config->auth    = ['collection' => 'auth'];

			$request          = $this->createRequestWithRoute('admin-index', '/admin');
			$expectedResponse = $this->createMock(ResponseInterface::class);

			$this->handler->expects($this->once())
				->method('handle')
				->willReturn($expectedResponse);

			$result = $this->middleware->process($request, $this->handler);

			$this->assertSame($expectedResponse, $result);
		} finally {
			rmdir($authDir);
			rmdir($tempDir);
		}
	}

	/**
	 * Create a mock request with route context attributes set.
	 */
	private function createRequestWithRoute(?string $routeName, string $routePattern): ServerRequestInterface
	{
		$route = $this->createMock(RouteInterface::class);
		$route->method('getName')->willReturn($routeName);
		$route->method('getPattern')->willReturn($routePattern);

		$routeParser    = $this->createMock(RouteParserInterface::class);
		$routingResults = $this->createMock(RoutingResults::class);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')
			->willReturnMap([
				[RouteContext::ROUTE, null, $route],
				[RouteContext::ROUTE_PARSER, null, $routeParser],
				[RouteContext::ROUTING_RESULTS, null, $routingResults],
				[RouteContext::BASE_PATH, null, null],
			]);

		return $request;
	}
}
