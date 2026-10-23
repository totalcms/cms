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
use Psr\Log\NullLogger;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

describe('BaseAccessMiddleware', function (): void {
	beforeEach(function (): void {
		$this->accessControl      = $this->createMock(AccessControlService::class);
		$this->session            = $this->createMock(SessionInterface::class);
		$this->jsonRenderer       = $this->createMock(JsonRenderer::class);
		$this->twigRenderer       = $this->createMock(TwigRenderer::class);
		$this->responseFactory    = $this->createMock(ResponseFactoryInterface::class);
		$this->config             = Config::init();
		$this->operationDetector  = $this->createMock(OperationDetector::class);
		$this->loggerFactory      = $this->createMock(LoggerFactory::class);
		// OAuthActivityLogger is final readonly — construct a real instance
		// backed by a NullLogger instead of createMock().
		$this->oauthActivityLogger = new OAuthActivityLogger(new NullLogger());
		$this->actors              = new ActorContext();
		$this->events              = new EventDispatcher(new NullLogger(), $this->actors);

		$this->handler = $this->createMock(RequestHandlerInterface::class);

		$this->passthroughResponse = $this->createMock(ResponseInterface::class);
		$this->handler->method('handle')->willReturn($this->passthroughResponse);

		// Config fields the middleware reads
		$this->config->auth  = ['enable' => true, 'collection' => 'auth'];
		$this->config->env   = 'prod';
		$this->config->debug = false;

		$this->make = (fn (?\Closure $checkPermission = null): TestableAccessMiddleware => new TestableAccessMiddleware(
			new ActorResolver($this->session, $this->accessControl, $this->config),
			$this->actors,
			$this->jsonRenderer,
			$this->twigRenderer,
			$this->responseFactory,
			$this->config,
			$this->operationDetector,
			$this->loggerFactory,
			new AccessDenials($this->loggerFactory, $this->oauthActivityLogger, $this->events),
			$checkPermission ?? (fn (): bool => true),
		));

		$this->requestFor = function (string $path = '/api/collections/blog'): ServerRequestInterface {
			$uri = $this->createMock(UriInterface::class);
			$uri->method('getPath')->willReturn($path);

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('withAttribute')->willReturnSelf();
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
		$request->method('withAttribute')->willReturnSelf();
		$request->method('getAttribute')->willReturnMap([
			['publicSubmission', null, true],
			['authMethod', null, null],
		]);

		$this->handler->expects($this->once())->method('handle');
		$this->operationDetector->expects($this->never())->method('detectOperation');
		$this->accessControl->expects($this->never())->method('authorityOf');

		$response = ($this->make)()->process($request, $this->handler);
		expect($response)->toBe($this->passthroughResponse);
	});

	test('an anonymous public submission sets the anonymous actor', function (): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnMap([
			['publicSubmission', null, true],
			['authMethod', null, null],
		]);
		$this->session->method('get')->willReturn(null);

		($this->make)()->process($request, $this->handler);

		expect($this->actors->current()->kind)->toBe(ActorKind::Anonymous);
	});

	test('a public submission by a signed-in session user makes that user the actor', function (): void {
		// The storage layer reads the actor to stamp the owner on create.
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnMap([
			['publicSubmission', null, true],
			['authMethod', null, null],
		]);
		$this->session->method('get')->willReturnCallback(
			static fn (string $key) => match ($key) {
				SessionKeys::AUTH_USER       => 'alex',
				SessionKeys::AUTH_COLLECTION => 'members',
				default                      => null,
			},
		);
		$this->accessControl->method('authorityOf')->with('alex', 'members')->willReturn(new UserAuthority(isAdmin: false, groups: []));
		$this->operationDetector->expects($this->never())->method('detectOperation');

		($this->make)()->process($request, $this->handler);

		$actor = $this->actors->current();
		expect($actor->kind)->toBe(ActorKind::Member)
			->and($actor->userId)->toBe('alex')
			->and($actor->authCollection)->toBe('members');
	});

	test('API key auth bypasses group checks', function (): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('withAttribute')->willReturnSelf();
		$request->method('getUri')->willReturn($this->createMock(UriInterface::class));
		$request->method('getAttribute')->willReturnMap([
			['publicSubmission', null, null],
			['authMethod', null, 'apikey'],
		]);

		$this->handler->expects($this->once())->method('handle');
		$this->operationDetector->expects($this->never())->method('detectOperation');

		($this->make)()->process($request, $this->handler);

		expect($this->actors->current()->kind)->toBe(ActorKind::ApiKey);
	});

	// ────────────────────────────────────────────────────────────────────
	// oauth_bearer branch — security review fix round 1, findings 3a/3b.
	// ────────────────────────────────────────────────────────────────────

	test('bearer request resolving to an admin authority bypasses the group check entirely', function (): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('withAttribute')->willReturnSelf();
		$request->method('getUri')->willReturn($this->createMock(UriInterface::class));
		$request->method('getAttribute')->willReturnMap([
			['publicSubmission', null, null],
			['authMethod', null, 'oauth_bearer'],
			['oauth_user_id', '', 'auth:admin-user-id'],
			['oauth_scopes', null, ['cms:write']],
		]);

		$this->accessControl
			->expects($this->once())
			->method('authorityOf')
			->with('admin-user-id', 'auth')
			->willReturn(new UserAuthority(isAdmin: true, groups: []));

		$wasCalled = false;
		$check     = function () use (&$wasCalled): bool {
			$wasCalled = true;

			return false;
		};

		// Admin bypass short-circuits before operation detection or checkPermission.
		$this->operationDetector->expects($this->never())->method('detectOperation');
		$this->handler->expects($this->once())->method('handle');

		$response = ($this->make)($check)->process($request, $this->handler);

		expect($wasCalled)->toBeFalse();
		expect($response)->toBe($this->passthroughResponse);
		expect($this->actors->current()->scopes)->toBe(['cms:write']);
	});

	test('bearer request with missing/empty oauth_user_id is anonymous and fails closed', function (): void {
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getUri')->willReturn($this->createMock(UriInterface::class));
		$request->method('getAttribute')->willReturnMap([
			['publicSubmission', null, null],
			['authMethod', null, 'oauth_bearer'],
			// getAttribute('oauth_user_id', '') — attribute genuinely absent,
			// so the mock falls back to the default: empty string.
			['oauth_user_id', '', ''],
		]);
		$request->method('withAttribute')->willReturnSelf();

		$this->accessControl->expects($this->never())->method('authorityOf');
		$this->handler->expects($this->never())->method('handle');
		$this->jsonRenderer
			->expects($this->once())
			->method('json')
			->with($this->anything(), $this->callback(fn (array $body): bool => $body['error']['message'] === 'Authentication required'));

		($this->make)(fn (): bool => true)->process($request, $this->handler);
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
		$this->session->method('get')->willReturnCallback(
			static fn (string $key): mixed => $key === SessionKeys::AUTH_USER ? 'admin-id' : null,
		);
		$this->accessControl->method('authorityOf')->with('admin-id')->willReturn(new UserAuthority(isAdmin: true, groups: []));

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
		$this->session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? 'user-1' : null);
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));
		$this->operationDetector->method('detectOperation')->willReturn(null);

		$this->loggerFactory->expects($this->never())->method('channelLogger');
		$this->handler->expects($this->never())->method('handle');

		($this->make)()->process(($this->requestFor)(), $this->handler);
	});

	test('failed operation detection logs in dev mode', function (): void {
		$this->config->env = 'dev';

		$this->session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? 'user-1' : null);
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));
		$this->operationDetector->method('detectOperation')->willReturn(null);

		// The dev-mode logger path reads the request's route context
		$route = $this->createMock(RouteInterface::class);
		$route->method('getName')->willReturn('some-route');

		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/api/collections/blog');

		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('withAttribute')->willReturnSelf();
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
		$this->loggerFactory->expects($this->once())->method('channelLogger')->willReturn($logger);

		($this->make)()->process($request, $this->handler);
	});

	test('checkPermission returning false returns 403', function (): void {
		$this->session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? 'user-1' : null);
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));
		$this->operationDetector->method('detectOperation')->willReturn('read');

		$this->handler->expects($this->never())->method('handle');
		$this->jsonRenderer->expects($this->once())->method('json');

		($this->make)(fn (): bool => false)->process(($this->requestFor)(), $this->handler);
	});

	test('a refusal fires access.denied naming the refused actor', function (): void {
		$this->session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? 'user-1' : null);
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));
		$this->operationDetector->method('detectOperation')->willReturn('update');
		$denials = [];
		$this->events->listen(CoreEvent::ACCESS_DENIED, function (array $payload) use (&$denials): void {
			$denials[] = $payload;
		});

		($this->make)(fn (): bool => false)->process(($this->requestFor)(), $this->handler);

		expect($denials)->toHaveCount(1)
			->and($denials[0])->toMatchArray(['operation' => 'update', 'path' => '/api/collections/blog'])
			->and($denials[0]['actor'])->toMatchArray(['kind' => 'member', 'userId' => 'user-1']);
	});

	test('checkPermission returning true lets the request through', function (): void {
		$this->session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? 'user-1' : null);
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));
		$this->operationDetector->method('detectOperation')->willReturn('read');

		$this->handler->expects($this->once())->method('handle');
		$this->jsonRenderer->expects($this->never())->method('json');

		$receivedArgs = [];
		$check        = function (Actor $actor, string $op) use (&$receivedArgs): bool {
			$receivedArgs = [$actor->userId, $op];

			return true;
		};

		($this->make)($check)->process(($this->requestFor)(), $this->handler);

		expect($receivedArgs)->toBe(['user-1', 'read']);
	});

	test('forbidden /admin/ requests get an HTML response via TwigRenderer', function (): void {
		$this->session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? 'user-1' : null);
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));
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
		$this->session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? 'user-1' : null);
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));
		$this->operationDetector->method('detectOperation')->willReturn('read');

		$this->jsonRenderer->expects($this->once())->method('json');
		$this->twigRenderer->expects($this->never())->method('template');

		($this->make)(fn (): bool => false)->process(($this->requestFor)('/api/collections/blog'), $this->handler);
	});

	test('error message includes the resource name in dev mode', function (): void {
		$this->config->env = 'dev';

		$this->session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? 'user-1' : null);
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));
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
		$this->session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === SessionKeys::AUTH_USER ? 'user-1' : null);
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));
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
