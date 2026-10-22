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
use TotalCMS\Domain\Auth\Data\ReadScope;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\AccessDenials;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\ActorResolver;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Collection\Data\OwnerScope;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\Object\Data\ObjectData;
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
 * Owned collections. When a collection names an ownerProperty, every actor
 * who is not a super admin is limited to the objects they own: object routes
 * 403 on a mismatch, the two list routes are handed a `readScope` attribute
 * to filter by, and the operations that cannot be scoped (bulk delete,
 * reindex, clone) are refused. Super admins never reach checkPermission(), so
 * nothing here can touch them.
 *
 * The group check still runs first: ownership never grants what the group did
 * not.
 */
describe('CollectionAccessMiddleware owner scoping', function (): void {
	beforeEach(function (): void {
		$this->accessControl     = $this->createMock(AccessControlService::class);
		$this->session           = $this->createMock(SessionInterface::class);
		$this->operationDetector = $this->createMock(OperationDetector::class);
		$this->ownerProperties   = $this->createMock(OwnerPropertyResolver::class);
		// The real outsider rule over whatever ownerCollection() a test stubs.
		$this->ownerProperties->method('isOutsider')->willReturnCallback(function (string $collection, string $actorCollection, string $default): bool {
			$required = $this->ownerProperties->ownerCollection($collection);

			return $required !== '' && ($actorCollection !== '' ? $actorCollection : $default) !== $required;
		});
		$this->objectFetcher     = $this->createMock(ObjectFetcher::class);

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

		// The handler records the request it was handed, so a test can read the
		// attributes the middleware attached.
		$this->handled = new \ArrayObject();
		$this->handler = $this->createMock(RequestHandlerInterface::class);
		$this->handler->method('handle')->willReturnCallback(function (ServerRequestInterface $request): ResponseInterface {
			$this->handled[] = $request;

			return $this->allowed;
		});

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
		$this->loggerFactory = $this->createMock(LoggerFactory::class);
		$this->loggerFactory->method('channelLogger')->willReturn($this->accessLogger);

		// The guard runs for real over a stubbed schema. Tests that need
		// protected properties replace the definitions here.
		$this->schemaProperties = new \ArrayObject(['title' => [], 'owner' => []]);
		$schemaFetcher          = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchemaForCollection')->willReturnCallback(function (): SchemaData {
			$schema             = new SchemaData();
			$schema->id         = 'showcase';
			$schema->properties = $this->schemaProperties->getArrayCopy();

			return $schema;
		});
		$guard = new AccessGuard($schemaFetcher, $this->objectFetcher, $this->ownerProperties, $this->config, $this->loggerFactory);

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

		// A routed request. Attributes set with withAttribute() are kept, so the
		// request the handler receives carries anything the middleware attached.
		$this->requestFor = function (string $routeName, string $collection, ?string $id = null, string $method = 'GET', array $attributes = []): ServerRequestInterface {
			$route = $this->createMock(RouteInterface::class);
			$route->method('getName')->willReturn($routeName);
			$route->method('getArgument')->willReturnCallback(
				static fn (string $name, $default = null) => match ($name) {
					'collection' => $collection,
					'id'         => $id ?? $default,
					'property'   => $attributes['__property'] ?? $default,
					default      => $default,
				},
			);

			$uri = $this->createMock(UriInterface::class);
			$uri->method('getPath')->willReturn('/api/collections/' . $collection . ($id !== null ? '/' . $id : ''));

			$attributes[RouteContext::ROUTE]           = $route;
			$attributes[RouteContext::ROUTE_PARSER]    = $this->createMock(RouteParserInterface::class);
			$attributes[RouteContext::ROUTING_RESULTS] = $this->createMock(RoutingResults::class);

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getUri')->willReturn($uri);
			$request->method('getMethod')->willReturn($method);
			$request->method('getParsedBody')->willReturn($attributes['__body'] ?? null);
			$request->method('getAttribute')->willReturnCallback(
				static fn (string $name, $default = null) => $attributes[$name] ?? $default,
			);
			$request->method('withAttribute')->willReturnCallback(
				function (string $name, $value) use ($routeName, $collection, $id, $method, $attributes): ServerRequestInterface {
					$attributes[$name] = $value;

					return ($this->requestFor)($routeName, $collection, $id, $method, $attributes);
				},
			);

			return $request;
		};

		// A signed-in non-admin whose group grants everything on the collection.
		$this->signInAs = function (string $userId, string $operation): void {
			$this->session->method('get')->willReturnCallback(
				static fn (string $key) => match ($key) {
					SessionKeys::AUTH_USER       => $userId,
					SessionKeys::AUTH_COLLECTION => 'members',
					default                      => null,
				},
			);
			$this->operationDetector->method('detectOperation')->willReturn($operation);
			$this->accessControl->method('authorityOf')->willReturn(($this->bearerAuthority)());
		};

		$this->owned = function (string $property = 'owner'): void {
			$this->ownerProperties->method('resolve')->willReturn($property);
		};

		/** @param array<string,mixed>|null $data null = object does not exist */
		$this->storedObject = function (?array $data): void {
			$this->objectFetcher->method('existsObject')->willReturn($data !== null);
			if ($data !== null) {
				$object = $this->createMock(ObjectData::class);
				$object->method('toArray')->willReturn($data);
				$this->objectFetcher->method('fetchObject')->willReturn($object);
			}
		};

		// A non-admin bearer authority whose one group grants every operation
		// on every collection — the group check passes, so only ownership can
		// refuse. Built for real: UserAuthority's promoted readonly properties
		// are uninitialised on a PHPUnit stub.
		$this->bearerAuthority = static fn (): UserAuthority => new UserAuthority(isAdmin: false, groups: [
			new AccessGroupData([
				'id'          => 'members',
				'permissions' => ['collections' => ['operations' => ['create', 'read', 'update', 'delete'], 'all' => true, 'allowed' => []]],
			]),
		]);
	});

	it('allows an object route when the actor owns the object', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'update');
		($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);

		$response = $this->middleware->process(($this->requestFor)('object-update', 'showcase', 'site-1', 'PUT'), $this->handler);

		expect($response)->toBe($this->allowed);
		expect($this->logged)->toHaveCount(0);
	});

	it('refuses an object route when someone else owns the object, with reason owner', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'update');
		($this->storedObject)(['id' => 'site-2', 'owner' => 'sam']);

		$response = $this->middleware->process(($this->requestFor)('object-update', 'showcase', 'site-2', 'PUT'), $this->handler);

		expect($response)->toBe($this->forbidden);
		expect($this->logged)->toHaveCount(1);
		expect($this->logged[0]['message'])->toBe('Access denied');
		expect($this->logged[0]['context']['reason'])->toBe('owner');
		expect($this->logged[0]['context']['user_id'])->toBe('alex');
	});

	it('allows a co-owner listed in a list owner property', function (): void {
		($this->owned)();
		($this->signInAs)('sam', 'update');
		($this->storedObject)(['id' => 'site-3', 'owner' => ['alex', 'sam']]);

		$response = $this->middleware->process(($this->requestFor)('object-update', 'showcase', 'site-3', 'PUT'), $this->handler);

		expect($response)->toBe($this->allowed);
		expect($this->logged)->toHaveCount(0);
	});

	it('refuses an actor missing from a list owner property, with reason owner', function (): void {
		($this->owned)();
		($this->signInAs)('kim', 'update');
		($this->storedObject)(['id' => 'site-3', 'owner' => ['alex', 'sam']]);

		$response = $this->middleware->process(($this->requestFor)('object-update', 'showcase', 'site-3', 'PUT'), $this->handler);

		expect($response)->toBe($this->forbidden);
		expect($this->logged)->toHaveCount(1);
		expect($this->logged[0]['context']['reason'])->toBe('owner');
	});

	it('refuses reads of another members object too', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'read');
		($this->storedObject)(['id' => 'site-2', 'owner' => 'sam']);

		$response = $this->middleware->process(($this->requestFor)('object-fetch', 'showcase', 'site-2'), $this->handler);

		expect($response)->toBe($this->forbidden);
	});

	it('refuses property and upload routes on another members object', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'update');
		($this->storedObject)(['id' => 'site-2', 'owner' => 'sam']);

		foreach (['property-update', 'property-file-save', 'deck-item-create', 'upload-post'] as $routeName) {
			$response = $this->middleware->process(($this->requestFor)($routeName, 'showcase', 'site-2', 'POST'), $this->handler);
			expect($response)->toBe($this->forbidden, "route: $routeName");
		}
	});

	it('denies an object that has no owner value', function (): void {
		// Created before ownerProperty was set, or anonymously. Nobody but an
		// admin owns it.
		($this->owned)();
		($this->signInAs)('alex', 'update');
		($this->storedObject)(['id' => 'legacy', 'title' => 'No owner key']);

		$response = $this->middleware->process(($this->requestFor)('object-patch', 'showcase', 'legacy', 'PATCH'), $this->handler);

		expect($response)->toBe($this->forbidden);
	});

	it('denies when the actor id is empty', function (): void {
		// A bearer token whose subject parses to nothing must not match an
		// object whose owner is empty.
		($this->owned)();
		$this->operationDetector->method('detectOperation')->willReturn('update');
		($this->storedObject)(['id' => 'anon', 'owner' => '']);
		$this->accessControl->method('authorityOf')->willReturn(($this->bearerAuthority)());

		$request = ($this->requestFor)('object-update', 'showcase', 'anon', 'PUT', [
			'authMethod'    => 'oauth_bearer',
			'oauth_user_id' => 'members:',
		]);

		$response = $this->middleware->process($request, $this->handler);

		expect($response)->toBe($this->forbidden);

		// No scope may be built from an empty actor id, and object-save is refused.
		$attrs = ['authMethod' => 'oauth_bearer', 'oauth_user_id' => 'members:'];
		expect($this->middleware->process(($this->requestFor)('collection-query', 'showcase', null, 'GET', $attrs), $this->handler))->toBe($this->forbidden);
		expect($this->middleware->process(($this->requestFor)('object-save', 'showcase', null, 'POST', $attrs), $this->handler))->toBe($this->forbidden);
		expect($this->handled)->toHaveCount(0);
	});

	it('refuses id-less export, report and import routes with reason owner', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'read');

		foreach ([['export-json', 'GET'], ['report-json', 'GET'], ['import-json', 'POST']] as [$routeName, $method]) {
			$this->logged->exchangeArray([]);
			$response = $this->middleware->process(($this->requestFor)($routeName, 'showcase', null, $method), $this->handler);
			expect($response)->toBe($this->forbidden, "route: $routeName");
			expect($this->logged[0]['context']['reason'])->toBe('owner', "route: $routeName");
		}
	});

	it('passes admin-collection without an id and sets no scope', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'read');

		expect($this->middleware->process(($this->requestFor)('admin-collection', 'showcase'), $this->handler))->toBe($this->allowed);
		expect($this->handled[0]->getAttribute('readScope'))->toBeNull();
	});

	it('refuses admin-side duplicate but passes a plain admin add', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'create');
		($this->storedObject)(null);

		$dup = ($this->requestFor)('admin-collection-post', 'showcase', 'add', 'POST', ['__body' => ['duplicate' => 'other']]);
		expect($this->middleware->process($dup, $this->handler))->toBe($this->forbidden);
		expect($this->logged[0]['context']['reason'])->toBe('owner');

		$plain = ($this->requestFor)('admin-collection-post', 'showcase', 'add', 'POST', ['__body' => ['title' => 'x']]);
		expect($this->middleware->process($plain, $this->handler))->toBe($this->allowed);
	});

	it('passes a read of a missing object through so the action returns its own 404', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'read');
		($this->storedObject)(null);

		$response = $this->middleware->process(($this->requestFor)('object-fetch', 'showcase', 'nope'), $this->handler);

		expect($response)->toBe($this->allowed);
	});

	/*
	 * A write naming a missing id is denied: the property savers (file, image,
	 * gallery, depot) would create the object as a side effect of an upload,
	 * squatting the id. Members create through object-save.
	 */
	it('denies an update of a missing object on a property route, with reason owner', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'update');
		($this->storedObject)(null);

		foreach (['upload-post', 'property-update'] as $routeName) {
			$this->logged->exchangeArray([]);
			$response = $this->middleware->process(($this->requestFor)($routeName, 'showcase', 'squat', 'POST', ['__property' => 'photo']), $this->handler);
			expect($response)->toBe($this->forbidden, "route: $routeName");
			expect($this->logged[0]['context']['reason'])->toBe('owner', "route: $routeName");
		}
		expect($this->handled)->toHaveCount(0);
	});

	it('denies a create naming a missing object, with reason owner', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'create');
		($this->storedObject)(null);

		$response = $this->middleware->process(($this->requestFor)('upload-post', 'showcase', 'squat', 'POST', ['__property' => 'photo']), $this->handler);

		expect($response)->toBe($this->forbidden);
		expect($this->logged[0]['context']['reason'])->toBe('owner');
	});

	it('denies a delete of a missing object, with reason owner', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'delete');
		($this->storedObject)(null);

		$response = $this->middleware->process(($this->requestFor)('object-delete', 'showcase', 'nope', 'DELETE'), $this->handler);

		expect($response)->toBe($this->forbidden);
		expect($this->logged[0]['context']['reason'])->toBe('owner');
	});

	it('still allows a read of a missing object on a property route', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'read');
		($this->storedObject)(null);

		$response = $this->middleware->process(($this->requestFor)('property-fetch', 'showcase', 'nope', 'GET', ['__property' => 'photo']), $this->handler);

		expect($response)->toBe($this->allowed);
	});

	it('denies every route when the owner property is unresolved', function (): void {
		// The collection record could not be read, so nothing on it can be
		// scoped. Even the actor's own object is refused.
		($this->owned)(OwnerPropertyResolver::UNRESOLVED);
		($this->signInAs)('alex', 'read');
		($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);

		foreach ([['object-fetch', 'site-1', 'GET'], ['collection-query', null, 'GET'], ['object-save', null, 'POST']] as [$routeName, $id, $method]) {
			$this->logged->exchangeArray([]);
			$response = $this->middleware->process(($this->requestFor)($routeName, 'showcase', $id, $method), $this->handler);
			expect($response)->toBe($this->forbidden, "route: $routeName");
			expect($this->logged[0]['context']['reason'])->toBe('owner', "route: $routeName");
		}
		expect($this->handled)->toHaveCount(0);
	});

	it('hands the two list routes a readScope attribute', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'read');

		foreach (['collection-query', 'collection-fetch-index'] as $routeName) {
			$response = $this->middleware->process(($this->requestFor)($routeName, 'showcase'), $this->handler);

			expect($response)->toBe($this->allowed, "route: $routeName");
		}

		expect($this->handled)->toHaveCount(2);
		foreach ($this->handled as $request) {
			$scope = $request->getAttribute('readScope');
			expect($scope)->toBeInstanceOf(ReadScope::class);
			expect($scope->owner)->toBeInstanceOf(OwnerScope::class);
			expect($scope->owner->property)->toBe('owner');
			expect($scope->owner->value)->toBe('alex');
		}
	});

	it('allows object-save without a scope — the storage layer stamps the owner', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'create');

		$response = $this->middleware->process(($this->requestFor)('object-save', 'showcase', null, 'POST'), $this->handler);

		expect($response)->toBe($this->allowed);
		expect($this->handled[0]->getAttribute('readScope'))->toBeNull();
	});

	it('refuses bulk delete, reindex and clone outright', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'update');
		($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);

		expect($this->middleware->process(($this->requestFor)('object-bulk-delete', 'showcase', null, 'POST'), $this->handler))->toBe($this->forbidden);
		expect($this->middleware->process(($this->requestFor)('collection-reindex', 'showcase', null, 'PUT'), $this->handler))->toBe($this->forbidden);
		// Even the actor's OWN object cannot be cloned: the copy would carry an
		// owner the guard never stamped.
		expect($this->middleware->process(($this->requestFor)('object-clone', 'showcase', 'site-1', 'POST'), $this->handler))->toBe($this->forbidden);
		expect($this->logged[2]['context']['reason'])->toBe('owner');
	});

	it('does not scope a collection without an ownerProperty', function (): void {
		$this->ownerProperties->method('resolve')->willReturn('');
		($this->signInAs)('alex', 'update');
		$this->objectFetcher->expects($this->never())->method('existsObject');

		expect($this->middleware->process(($this->requestFor)('object-update', 'blog', 'post-1', 'PUT'), $this->handler))->toBe($this->allowed);
		expect($this->middleware->process(($this->requestFor)('object-bulk-delete', 'blog', null, 'POST'), $this->handler))->toBe($this->allowed);
		expect($this->middleware->process(($this->requestFor)('collection-query', 'blog'), $this->handler))->toBe($this->allowed);
		expect($this->handled[2]->getAttribute('readScope'))->toBeNull();
	});

	it('does not scope a super admin', function (): void {
		($this->owned)();
		$this->session->method('get')->willReturnCallback(
			static fn (string $key) => match ($key) {
				SessionKeys::AUTH_USER       => 'root',
				SessionKeys::AUTH_COLLECTION => '',
				default                      => null,
			},
		);
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: true, groups: []));
		$this->objectFetcher->expects($this->never())->method('existsObject');

		expect($this->middleware->process(($this->requestFor)('object-update', 'showcase', 'site-2', 'PUT'), $this->handler))->toBe($this->allowed);
		expect($this->middleware->process(($this->requestFor)('collection-query', 'showcase'), $this->handler))->toBe($this->allowed);
		expect($this->handled[1]->getAttribute('readScope'))->toBeNull();
		// The actor is set before the super-admin bypass.
		expect($this->actors->current()->userId)->toBe('root');
		expect($this->actors->current()->authCollection)->toBe('auth');
	});

	it('makes the token user the actor of an admin bearer request it passes through', function (): void {
		($this->owned)();
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: true, groups: []));
		$this->objectFetcher->expects($this->never())->method('existsObject');

		$request = ($this->requestFor)('object-update', 'showcase', 'site-2', 'PUT', ['authMethod' => 'oauth_bearer', 'oauth_user_id' => 'members:root']);

		expect($this->middleware->process($request, $this->handler))->toBe($this->allowed);
		expect($this->actors->current()->userId)->toBe('root');
		expect($this->actors->current()->authCollection)->toBe('members');
	});

	it('scopes a non-admin editor of the default auth collection', function (): void {
		// Dashboard editors are not exempt: on an owned collection they see and
		// edit only their own objects, which is the guest-author behaviour.
		($this->owned)();
		$this->session->method('get')->willReturnCallback(
			static fn (string $key) => match ($key) {
				SessionKeys::AUTH_USER       => 'editor',
				SessionKeys::AUTH_COLLECTION => '',
				default                      => null,
			},
		);
		$this->operationDetector->method('detectOperation')->willReturn('update');
		$this->accessControl->method('authorityOf')->willReturn(($this->bearerAuthority)());
		($this->storedObject)(['id' => 'site-2', 'owner' => 'sam']);

		expect($this->middleware->process(($this->requestFor)('object-update', 'showcase', 'site-2', 'PUT'), $this->handler))->toBe($this->forbidden);
	});

	it('scopes a non-admin OAuth bearer actor by the token subject', function (): void {
		($this->owned)();
		$this->operationDetector->method('detectOperation')->willReturn('read');
		($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);
		$this->accessControl->method('authorityOf')->willReturn(($this->bearerAuthority)());

		$own   = ($this->requestFor)('object-fetch', 'showcase', 'site-1', 'GET', ['authMethod' => 'oauth_bearer', 'oauth_user_id' => 'members:alex']);
		$other = ($this->requestFor)('object-fetch', 'showcase', 'site-1', 'GET', ['authMethod' => 'oauth_bearer', 'oauth_user_id' => 'members:sam']);

		expect($this->middleware->process($own, $this->handler))->toBe($this->allowed);
		expect($this->middleware->process($other, $this->handler))->toBe($this->forbidden);
	});

	it('still refuses when the group check fails, with reason group', function (): void {
		($this->owned)();
		$this->session->method('get')->willReturnCallback(
			static fn (string $key) => match ($key) {
				SessionKeys::AUTH_USER       => 'alex',
				SessionKeys::AUTH_COLLECTION => 'members',
				default                      => null,
			},
		);
		$this->operationDetector->method('detectOperation')->willReturn('delete');
		$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: false, groups: []));
		($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);

		$response = $this->middleware->process(($this->requestFor)('object-delete', 'showcase', 'site-1', 'DELETE'), $this->handler);

		expect($response)->toBe($this->forbidden);
		expect($this->logged[0]['context']['reason'])->toBe('group');
	});

	describe('protected properties on property routes', function (): void {
		beforeEach(function (): void {
			$this->schemaProperties->exchangeArray([
				'title'   => [],
				'owner'   => [],
				'draft'   => ['protected' => ['value' => true]],
				'gallery' => ['protected' => true],
			]);
		});

		it('refuses property-delete on a protected property of the members own object, with reason protected', function (): void {
			($this->owned)();
			($this->signInAs)('alex', 'update');
			($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);

			$response = $this->middleware->process(($this->requestFor)('property-delete', 'showcase', 'site-1', 'DELETE', ['__property' => 'draft']), $this->handler);

			expect($response)->toBe($this->forbidden);
			expect($this->logged[0]['context']['reason'])->toBe('protected');
		});

		it('passes property-delete on an unprotected property of the members own object', function (): void {
			($this->owned)();
			($this->signInAs)('alex', 'update');
			($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);

			$response = $this->middleware->process(($this->requestFor)('property-delete', 'showcase', 'site-1', 'DELETE', ['__property' => 'title']), $this->handler);

			expect($response)->toBe($this->allowed);
		});

		it('refuses deck-item-create on a protected deck, owned collection or not', function (): void {
			($this->signInAs)('alex', 'update');
			$this->ownerProperties->method('resolve')->willReturn('');

			$response = $this->middleware->process(($this->requestFor)('deck-item-create', 'showcase', 'site-1', 'POST', ['__property' => 'gallery']), $this->handler);

			expect($response)->toBe($this->forbidden);
			expect($this->logged[0]['context']['reason'])->toBe('protected');
		});

		it('refuses a non-admin bearer on a protected property', function (): void {
			($this->owned)();
			$this->operationDetector->method('detectOperation')->willReturn('update');
			($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);
			$this->accessControl->method('authorityOf')->willReturn(($this->bearerAuthority)());

			$request = ($this->requestFor)('upload-post', 'showcase', 'site-1', 'POST', ['authMethod' => 'oauth_bearer', 'oauth_user_id' => 'members:alex', '__property' => 'gallery']);

			expect($this->middleware->process($request, $this->handler))->toBe($this->forbidden);
			expect($this->logged[0]['context']['reason'])->toBe('protected');
		});

		it('lets a super admin write a protected deck', function (): void {
			($this->owned)();
			$this->session->method('get')->willReturnCallback(
				static fn (string $key) => match ($key) {
					SessionKeys::AUTH_USER       => 'root',
					SessionKeys::AUTH_COLLECTION => '',
					default                      => null,
				},
			);
			$this->accessControl->method('authorityOf')->willReturn(new UserAuthority(isAdmin: true, groups: []));

			$response = $this->middleware->process(($this->requestFor)('deck-item-create', 'showcase', 'site-1', 'POST', ['__property' => 'gallery']), $this->handler);

			expect($response)->toBe($this->allowed);
		});
	});

	// Every write path (deck items, files, uploads, meta, property deletes)
	// reaches storage through ObjectUpdater::updateObject(), which lays these
	// remembered values over the save. Only an allowed non-admin write on a
	// route naming both {collection} and {id} remembers anything.
	describe('forced values for the request', function (): void {
		beforeEach(function (): void {
			$this->schemaProperties->exchangeArray([
				'title' => [],
				'owner' => [],
				'shots' => [],
				'draft' => ['protected' => ['value' => true]],
			]);
		});

		// A clone copies values the actor could not write (an approved draft,
		// an admin-only flag), so on a collection with any protected, forced or
		// owner rule it is refused outright.
		it('refuses object-clone on a non-owned collection with a forced field, with reason protected', function (): void {
			($this->signInAs)('alex', 'create');
			$this->ownerProperties->method('resolve')->willReturn('');
			($this->storedObject)(['id' => 'site-1', 'draft' => false]);

			$response = $this->middleware->process(($this->requestFor)('object-clone', 'showcase', 'site-1', 'POST'), $this->handler);

			expect($response)->toBe($this->forbidden);
			expect($this->handled)->toHaveCount(0);
			expect($this->logged[0]['context']['reason'])->toBe('protected');
		});

		it('passes object-clone on a non-owned collection without protected rules', function (): void {
			$this->schemaProperties->exchangeArray(['title' => [], 'owner' => []]);
			($this->signInAs)('alex', 'create');
			$this->ownerProperties->method('resolve')->willReturn('');
			($this->storedObject)(['id' => 'site-1', 'title' => 'Site']);

			$response = $this->middleware->process(($this->requestFor)('object-clone', 'showcase', 'site-1', 'POST'), $this->handler);

			expect($response)->toBe($this->allowed);
			expect($this->logged)->toHaveCount(0);
		});

		// The clone action takes its TARGET collection from the body, and only
		// the route collection is access-checked: a body naming any other
		// collection is refused, so a member cannot clone into a collection
		// their group or its protected fields would not let them write.
		it('refuses object-clone whose body names another collection, with reason protected', function (): void {
			$this->schemaProperties->exchangeArray(['title' => [], 'owner' => []]);
			($this->signInAs)('alex', 'create');
			$this->ownerProperties->method('resolve')->willReturn('');
			($this->storedObject)(['id' => 'site-1', 'title' => 'Site']);

			$response = $this->middleware->process(($this->requestFor)('object-clone', 'showcase', 'site-1', 'POST', ['__body' => ['id' => 'copy', 'collection' => 'other']]), $this->handler);

			expect($response)->toBe($this->forbidden);
			expect($this->handled)->toHaveCount(0);
			expect($this->logged[0]['context']['reason'])->toBe('protected');
		});

		// The action passes a non-string target through and it is coerced
		// downstream (2024 -> "2024", true -> "1"), so any non-null value that
		// is not the route collection is a retarget.
		it('refuses object-clone whose body names a non-string collection, with reason protected', function (): void {
			$this->schemaProperties->exchangeArray(['title' => [], 'owner' => []]);
			($this->signInAs)('alex', 'create');
			$this->ownerProperties->method('resolve')->willReturn('');
			($this->storedObject)(['id' => 'site-1', 'title' => 'Site']);

			foreach ([2024, true, ''] as $target) {
				$this->logged->exchangeArray([]);
				$response = $this->middleware->process(($this->requestFor)('object-clone', 'showcase', 'site-1', 'POST', ['__body' => ['id' => 'copy', 'collection' => $target]]), $this->handler);

				expect($response)->toBe($this->forbidden, 'target: ' . var_export($target, true));
				expect($this->logged[0]['context']['reason'])->toBe('protected');
			}
			expect($this->handled)->toHaveCount(0);
		});

		it('passes object-clone whose body names the route collection', function (): void {
			$this->schemaProperties->exchangeArray(['title' => [], 'owner' => []]);
			($this->signInAs)('alex', 'create');
			$this->ownerProperties->method('resolve')->willReturn('');
			($this->storedObject)(['id' => 'site-1', 'title' => 'Site']);

			$response = $this->middleware->process(($this->requestFor)('object-clone', 'showcase', 'site-1', 'POST', ['__body' => ['id' => 'copy', 'collection' => 'showcase']]), $this->handler);

			expect($response)->toBe($this->allowed);
			expect($this->logged)->toHaveCount(0);
		});

		// Imports upsert whole objects past the field rules, so on a collection
		// with protected, forced or owner rules they are admin-only.
		it('refuses an import on a non-owned collection with a forced field, with reason protected', function (): void {
			($this->signInAs)('alex', 'create');
			$this->ownerProperties->method('resolve')->willReturn('');

			foreach (['import-json', 'import-csv', 'import-deck-csv', 'import-deck-json', 'import-factory'] as $routeName) {
				$this->logged->exchangeArray([]);
				$response = $this->middleware->process(($this->requestFor)($routeName, 'showcase', null, 'POST'), $this->handler);

				expect($response)->toBe($this->forbidden, "route: $routeName");
				expect($this->logged[0]['context']['reason'])->toBe('protected', "route: $routeName");
			}
			expect($this->handled)->toHaveCount(0);
		});

		it('passes an import on a non-owned collection without protected rules', function (): void {
			$this->schemaProperties->exchangeArray(['title' => [], 'owner' => []]);
			($this->signInAs)('alex', 'create');
			$this->ownerProperties->method('resolve')->willReturn('');

			$response = $this->middleware->process(($this->requestFor)('import-json', 'showcase', null, 'POST'), $this->handler);

			expect($response)->toBe($this->allowed);
			expect($this->logged)->toHaveCount(0);
		});
	});

	it('refuses admin-side duplicate whatever its value, including "0"', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'create');
		($this->storedObject)(null);

		$dup = ($this->requestFor)('admin-collection-post', 'showcase', 'add', 'POST', ['__body' => ['duplicate' => '0']]);
		expect($this->middleware->process($dup, $this->handler))->toBe($this->forbidden);
		expect($this->logged[0]['context']['reason'])->toBe('owner');
	});

	it('refuses the admin pseudo-pages to a scoped actor, with reason owner', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'read');
		($this->storedObject)(null);

		foreach (['-export', '-import', '-factory', '-sitemap', '-feeds'] as $pseudo) {
			$this->logged->exchangeArray([]);
			expect($this->middleware->process(($this->requestFor)('admin-collection', 'showcase', $pseudo), $this->handler))->toBe($this->forbidden, "id: $pseudo");
			expect($this->logged[0]['context']['reason'])->toBe('owner', "id: $pseudo");
		}

		expect($this->middleware->process(($this->requestFor)('admin-collection-post', 'showcase', '-import', 'POST'), $this->handler))->toBe($this->forbidden);
	});

	describe('ownerCollection', function (): void {
		beforeEach(function (): void {
			// A signed-in non-admin from the given session auth collection
			// ('' = logged in at the plain /admin/login route).
			$this->signInFrom = function (string $userId, string $authCollection, string $operation): void {
				$this->session->method('get')->willReturnCallback(
					static fn (string $key) => match ($key) {
						SessionKeys::AUTH_USER       => $userId,
						SessionKeys::AUTH_COLLECTION => $authCollection,
						default                      => null,
					},
				);
				$this->operationDetector->method('detectOperation')->willReturn($operation);
				$this->accessControl->method('authorityOf')->willReturn(($this->bearerAuthority)());
			};
		});

		it('allows a member of the owner collection on their own object', function (): void {
			($this->owned)();
			$this->ownerProperties->method('ownerCollection')->willReturn('members');
			($this->signInFrom)('alex', 'members', 'update');
			($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);

			$response = $this->middleware->process(($this->requestFor)('object-update', 'showcase', 'site-1', 'PUT'), $this->handler);

			expect($response)->toBe($this->allowed);
			expect($this->actors->current()->authCollection)->toBe('members');
		});

		it('scopes the list routes for a member of the owner collection', function (): void {
			($this->owned)();
			$this->ownerProperties->method('ownerCollection')->willReturn('members');
			($this->signInFrom)('alex', 'members', 'read');

			expect($this->middleware->process(($this->requestFor)('collection-query', 'showcase'), $this->handler))->toBe($this->allowed);
			expect($this->handled[0]->getAttribute('readScope'))->toBeInstanceOf(ReadScope::class);
		});

		it('denies an outsider on their own object, the list route and object-save, with reason owner', function (): void {
			// alex logged in at the plain /admin/login route, so belongs to the
			// default auth collection `users`, not the owner collection `members`.
			// An object stamped `alex` (an id that exists in both collections)
			// is still refused.
			$this->config->auth = ['enable' => true, 'collection' => 'users'];
			($this->owned)();
			$this->ownerProperties->method('ownerCollection')->willReturn('members');
			($this->signInFrom)('alex', '', 'read');
			($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);

			foreach ([['object-fetch', 'site-1', 'GET'], ['collection-query', null, 'GET'], ['object-save', null, 'POST']] as [$routeName, $id, $method]) {
				$this->logged->exchangeArray([]);
				$response = $this->middleware->process(($this->requestFor)($routeName, 'showcase', $id, $method), $this->handler);
				expect($response)->toBe($this->forbidden, "route: $routeName");
				expect($this->logged[0]['context']['reason'])->toBe('owner', "route: $routeName");
			}
			expect($this->handled)->toHaveCount(0);
		});

		it('denies an outsider reading a missing id, with reason owner, instead of passing it to the 404', function (): void {
			// A member of the owner collection reading a missing id gets the
			// action's 404; an outsider owns nothing here and is refused before
			// the object is looked up, so they learn nothing about which ids exist.
			($this->owned)();
			$this->ownerProperties->method('ownerCollection')->willReturn('members');
			($this->signInFrom)('alex', 'partners', 'read');
			($this->storedObject)(null);

			$response = $this->middleware->process(($this->requestFor)('object-fetch', 'showcase', 'ghost'), $this->handler);

			expect($response)->toBe($this->forbidden);
			expect($this->logged[0]['context']['reason'])->toBe('owner');
			expect($this->handled)->toHaveCount(0);
		});

		it('keeps any auth collection an owner when ownerCollection is empty', function (): void {
			$this->config->auth = ['enable' => true, 'collection' => 'auth'];
			($this->owned)();
			$this->ownerProperties->method('ownerCollection')->willReturn('');
			($this->signInFrom)('alex', 'users', 'update');
			($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);

			$response = $this->middleware->process(($this->requestFor)('object-update', 'showcase', 'site-1', 'PUT'), $this->handler);

			expect($response)->toBe($this->allowed);
		});

		it('does not touch an unowned collection whatever ownerCollection says', function (): void {
			$this->ownerProperties->method('resolve')->willReturn('');
			$this->ownerProperties->method('ownerCollection')->willReturn('members');
			($this->signInFrom)('alex', 'users', 'update');
			$this->objectFetcher->expects($this->never())->method('existsObject');

			expect($this->middleware->process(($this->requestFor)('object-update', 'blog', 'post-1', 'PUT'), $this->handler))->toBe($this->allowed);
		});
	});
});
