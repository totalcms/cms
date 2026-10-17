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
use TotalCMS\Domain\Auth\Data\AccessActor;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Auth\Service\ForcedValueContext;
use TotalCMS\Domain\Auth\Service\OperationDetector;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Data\OwnerScope;
use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
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
 * 403 on a mismatch, the two list routes are handed an `ownerScope` attribute
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
		$this->userValidation    = $this->createMock(UserValidationService::class);
		$this->operationDetector = $this->createMock(OperationDetector::class);
		$this->ownerProperties   = $this->createMock(OwnerPropertyResolver::class);
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

		// The field policy is final, so it runs for real over a stubbed schema.
		// Tests that need protected properties replace the definitions here.
		$this->schemaProperties = new \ArrayObject(['title' => [], 'owner' => []]);
		$schemaFetcher          = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchemaForCollection')->willReturnCallback(function (): SchemaData {
			$schema             = new SchemaData();
			$schema->id         = 'showcase';
			$schema->properties = $this->schemaProperties->getArrayCopy();

			return $schema;
		});
		$policy = new AuthFieldPolicy($schemaFetcher, $this->userValidation, $this->objectFetcher, $this->ownerProperties, $this->config, $this->loggerFactory);

		// Filled by scopeRequest() for an allowed non-admin write; read by
		// ObjectUpdater::updateObject() in the running app.
		$this->forcedValues = new ForcedValueContext();

		$this->middleware = new CollectionAccessMiddleware(
			$this->userValidation,
			$this->accessControl,
			$this->session,
			$jsonRenderer,
			$twigRenderer,
			$responseFactory,
			$this->config,
			$this->operationDetector,
			$this->loggerFactory,
			new OAuthActivityLogger(new NullLogger()),
			$this->ownerProperties,
			$this->objectFetcher,
			$policy,
			$this->forcedValues,
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
			$this->userValidation->method('isSuperAdmin')->willReturn(false);
			$this->operationDetector->method('detectOperation')->willReturn($operation);
			$this->accessControl->method('canAccessCollection')->willReturn(true);
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
		$this->accessControl->method('authorityFor')->willReturn(($this->bearerAuthority)());

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
		expect($this->handled[0]->getAttribute('ownerScope'))->toBeNull();
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
	 * gallery, depot) create the object through ObjectSaver, outside the field
	 * policy, so it would carry no owner stamp and squat the id. Members create
	 * through object-save.
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

	it('hands the two list routes an ownerScope attribute', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'read');

		foreach (['collection-query', 'collection-fetch-index'] as $routeName) {
			$response = $this->middleware->process(($this->requestFor)($routeName, 'showcase'), $this->handler);

			expect($response)->toBe($this->allowed, "route: $routeName");
		}

		expect($this->handled)->toHaveCount(2);
		foreach ($this->handled as $request) {
			$scope = $request->getAttribute('ownerScope');
			expect($scope)->toBeInstanceOf(OwnerScope::class);
			expect($scope->property)->toBe('owner');
			expect($scope->value)->toBe('alex');
		}
	});

	it('allows object-save without a scope — the field policy stamps the owner', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'create');

		$response = $this->middleware->process(($this->requestFor)('object-save', 'showcase', null, 'POST'), $this->handler);

		expect($response)->toBe($this->allowed);
		expect($this->handled[0]->getAttribute('ownerScope'))->toBeNull();
	});

	it('refuses bulk delete, reindex and clone outright', function (): void {
		($this->owned)();
		($this->signInAs)('alex', 'update');
		($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);

		expect($this->middleware->process(($this->requestFor)('object-bulk-delete', 'showcase', null, 'POST'), $this->handler))->toBe($this->forbidden);
		expect($this->middleware->process(($this->requestFor)('collection-reindex', 'showcase', null, 'PUT'), $this->handler))->toBe($this->forbidden);
		// Even the actor's OWN object cannot be cloned: the copy would carry an
		// owner the policy never stamped.
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
		expect($this->handled[2]->getAttribute('ownerScope'))->toBeNull();
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
		$this->userValidation->method('isSuperAdmin')->willReturn(true);
		$this->objectFetcher->expects($this->never())->method('existsObject');

		expect($this->middleware->process(($this->requestFor)('object-update', 'showcase', 'site-2', 'PUT'), $this->handler))->toBe($this->allowed);
		expect($this->middleware->process(($this->requestFor)('collection-query', 'showcase'), $this->handler))->toBe($this->allowed);
		expect($this->handled[1]->getAttribute('ownerScope'))->toBeNull();
		// The actor is attached before the super-admin bypass.
		expect($this->handled[0]->getAttribute('accessActor'))->toEqual(new AccessActor('root', ''));
	});

	it('attaches the token actor to an admin bearer request it passes through', function (): void {
		($this->owned)();
		$this->accessControl->method('authorityFor')->willReturn(new UserAuthority(isAdmin: true, groups: []));
		$this->objectFetcher->expects($this->never())->method('existsObject');

		$request = ($this->requestFor)('object-update', 'showcase', 'site-2', 'PUT', ['authMethod' => 'oauth_bearer', 'oauth_user_id' => 'members:root']);

		expect($this->middleware->process($request, $this->handler))->toBe($this->allowed);
		expect($this->handled[0]->getAttribute('accessActor'))->toEqual(new AccessActor('root', 'members'));
		expect($this->handled[0]->getAttribute('accessAuthority'))->toBeNull();
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
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->operationDetector->method('detectOperation')->willReturn('update');
		$this->accessControl->method('canAccessCollection')->willReturn(true);
		($this->storedObject)(['id' => 'site-2', 'owner' => 'sam']);

		expect($this->middleware->process(($this->requestFor)('object-update', 'showcase', 'site-2', 'PUT'), $this->handler))->toBe($this->forbidden);
	});

	it('scopes a non-admin OAuth bearer actor by the token subject', function (): void {
		($this->owned)();
		$this->operationDetector->method('detectOperation')->willReturn('read');
		($this->storedObject)(['id' => 'site-1', 'owner' => 'alex']);
		$this->accessControl->method('authorityFor')->willReturn(($this->bearerAuthority)());

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
		$this->userValidation->method('isSuperAdmin')->willReturn(false);
		$this->operationDetector->method('detectOperation')->willReturn('delete');
		$this->accessControl->method('canAccessCollection')->willReturn(false);
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
			$this->accessControl->method('authorityFor')->willReturn(($this->bearerAuthority)());
			$this->userValidation->method('isSuperAdmin')->willReturn(false);

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
			$this->userValidation->method('isSuperAdmin')->willReturn(true);

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

		// Imports upsert whole objects with no field policy, so on a collection
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

		// A counter tick is not a content edit: it must not send an approved
		// object back to draft. propertyAllows() still guards a protected counter.
		it('remembers nothing for a counter increment or decrement on the members own object', function (): void {
			($this->owned)();
			($this->signInAs)('alex', 'update');
			($this->storedObject)(['id' => 'alex-site', 'owner' => 'alex', 'draft' => false]);

			foreach (['property-increment', 'property-decrement'] as $routeName) {
				$this->forcedValues->clear();
				$response = $this->middleware->process(($this->requestFor)($routeName, 'showcase', 'alex-site', 'POST', ['__property' => 'shots']), $this->handler);

				expect($response)->toBe($this->allowed, "route: $routeName");
				expect($this->forcedValues->forcedFor('showcase', 'alex-site'))->toBe([], "route: $routeName");
			}
		});

		it('remembers the forced values for an upload to the members own object', function (): void {
			($this->owned)();
			($this->signInAs)('alex', 'update');
			($this->storedObject)(['id' => 'alex-site', 'owner' => 'alex', 'draft' => false]);

			$response = $this->middleware->process(($this->requestFor)('upload-post', 'showcase', 'alex-site', 'POST', ['__property' => 'shots']), $this->handler);

			expect($response)->toBe($this->allowed);
			expect($this->forcedValues->forcedFor('showcase', 'alex-site'))->toBe(['draft' => true]);
		});

		it('remembers them on deck-item-create and property-delete too', function (): void {
			($this->owned)();
			($this->signInAs)('alex', 'update');
			($this->storedObject)(['id' => 'alex-site', 'owner' => 'alex', 'draft' => false]);

			foreach (['deck-item-create' => 'POST', 'property-delete' => 'DELETE'] as $routeName => $method) {
				$this->forcedValues->clear();
				$response = $this->middleware->process(($this->requestFor)($routeName, 'showcase', 'alex-site', $method, ['__property' => 'shots']), $this->handler);

				expect($response)->toBe($this->allowed, "route: $routeName");
				expect($this->forcedValues->forcedFor('showcase', 'alex-site'))->toBe(['draft' => true], "route: $routeName");
			}
		});

		it('remembers nothing for a read', function (): void {
			($this->owned)();
			($this->signInAs)('alex', 'read');
			($this->storedObject)(['id' => 'alex-site', 'owner' => 'alex', 'draft' => false]);

			$response = $this->middleware->process(($this->requestFor)('object-fetch', 'showcase', 'alex-site'), $this->handler);

			expect($response)->toBe($this->allowed);
			expect($this->forcedValues->forcedFor('showcase', 'alex-site'))->toBe([]);
		});

		it('remembers nothing for a denied request', function (): void {
			($this->owned)();
			($this->signInAs)('alex', 'update');
			($this->storedObject)(['id' => 'sam-site', 'owner' => 'sam', 'draft' => false]);

			$response = $this->middleware->process(($this->requestFor)('upload-post', 'showcase', 'sam-site', 'POST', ['__property' => 'shots']), $this->handler);

			expect($response)->toBe($this->forbidden);
			expect($this->forcedValues->forcedFor('showcase', 'sam-site'))->toBe([]);
		});

		it('remembers nothing for object-save, which has no id', function (): void {
			($this->owned)();
			($this->signInAs)('alex', 'create');
			($this->storedObject)(null);

			$response = $this->middleware->process(($this->requestFor)('object-save', 'showcase', null, 'POST'), $this->handler);

			expect($response)->toBe($this->allowed);
			expect($this->forcedValues->forcedFor('showcase', ''))->toBe([]);
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
});
