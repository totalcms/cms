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
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Middleware\Access\CollectionMetaAccessMiddleware;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/*
 * A `collectionsMeta` grantee may edit collection settings, but not the
 * registration settings: with them they could open registration on an auth
 * collection with `registrationGroups: ["admin"]` and sign up anonymously as
 * a super admin. Super admins and API keys bypass checkPermission() and are
 * not covered here.
 */
describe('CollectionMetaAccessMiddleware registration settings', function (): void {
	beforeEach(function (): void {
		$this->accessControl     = $this->createMock(AccessControlService::class);
		$this->session           = $this->createMock(SessionInterface::class);
		$this->operationDetector = $this->createMock(OperationDetector::class);

		$this->config        = Config::init();
		$this->config->auth  = ['enable' => true, 'collection' => 'auth'];
		$this->config->env   = 'prod';
		$this->config->debug = false;

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

		$this->logged       = new \ArrayObject();
		$this->accessLogger = new class($this->logged) extends AbstractLogger {
			public function __construct(private \ArrayObject $sink)
			{
			}

			public function log($level, string|\Stringable $message, array $context = []): void
			{
				$this->sink[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}
		};
		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('channelLogger')->willReturn($this->accessLogger);

		// The stored `members` record: registration off, no groups.
		$members         = new CollectionData();
		$members->id     = 'members';
		$members->schema = 'auth';
		$this->stored    = $members;

		$fetcher = $this->createMock(CollectionFetcher::class);
		$fetcher->method('fetchCollection')->willReturnCallback(fn (string $id): ?CollectionData => $id === 'members' ? $this->stored : null);

		$this->middleware = new CollectionMetaAccessMiddleware(
			new ActorResolver($this->session, $this->accessControl, $this->config),
			new ActorContext(),
			$jsonRenderer,
			$twigRenderer,
			$responseFactory,
			$this->config,
			$this->operationDetector,
			$loggerFactory,
			new AccessDenials($loggerFactory, new OAuthActivityLogger(new NullLogger()), new EventDispatcher(new NullLogger())),
			$fetcher,
		);

		$this->requestFor = function (string $routeName, string $collection, mixed $body, array $attributes = []): ServerRequestInterface {
			$route = $this->createMock(RouteInterface::class);
			$route->method('getName')->willReturn($routeName);
			$route->method('getArgument')->willReturnCallback(
				static fn (string $name, $default = null) => $name === 'collection' && $collection !== '' ? $collection : $default,
			);

			$uri = $this->createMock(UriInterface::class);
			$uri->method('getPath')->willReturn('/api/collections/' . $collection);

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getUri')->willReturn($uri);
			$request->method('getMethod')->willReturn('PATCH');
			$request->method('getParsedBody')->willReturn($body);
			$request->method('withAttribute')->willReturnCallback(
				fn (string $name, $value): ServerRequestInterface => ($this->requestFor)($routeName, $collection, $body, [$name => $value] + $attributes),
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

		// Signed in as a non-super-admin whose group grants every collectionsMeta operation.
		$this->signInAsGrantee = function (string $operation): void {
			$this->session->method('get')->willReturnCallback(
				static fn (string $key) => match ($key) {
					SessionKeys::AUTH_USER       => 'sam',
					SessionKeys::AUTH_COLLECTION => 'auth',
					default                      => null,
				},
			);
			$this->operationDetector->method('detectOperation')->willReturn($operation);
			$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: [
				new AccessGroupData(['id' => 'meta', 'permissions' => ['collectionsMeta' => ['all' => true, 'operations' => ['create', 'read', 'update', 'delete']]]]),
			]));
		};
	});

	it('refuses a grantee PATCH that turns on publicRegistration, logging the reason protected', function (): void {
		($this->signInAsGrantee)('update');

		$response = $this->middleware->process(($this->requestFor)('collection-patch', 'members', ['publicRegistration' => true]), $this->handler);

		expect($response)->toBe($this->forbidden);
		expect($this->logged)->toHaveCount(1);
		expect($this->logged[0]['context']['reason'])->toBe('protected');
		expect($this->logged[0]['context']['resource'])->toBe('collection metadata');
	});

	it('refuses a grantee PATCH that sets registrationGroups', function (): void {
		($this->signInAsGrantee)('update');

		$response = $this->middleware->process(($this->requestFor)('collection-patch', 'members', ['registrationGroups' => ['admin']]), $this->handler);

		expect($response)->toBe($this->forbidden);
		expect($this->logged[0]['context']['reason'])->toBe('protected');
	});

	it('refuses a grantee creating a collection with registration open', function (): void {
		($this->signInAsGrantee)('create');

		$response = $this->middleware->process(($this->requestFor)('collection-save', '', ['id' => 'members2', 'schema' => 'auth', 'publicRegistration' => 'true']), $this->handler);

		expect($response)->toBe($this->forbidden);
	});

	it('allows a grantee PATCH of other settings', function (): void {
		($this->signInAsGrantee)('update');

		$response = $this->middleware->process(($this->requestFor)('collection-patch', 'members', ['name' => 'Members', 'labelPlural' => 'People']), $this->handler);

		expect($response)->toBe($this->allowed);
		expect($this->logged)->toHaveCount(0);
	});

	it('allows a grantee form save that resubmits the stored registration values unchanged', function (): void {
		$this->stored->publicRegistration = true;
		$this->stored->registrationGroups = ['vip', 'member'];
		($this->signInAsGrantee)('update');

		$response = $this->middleware->process(($this->requestFor)('collection-update', 'members', [
			'name'               => 'Members',
			'publicRegistration' => 'true',
			'registrationGroups' => ['member', 'vip'],
		]), $this->handler);

		expect($response)->toBe($this->allowed);
	});

	// The same normaliser CollectionSaver stores with: a value that stores as
	// off is no change against a stored off, one that stores as on is.
	it('allows a grantee PATCH whose publicRegistration stores as off', function (mixed $value): void {
		($this->signInAsGrantee)('update');

		$response = $this->middleware->process(($this->requestFor)('collection-patch', 'members', ['publicRegistration' => $value]), $this->handler);

		expect($response)->toBe($this->allowed);
	})->with(['false', 'off', 'garbage']);

	it('refuses a grantee PATCH whose publicRegistration stores as on', function (mixed $value): void {
		($this->signInAsGrantee)('update');

		$response = $this->middleware->process(($this->requestFor)('collection-patch', 'members', ['publicRegistration' => $value]), $this->handler);

		expect($response)->toBe($this->forbidden);
	})->with(['on', '1', 'true', true]);

	it('allows a grantee PATCH of a string registrationGroups, which stores as no groups', function (): void {
		($this->signInAsGrantee)('update');

		$response = $this->middleware->process(($this->requestFor)('collection-patch', 'members', ['registrationGroups' => 'admin']), $this->handler);

		expect($response)->toBe($this->allowed);
	});

	it('allows a grantee read', function (): void {
		($this->signInAsGrantee)('read');

		$response = $this->middleware->process(($this->requestFor)('collection-fetch', 'members', null), $this->handler);

		expect($response)->toBe($this->allowed);
	});

	it('logs no reason for an ordinary group denial', function (): void {
		$this->session->method('get')->willReturnCallback(
			static fn (string $key) => $key === SessionKeys::AUTH_USER ? 'sam' : null,
		);
		$this->operationDetector->method('detectOperation')->willReturn('update');
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));

		$response = $this->middleware->process(($this->requestFor)('collection-patch', 'members', ['name' => 'X']), $this->handler);

		expect($response)->toBe($this->forbidden);
		expect($this->logged[0]['context'])->not->toHaveKey('reason');
	});
});
