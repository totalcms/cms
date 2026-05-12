<?php

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
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

		$request          = $this->createRequest('/admin');
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

		$request          = $this->createRequest('/setup/data-path');
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

		$request          = $this->createRequest('/api/assets/admin.css');
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

		$request       = $this->createRequest('/login');
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

		$request       = $this->createRequest('/admin');
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
		$request       = $this->createRequest('/admin');
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

	public function testAllowsNormalAccessWhenSetupComplete(): void
	{
		$this->config->env = 'prod';
		$this->setupState->method('isSetupComplete')->willReturn(true);

		$request          = $this->createRequest('/admin');
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($expectedResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($expectedResponse, $result);
	}

	public function testRedirectsSetupRouteToAdminWhenSetupComplete(): void
	{
		$this->config->env = 'prod';
		$this->setupState->method('isSetupComplete')->willReturn(true);

		$request       = $this->createRequest('/setup');
		$adminResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->never())
			->method('handle');

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'admin-index')
			->willReturn($adminResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($adminResponse, $result);
	}

	public function testRedirectsServerConfigStepToAdminWhenSetupComplete(): void
	{
		// Catches the case where a returning user types /setup/server-config directly
		$this->config->env = 'prod';
		$this->setupState->method('isSetupComplete')->willReturn(true);

		$request       = $this->createRequest('/setup/server-config');
		$adminResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->never())
			->method('handle');

		$this->redirectRenderer->expects($this->once())
			->method('redirectFor')
			->with($this->anything(), 'admin-index')
			->willReturn($adminResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($adminResponse, $result);
	}

	public function testAllowsAssetLikeAndApiPathsThroughEvenWhenSetupIncomplete(): void
	{
		// Pre-setup, the wizard should ONLY catch page-navigation requests.
		// Asset-like paths (anything with a file extension) and API paths
		// must fall through to routing so they 404 (or 401) naturally —
		// otherwise every unrouted /css/, /js/, /api/ request 302s to the
		// wizard, which breaks browser asset loading and confuses API
		// clients reaching pre-setup endpoints.
		$this->config->env = 'prod';
		$this->setupState->method('isSetupComplete')->willReturn(false);

		$passthroughPaths = [
			'/css/test.css',
			'/js/test.js',
			'/images/test.png',
			'/favicon.ico',
			'/api/something',
		];

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->handler->expects($this->exactly(count($passthroughPaths)))
			->method('handle')
			->willReturn($expectedResponse);
		$this->redirectRenderer->expects($this->never())->method('redirectFor');

		foreach ($passthroughPaths as $path) {
			$result = $this->middleware->process($this->createRequest($path), $this->handler);
			$this->assertSame($expectedResponse, $result);
		}
	}

	public function testAllowsAccessToLaterSetupStepsAfterAccountCreation(): void
	{
		// Regression: creating the admin account creates the auth collection,
		// but the wizard still has license + server-config steps to go.
		// Middleware must NOT bounce mid-wizard requests to /admin just
		// because the auth collection now exists.
		$this->config->env = 'prod';
		$this->setupState->method('isSetupComplete')->willReturn(false);

		$request          = $this->createRequest('/setup/license');
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->handler->expects($this->once())
			->method('handle')
			->willReturn($expectedResponse);

		$result = $this->middleware->process($request, $this->handler);

		$this->assertSame($expectedResponse, $result);
	}

	/**
	 * Create a mock request whose URI returns the given path.
	 *
	 * The middleware now runs before Slim's RoutingMiddleware, so it inspects
	 * the URL path directly instead of looking at route names — these mocks
	 * only need to expose getUri()->getPath().
	 */
	private function createRequest(string $path): ServerRequestInterface
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn($path);

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($uri);

		return $request;
	}
}
