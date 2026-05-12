<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Service\RouteCollector;

describe('RouteCollector', function (): void {
	test('registers GET route', function (): void {
		$collector = new RouteCollector();
		$collector->get('/api/test', 'TestHandler');

		$routes = $collector->getRoutes();

		expect($routes)->toHaveCount(1);
		expect($routes[0]['method'])->toBe('GET');
		expect($routes[0]['path'])->toBe('/api/test');
		expect($routes[0]['handler'])->toBe('TestHandler');
		expect($routes[0]['public'])->toBeFalse();
	});

	test('registers POST route', function (): void {
		$collector = new RouteCollector();
		$collector->post('/api/submit', 'SubmitHandler');

		$routes = $collector->getRoutes();

		expect($routes)->toHaveCount(1);
		expect($routes[0]['method'])->toBe('POST');
		expect($routes[0]['path'])->toBe('/api/submit');
	});

	test('registers PUT route', function (): void {
		$collector = new RouteCollector();
		$collector->put('/api/update', 'UpdateHandler');

		expect($collector->getRoutes()[0]['method'])->toBe('PUT');
	});

	test('registers PATCH route', function (): void {
		$collector = new RouteCollector();
		$collector->patch('/api/patch', 'PatchHandler');

		expect($collector->getRoutes()[0]['method'])->toBe('PATCH');
	});

	test('registers DELETE route', function (): void {
		$collector = new RouteCollector();
		$collector->delete('/api/remove', 'DeleteHandler');

		expect($collector->getRoutes()[0]['method'])->toBe('DELETE');
	});

	test('any() registers all HTTP methods', function (): void {
		$collector = new RouteCollector();
		$collector->any('/api/all', 'AllHandler');

		$routes  = $collector->getRoutes();
		$methods = array_map(fn (array $r): string => $r['method'], $routes);

		expect($routes)->toHaveCount(5);
		expect($methods)->toBe(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);

		foreach ($routes as $route) {
			expect($route['path'])->toBe('/api/all');
			expect($route['handler'])->toBe('AllHandler');
		}
	});

	test('marks routes as public when constructed with isPublic', function (): void {
		$collector = new RouteCollector(isPublic: true);
		$collector->get('/status', 'StatusHandler');

		expect($collector->getRoutes()[0]['public'])->toBeTrue();
	});

	test('marks routes as private by default', function (): void {
		$collector = new RouteCollector();
		$collector->get('/api/data', 'DataHandler');

		expect($collector->getRoutes()[0]['public'])->toBeFalse();
	});

	test('collects multiple routes', function (): void {
		$collector = new RouteCollector();
		$collector->get('/list', 'ListHandler');
		$collector->post('/create', 'CreateHandler');
		$collector->delete('/remove', 'RemoveHandler');

		expect($collector->getRoutes())->toHaveCount(3);
	});

	test('supports method chaining', function (): void {
		$collector = new RouteCollector();
		$result    = $collector->get('/a', 'A')->post('/b', 'B');

		expect($result)->toBeInstanceOf(RouteCollector::class);
		expect($collector->getRoutes())->toHaveCount(2);
	});

	test('starts with no routes', function (): void {
		$collector = new RouteCollector();

		expect($collector->getRoutes())->toBe([]);
	});
});
