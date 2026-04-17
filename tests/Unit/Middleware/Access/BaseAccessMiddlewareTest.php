<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

describe('BaseAccessMiddleware', function (): void {
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

		$this->handler = $this->createMock(RequestHandlerInterface::class);

		$this->passthroughResponse = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($this->passthroughResponse);

		// Config fields the middleware reads
		$this->config->auth  = ['enable' => true];
		$this->config->env   = 'prod';
		$this->config->debug = false;

		$this->make = (fn (?\Closure $checkPermission = null): TestableAccessMiddleware => new TestableAccessMiddleware(
			$this->userValidation,
			$this->accessControl,
			$this->session,
			$this->jsonRenderer,
			$this->twigRenderer,
			$this->responseFactory,
			$this->config,
			$this->operationDetector,
			$this->loggerFactory,
			$checkPermission ?? (fn (): bool => true),
		));

		$this->requestFor = function (string $path = '/api/collections/blog'): ServerRequestInterface {
			$uri = $this->createMock(UriInterface::class);
			$uri->method('getPath')->willReturn($path);

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getUri')->willReturn($uri);
			$request->method('getMethod')->willReturn('GET');
			$request->method('getAttribute')->willReturnMap([
				['publicSubmission', null, null],
				['authMethod', null, null],
			]);

			return $request;
		};

		$this->forbiddenResponse = $this->createMock(ResponseInterface::class);
		$this->forbiddenResponse->method('withStatus')->willReturnSelf();

		$this->responseFactory->method('createResponse')->willReturn($this->forbiddenResponse);
		$this->jsonRenderer->method('json')->willReturn($this->forbiddenResponse);
		$this->twigRenderer->method('template')->willReturn($this->forbiddenResponse);
	});

	test('when auth.enable is false, passes through to the handler', function (): void {
		$this->config->auth = ['enable' => false];

		$this->handler->expects($this->once())->method('handle');
		$this->session->expects($this->never())->method('get');

		$response = ($this->make)()->process(($this->requestFor)(), $this->handler);

		expect($response)->toBe($this->passthroughResponse);
	});

	test('publicSubmission attribute bypasses access control', function (): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnMap([
			['publicSubmission', null, true],
			['authMethod', null, null],
		]);

		$this->handler->expects($this->once())->method('handle');
		$this->session->expects($this->never())->method('get');

		$response = ($this->make)()->process($request, $this->handler);
		expect($response)->toBe($this->passthroughResponse);
	});

	test('API key auth bypasses group checks', function (): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($this->createMock(UriInterface::class));
		$request->method('getAttribute')->willReturnMap([
			['publicSubmission', null, null],
			['authMethod', null, 'apikey'],
		]);

		$this->handler->expects($this->once())->method('handle');
		$this->operationDetector->expects($this->never())->method('detectOperation');

		($this->make)()->process($request, $this->handler);
	});

	test('missing session user returns 403 with "Authentication required"', function (): void {
		$this->session->method('get')->with(SessionKeys::AUTH_USER)->willReturn(null);

		$this->forbiddenResponse->expects($this->once())->method('withStatus')->with(403);
		$this->jsonRenderer
			->expects($this->once())
			->method('json')
			->with($this->anything(), $this->callback(fn (array $body): bool => $body['error']['message'] === 'Authentication required'));

		$this->handler->expects($this->never())->method('handle');

		($this->make)()->process(($this->requestFor)(), $this->handler);
	});

	test('super admin bypasses checkPermission entirely', function (): void {
		$this->session->method('get')->with(SessionKeys::AUTH_USER)->willReturn('admin-id');
		$this->userValidation->method('isSuperAdmin')->with('admin-id')->willReturn(true);

		$wasCalled = false;
		$check     = function () use (&$wasCalled): bool {
			$wasCalled = true;

			return false;
		};

		$this->handler->expects($this->once())->method('handle');
		$this->operationDetector->expects($this->never())->method('detectOperation');

		($this->make)($check)->process(($this->requestFor)(), $this->handler);

		expect($wasCalled)->toBeFalse();
	});

	test('failed operation detection denies access (no logging in prod)', function (): void {
		$this->session->method('get')->willReturn('user-1');
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->operationDetector->method('detectOperation')->willReturn(null);

		$this->loggerFactory->expects($this->never())->method('addFileHandler');
		$this->handler->expects($this->never())->method('handle');

		($this->make)()->process(($this->requestFor)(), $this->handler);
	});

	test('failed operation detection logs in dev mode', function (): void {
		$this->config->env = 'dev';

		$this->session->method('get')->willReturn('user-1');
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->operationDetector->method('detectOperation')->willReturn(null);

		// The dev-mode logger path reads the request's route context
		$route = $this->createMock(RouteInterface::class);
		$route->method('getName')->willReturn('some-route');

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/collections/blog');

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

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$this->loggerFactory->expects($this->once())->method('addFileHandler')->willReturnSelf();
		$this->loggerFactory->expects($this->once())->method('createLogger')->willReturn($logger);

		($this->make)()->process($request, $this->handler);
	});

	test('checkPermission returning false returns 403', function (): void {
		$this->session->method('get')->willReturn('user-1');
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->operationDetector->method('detectOperation')->willReturn('read');

		$this->handler->expects($this->never())->method('handle');
		$this->jsonRenderer->expects($this->once())->method('json');

		($this->make)(fn (): bool => false)->process(($this->requestFor)(), $this->handler);
	});

	test('checkPermission returning true lets the request through', function (): void {
		$this->session->method('get')->willReturn('user-1');
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->operationDetector->method('detectOperation')->willReturn('read');

		$this->handler->expects($this->once())->method('handle');
		$this->jsonRenderer->expects($this->never())->method('json');

		$receivedArgs = [];
		$check        = function (string $userId, string $op) use (&$receivedArgs): bool {
			$receivedArgs = [$userId, $op];

			return true;
		};

		($this->make)($check)->process(($this->requestFor)(), $this->handler);

		expect($receivedArgs)->toBe(['user-1', 'read']);
	});

	test('forbidden /admin/ requests get an HTML response via TwigRenderer', function (): void {
		$this->session->method('get')->willReturn('user-1');
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->operationDetector->method('detectOperation')->willReturn('read');

		$this->twigRenderer
			->expects($this->once())
			->method('template')
			->with($this->anything(), 'access-denied.twig', $this->anything())
			->willReturn($this->forbiddenResponse);
		$this->jsonRenderer->expects($this->never())->method('json');

		($this->make)(fn (): bool => false)->process(($this->requestFor)('/admin/schemas'), $this->handler);
	});

	test('forbidden non-admin requests get a JSON response', function (): void {
		$this->session->method('get')->willReturn('user-1');
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->operationDetector->method('detectOperation')->willReturn('read');

		$this->jsonRenderer->expects($this->once())->method('json');
		$this->twigRenderer->expects($this->never())->method('template');

		($this->make)(fn (): bool => false)->process(($this->requestFor)('/api/collections/blog'), $this->handler);
	});

	test('error message includes the resource name in dev mode', function (): void {
		$this->config->env = 'dev';

		$this->session->method('get')->willReturn('user-1');
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->operationDetector->method('detectOperation')->willReturn('read');

		$this->jsonRenderer
			->expects($this->once())
			->method('json')
			->with(
				$this->anything(),
				$this->callback(fn (array $body): bool => str_contains((string)$body['error']['message'], 'widget')),
			);

		($this->make)(fn (): bool => false)->process(($this->requestFor)(), $this->handler);
	});

	test('error message is generic "Access denied" in prod mode', function (): void {
		$this->session->method('get')->willReturn('user-1');
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->operationDetector->method('detectOperation')->willReturn('read');

		$this->jsonRenderer
			->expects($this->once())
			->method('json')
			->with(
				$this->anything(),
				$this->callback(fn (array $body): bool => $body['error']['message'] === 'Access denied'),
			);

		($this->make)(fn (): bool => false)->process(($this->requestFor)(), $this->handler);
	});
});
