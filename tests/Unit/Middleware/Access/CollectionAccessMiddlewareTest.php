<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware\Access;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\Access\CollectionAccessMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/*
 * A user must always be able to write their OWN record in their auth
 * collection, whatever their access groups grant.
 *
 * The carve-out compared the route's collection against the raw
 * AUTH_COLLECTION session value. That value is EMPTY for anyone who logged in
 * at the plain `/admin/login` route — it has no `{collection}` argument, and
 * AuthLoginSubmitAction stored `$args['collection'] ?? ''`. So the comparison
 * was `'auth' === ''` and the carve-out never fired: a non-admin saving their
 * own profile fell through to the group check and got 403. Super-admins never
 * saw it, they bypass earlier in BaseAccessMiddleware.
 */
describe('CollectionAccessMiddleware self-profile carve-out', function (): void {
	beforeEach(function (): void {
		$this->accessControl     = $this->createMock(AccessControlService::class);
		$this->session           = $this->createMock(SessionInterface::class);
		$this->operationDetector = $this->createMock(OperationDetector::class);

		$this->config             = Config::init();
		$this->config->auth       = ['enable' => true, 'collection' => 'auth'];
		$this->config->env        = 'prod';
		$this->config->debug      = false;

		$responseFactory = $this->createMock(ResponseFactoryInterface::class);
		$forbidden       = $this->createMock(ResponseInterface::class);
		$forbidden->method('withStatus')->willReturnSelf();
		$responseFactory->method('createResponse')->willReturn($forbidden);

		$jsonRenderer = $this->createMock(JsonRenderer::class);
		$jsonRenderer->method('json')->willReturn($forbidden);
		$twigRenderer = $this->createMock(TwigRenderer::class);
		$twigRenderer->method('template')->willReturn($forbidden);

		$this->forbidden = $forbidden;
		$this->allowed   = $this->createMock(ResponseInterface::class);

		$this->handler = $this->createMock(RequestHandlerInterface::class);
		$this->handler->method('handle')->willReturn($this->allowed);

		// Capture what the access channel is told when a request is refused.
		$this->logged        = new \ArrayObject();
		$this->accessLogger  = new class($this->logged) extends AbstractLogger {
			public function __construct(private \ArrayObject $sink)
			{
			}

			public function log($level, string|\Stringable $message, array $context = []): void
			{
				$this->sink[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};
		$this->loggerFactory = $this->createMock(LoggerFactory::class);
		$this->loggerFactory->method('channelLogger')->willReturn($this->accessLogger);

		$ownerProperties = $this->createMock(OwnerPropertyResolver::class);
		$ownerProperties->method('resolve')->willReturn('');

		// No protected properties: the field policy never refuses here.
		$schemaFetcher = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchemaForCollection')->willReturn(new SchemaData());
		$guard  = new AccessGuard($schemaFetcher, $this->createMock(ObjectFetcher::class), $ownerProperties, $this->config, $this->loggerFactory);

		$this->actors     = new ActorContext();
		$this->middleware = new CollectionAccessMiddleware(
			new ActorResolver($this->session, $this->accessControl, $this->config),
			$this->actors,
			$jsonRenderer,
			$twigRenderer,
			$responseFactory,
			$this->config,
			$this->operationDetector,
			$this->loggerFactory,
			new AccessDenials($this->loggerFactory, new OAuthActivityLogger(new NullLogger()), new EventDispatcher(new NullLogger())),
			$guard,
		);

		// A request already through routing, carrying collection + id args.
		// Attributes set with withAttribute() are kept.
		$this->requestFor = function (string $collection, string $id, array $attributes = []): ServerRequestInterface {
			$route = $this->createMock(RouteInterface::class);
			$route->method('getName')->willReturn('object-update');
			$route->method('getArgument')->willReturnCallback(
				static fn (string $name, $default = null) => match ($name) {
					'collection' => $collection,
					'id'         => $id,
					default      => $default,
				},
			);

			$uri = $this->createMock(UriInterface::class);
			$uri->method('getPath')->willReturn("/api/collections/{$collection}/{$id}");

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getUri')->willReturn($uri);
			$request->method('getMethod')->willReturn('PUT');
			$request->method('withAttribute')->willReturnCallback(
				fn (string $name, $value): ServerRequestInterface => ($this->requestFor)($collection, $id, [$name => $value] + $attributes),
			);
			$request->method('getAttribute')->willReturnCallback(
				fn (string $name, $default = null) => match ($name) {
					RouteContext::ROUTE           => $route,
					RouteContext::ROUTE_PARSER    => $this->createMock(RouteParserInterface::class),
					RouteContext::ROUTING_RESULTS => $this->createMock(RoutingResults::class),
					default                       => $attributes[$name] ?? $default,
				},
			);

			return $request;
		};

		// Signed in as alex-park, who is NOT a super-admin and whose groups
		// grant nothing on the auth collection.
		$this->signInAs = function (string $userId, string $authCollection): void {
			$this->session->method('get')->willReturnCallback(
				static fn (string $key) => match ($key) {
					SessionKeys::AUTH_USER       => $userId,
					SessionKeys::AUTH_COLLECTION => $authCollection,
					default                      => null,
				},
			);
			$this->operationDetector->method('detectOperation')->willReturn('update');
			$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));
		};
	});

	it('allows a self-profile write when AUTH_COLLECTION is empty', function (): void {
		($this->signInAs)('alex-park', '');

		$response = $this->middleware->process(($this->requestFor)('auth', 'alex-park'), $this->handler);

		expect($response)->toBe($this->allowed);
	});

	it('allows a self-profile write when AUTH_COLLECTION is set', function (): void {
		($this->signInAs)('alex-park', 'auth');

		$response = $this->middleware->process(($this->requestFor)('auth', 'alex-park'), $this->handler);

		expect($response)->toBe($this->allowed);
	});

	it('a default-login session user can still update their own profile', function (): void {
		$this->config->auth = ['enable' => true, 'collection' => 'users'];
		($this->signInAs)('alex', '');

		$response = $this->middleware->process(($this->requestFor)('users', 'alex'), $this->handler);

		// Allowed by the carve-out, before the group check: the actor's empty
		// collection resolves to the configured default `users`.
		expect($response)->toBe($this->allowed);
		$actor = $this->actors->current();
		expect($actor->userId)->toBe('alex');
		expect($actor->authCollection)->toBe('users');
	});

	it('still refuses a write to someone elses record', function (): void {
		($this->signInAs)('alex-park', '');

		$response = $this->middleware->process(($this->requestFor)('auth', 'jordan-lee'), $this->handler);

		expect($response)->toBe($this->forbidden);
	});

	it('does not extend the carve-out to a non-auth collection', function (): void {
		($this->signInAs)('alex-park', '');

		$response = $this->middleware->process(($this->requestFor)('blog', 'alex-park'), $this->handler);

		expect($response)->toBe($this->forbidden);
	});

	it('logs a denial so an operator can see who was refused what', function (): void {
		($this->signInAs)('alex-park', '');

		$this->middleware->process(($this->requestFor)('auth', 'jordan-lee'), $this->handler);

		expect($this->logged)->toHaveCount(1);
		expect($this->logged[0]['message'])->toBe('Access denied');
		expect($this->logged[0]['context']['user_id'])->toBe('alex-park');
		expect($this->logged[0]['context']['operation'])->toBe('update');
		expect($this->logged[0]['context']['auth_method'])->toBe('session');
		expect($this->logged[0]['context']['resource'])->toBe('collection');
		expect($this->logged[0]['context']['reason'])->toBe('group');
	});

	it('logs nothing when the request is allowed', function (): void {
		($this->signInAs)('alex-park', '');

		$this->middleware->process(($this->requestFor)('auth', 'alex-park'), $this->handler);

		expect($this->logged)->toHaveCount(0);
	});
});
