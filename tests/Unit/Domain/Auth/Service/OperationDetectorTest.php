<?php

declare(strict_types=1);

use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use TotalCMS\Domain\Auth\Service\OperationDetector;

/**
 * OperationDetector is the central route-name → CRUD-operation mapping used by
 * BaseAccessMiddleware and DualAuthMiddleware for permission checks.
 * A typo in the arrays silently grants/denies permission to every route in the
 * system, so the mappings need regression coverage.
 */
describe('OperationDetector', function (): void {
	beforeEach(function (): void {
		$this->detector = new OperationDetector();

		// Bound to $this so protected createMock() is accessible.
		$this->requestWithRoute = function (?string $routeName): ServerRequestInterface {
			$routeAttr = null;
			if ($routeName !== null) {
				$route = $this->createMock(RouteInterface::class);
				$route->method('getName')->willReturn($routeName);
				$routeAttr = $route;
			}

			$request = $this->createMock(ServerRequestInterface::class);
			$request->method('getAttribute')
				->willReturnMap([
					[RouteContext::ROUTE, null, $routeAttr],
					[RouteContext::ROUTE_PARSER, null, $this->createMock(RouteParserInterface::class)],
					[RouteContext::ROUTING_RESULTS, null, $this->createMock(RoutingResults::class)],
					[RouteContext::BASE_PATH, null, null],
				]);

			return $request;
		};
	});

	// --- detectOperation ---

	test('detectOperation returns create for create routes', function (): void {
		foreach (['object-save', 'schema-save', 'template-save', 'playground-save'] as $routeName) {
			$request = ($this->requestWithRoute)($routeName);
			expect($this->detector->detectOperation($request))->toBe('create', "route: $routeName");
		}
	});

	test('detectOperation returns read for read routes', function (): void {
		foreach (['schema-fetch', 'collection-fetch', 'template-list', 'report-fields'] as $routeName) {
			$request = ($this->requestWithRoute)($routeName);
			expect($this->detector->detectOperation($request))->toBe('read', "route: $routeName");
		}
	});

	test('detectOperation returns update for update routes', function (): void {
		foreach (['object-update', 'object-patch', 'schema-update', 'collection-reindex'] as $routeName) {
			$request = ($this->requestWithRoute)($routeName);
			expect($this->detector->detectOperation($request))->toBe('update', "route: $routeName");
		}
	});

	test('detectOperation returns delete for delete routes', function (): void {
		foreach (['object-delete', 'schema-delete', 'collection-delete', 'template-delete'] as $routeName) {
			$request = ($this->requestWithRoute)($routeName);
			expect($this->detector->detectOperation($request))->toBe('delete', "route: $routeName");
		}
	});

	test('detectOperation prefers CREATE when a route appears in both create and read (e.g. object-save)', function (): void {
		// `object-save` lives in both CREATE_ROUTES and (duplicated) others;
		// the resolver checks create first, so it should win.
		$request = ($this->requestWithRoute)('object-save');
		expect($this->detector->detectOperation($request))->toBe('create');
	});

	test('detectOperation returns null for an unknown route name', function (): void {
		$request = ($this->requestWithRoute)('some-unknown-route');
		expect($this->detector->detectOperation($request))->toBeNull();
	});

	test('detectOperation returns null when the request has no route', function (): void {
		$request = ($this->requestWithRoute)(null);
		expect($this->detector->detectOperation($request))->toBeNull();
	});

	test('detectOperation returns null when the route name is empty', function (): void {
		$request = ($this->requestWithRoute)('');
		expect($this->detector->detectOperation($request))->toBeNull();
	});

	// --- detectPublicOperation ---

	test('detectPublicOperation returns the CRUD op for public routes', function (): void {
		$request = ($this->requestWithRoute)('object-save');
		expect($this->detector->detectPublicOperation($request))->toBe('create');

		$request = ($this->requestWithRoute)('object-fetch');
		expect($this->detector->detectPublicOperation($request))->toBe('read');

		$request = ($this->requestWithRoute)('object-delete');
		expect($this->detector->detectPublicOperation($request))->toBe('delete');
	});

	test('detectPublicOperation returns null for routes that are not public', function (): void {
		// schema-save is CREATE but not in PUBLIC_ROUTES
		$request = ($this->requestWithRoute)('schema-save');
		expect($this->detector->detectPublicOperation($request))->toBeNull();

		// template-list is READ but not in PUBLIC_ROUTES
		$request = ($this->requestWithRoute)('template-list');
		expect($this->detector->detectPublicOperation($request))->toBeNull();
	});

	test('detectPublicOperation returns null for unknown routes', function (): void {
		$request = ($this->requestWithRoute)('definitely-not-a-route');
		expect($this->detector->detectPublicOperation($request))->toBeNull();
	});

	test('detectPublicOperation returns null when request has no route', function (): void {
		$request = ($this->requestWithRoute)(null);
		expect($this->detector->detectPublicOperation($request))->toBeNull();
	});

	// --- Spot checks on a few historically-tricky mappings ---

	test('deck-item-create is classified as UPDATE (it modifies the parent object)', function (): void {
		$request = ($this->requestWithRoute)('deck-item-create');
		expect($this->detector->detectOperation($request))->toBe('update');
	});

	test('deck-item-delete is classified as UPDATE (it modifies the parent object)', function (): void {
		$request = ($this->requestWithRoute)('deck-item-delete');
		expect($this->detector->detectOperation($request))->toBe('update');
	});

	test('property-delete is UPDATE, object-delete is DELETE', function (): void {
		// property-delete removes a property from an object → object is updated
		$request = ($this->requestWithRoute)('property-delete');
		expect($this->detector->detectOperation($request))->toBe('update');

		// object-delete removes the whole object → genuine delete
		$request = ($this->requestWithRoute)('object-delete');
		expect($this->detector->detectOperation($request))->toBe('delete');
	});
});
