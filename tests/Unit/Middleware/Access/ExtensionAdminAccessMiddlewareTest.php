<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Extension\Data\ExtensionRoute;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\Access\ExtensionAdminAccessMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/*
 * /admin/ext/{vendor}/{name}/{path} previously had NO authorization — any
 * logged-in dashboard user could open any extension admin page by URL; the
 * AdminNavItem permission only hid the link. These tests pin the fix:
 * matched routes default to admin-only, permission 'any' opts a page open
 * to every logged-in user, unmatched paths fall through to the dispatcher's
 * 404, and super admins bypass.
 */

describe('ExtensionAdminAccessMiddleware', function (): void {
	beforeEach(function (): void {
		$this->userValidation    = $this->createMock(UserValidationService::class);
		$this->accessControl     = $this->createMock(AccessControlService::class);
		$this->session           = $this->createMock(SessionInterface::class);
		$this->jsonRenderer      = $this->createMock(JsonRenderer::class);
		$this->twigRenderer      = $this->createMock(TwigRenderer::class);
		$this->responseFactory   = $this->createMock(ResponseFactoryInterface::class);
		$this->config            = Config::init();
		$this->operationDetector = $this->createMock(OperationDetector::class);
		$this->loggerFactory     = $this->createMock(LoggerFactory::class);
		$this->extensionManager  = $this->createMock(ExtensionManager::class);

		$this->handler             = $this->createMock(RequestHandlerInterface::class);
		$this->passthroughResponse = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($this->passthroughResponse);

		$this->config->auth  = ['enable' => true];
		$this->config->env   = 'prod';
		$this->config->debug = false;

		$this->forbiddenResponse = $this->createMock(ResponseInterface::class);
		$this->forbiddenResponse->method('withStatus')->willReturnSelf();
		$this->responseFactory->method('createResponse')->willReturn($this->forbiddenResponse);
		$this->jsonRenderer->method('json')->willReturn($this->forbiddenResponse);
		$this->twigRenderer->method('template')->willReturn($this->forbiddenResponse);

		$this->make = fn (): ExtensionAdminAccessMiddleware => new ExtensionAdminAccessMiddleware(
			$this->userValidation,
			$this->accessControl,
			$this->session,
			$this->jsonRenderer,
			$this->twigRenderer,
			$this->responseFactory,
			$this->config,
			$this->operationDetector,
			$this->loggerFactory,
			$this->extensionManager,
		);

		// Request for /admin/ext/test-vendor/hello-world/{path} with route args
		$this->requestFor = function (string $path = 'dashboard'): ServerRequestInterface {
			$uri = $this->createMock(UriInterface::class);
			$uri->method('getPath')->willReturn("/admin/ext/test-vendor/hello-world/{$path}");

			$route = $this->createMock(RouteInterface::class);
			$route->method('getArguments')->willReturn([
				'vendor' => 'test-vendor',
				'name'   => 'hello-world',
				'path'   => $path,
			]);

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getUri')->willReturn($uri);
			$request->method('getMethod')->willReturn('GET');
			$request->method('getAttribute')->willReturnMap([
				['publicSubmission', null, null],
				['authMethod', null, null],
				[RouteContext::ROUTE, null, $route],
				[RouteContext::ROUTE_PARSER, null, $this->createMock(RouteParserInterface::class)],
				[RouteContext::ROUTING_RESULTS, null, $this->createMock(RoutingResults::class)],
				[RouteContext::BASE_PATH, null, null],
			]);

			return $request;
		};

		// Logged-in non-admin user by default
		$this->session->method('get')->with(SessionKeys::AUTH_USER)->willReturn('regular-user');
		$this->extensionManager->method('isEnabled')->willReturn(true);
	});

	test('denies a non-admin on a default (admin) extension route', function (): void {
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->extensionManager->method('matchExtensionAdminRoute')
			->with('test-vendor/hello-world', 'GET', '/dashboard')
			->willReturn(new ExtensionRoute(handler: fn () => null, permission: 'admin'));

		$this->handler->expects($this->never())->method('handle');
		$this->forbiddenResponse->expects($this->once())->method('withStatus')->with(403);

		$response = ($this->make)()->process(($this->requestFor)('dashboard'), $this->handler);

		expect($response)->toBe($this->forbiddenResponse);
	});

	test('allows a non-admin on a permission-any extension route when their group grants the extension', function (): void {
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->extensionManager->method('matchExtensionAdminRoute')
			->with('test-vendor/hello-world', 'GET', '/everyone')
			->willReturn(new ExtensionRoute(handler: fn () => null, permission: 'any'));
		$this->accessControl->method('canAccessExtension')
			->with('regular-user', 'test-vendor/hello-world')
			->willReturn(true);

		$this->handler->expects($this->once())->method('handle');

		$response = ($this->make)()->process(($this->requestFor)('everyone'), $this->handler);

		expect($response)->toBe($this->passthroughResponse);
	});

	test('denies a permission-any extension route when the group does not grant the extension', function (): void {
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->extensionManager->method('matchExtensionAdminRoute')
			->willReturn(new ExtensionRoute(handler: fn () => null, permission: 'any'));
		$this->accessControl->method('canAccessExtension')->willReturn(false);

		$this->handler->expects($this->never())->method('handle');
		$this->forbiddenResponse->expects($this->once())->method('withStatus')->with(403);

		$response = ($this->make)()->process(($this->requestFor)('everyone'), $this->handler);

		expect($response)->toBe($this->forbiddenResponse);
	});

	test('super admins bypass the permission check entirely', function (): void {
		$this->userValidation->method('isSuperAdmin')->willReturn(true);
		$this->extensionManager->expects($this->never())->method('matchExtensionAdminRoute');

		$this->handler->expects($this->once())->method('handle');

		$response = ($this->make)()->process(($this->requestFor)('dashboard'), $this->handler);

		expect($response)->toBe($this->passthroughResponse);
	});

	test('unmatched paths pass through so the dispatcher can 404', function (): void {
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->extensionManager->method('matchExtensionAdminRoute')->willReturn(null);

		$this->handler->expects($this->once())->method('handle');

		$response = ($this->make)()->process(($this->requestFor)('no-such-page'), $this->handler);

		expect($response)->toBe($this->passthroughResponse);
	});

	test('disabled extensions pass through so the dispatcher can 404', function (): void {
		// Rebuild the manager mock with isEnabled => false
		$this->extensionManager = $this->createMock(ExtensionManager::class);
		$this->extensionManager->method('isEnabled')->willReturn(false);
		$this->extensionManager->expects($this->never())->method('matchExtensionAdminRoute');
		$this->userValidation->method('isSuperAdmin')->willReturn(false);

		$this->handler->expects($this->once())->method('handle');

		$response = ($this->make)()->process(($this->requestFor)('dashboard'), $this->handler);

		expect($response)->toBe($this->passthroughResponse);
	});
});
