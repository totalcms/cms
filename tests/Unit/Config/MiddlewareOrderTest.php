<?php

declare(strict_types=1);

// Slim applies middleware LIFO: whatever is added LAST wraps everything added
// before it. Two orderings in config/middleware.php are load-bearing enough
// that getting them wrong produces a silent, hard-to-trace bug rather than a
// failure, so they are pinned here.
//
// The one that actually shipped broken: CacheInvalidationMiddleware sat inside
// the routing layer, so it only ran for requests Slim could route — admin and
// API. A Site Builder page has no Slim route, so RoutingMiddleware threw a 404
// that unwound outward before the invalidation middleware was ever reached, and
// PageRouterMiddleware then rendered the page from the very cache the signal was
// asking it to drop. `tcms cache:clear` looked like a no-op on the front end,
// and only started working once someone happened to load /admin.

describe('middleware registration order', function (): void {
	beforeEach(function (): void {
		$this->source = file_get_contents(__DIR__ . '/../../../config/middleware.php');
		expect($this->source)->toBeString();
	});

	// Registrations use the imported short class names, e.g. `$app->add(FooMiddleware::class)`.
	$positionOf = function (string $source, string $class): int {
		$offset = strpos($source, "\$app->add({$class}::class)");
		expect($offset)->not->toBeFalse("{$class} is not registered in config/middleware.php");

		return (int)$offset;
	};

	test('CacheInvalidationMiddleware wraps PageRouterMiddleware', function () use ($positionOf): void {
		// Added later == wraps == runs first. The invalidation signal has to be
		// replayed before PageRouter renders anything from cache.
		expect($positionOf($this->source, 'CacheInvalidationMiddleware'))
			->toBeGreaterThan($positionOf($this->source, 'PageRouterMiddleware'));
	});

	test('CacheInvalidationMiddleware is registered outside the routing layer', function () use ($positionOf): void {
		// Anything registered before addRoutingMiddleware() is skipped entirely
		// when routing 404s — which is every Site Builder page request.
		$routing = strpos($this->source, '$app->addRoutingMiddleware()');
		expect($routing)->not->toBeFalse();

		expect($positionOf($this->source, 'CacheInvalidationMiddleware'))
			->toBeGreaterThan((int)$routing);
	});

	test('LazySessionStartMiddleware wraps PageRouterMiddleware', function () use ($positionOf): void {
		// Pre-existing invariant, documented inline in config/middleware.php: if
		// the session closes before PageRouter does its post-Slim work, per-page
		// auth checks see an empty session and /admin redirect-loops.
		expect($positionOf($this->source, 'LazySessionStartMiddleware'))
			->toBeGreaterThan($positionOf($this->source, 'PageRouterMiddleware'));
	});
});
