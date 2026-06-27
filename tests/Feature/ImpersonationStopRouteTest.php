<?php

declare(strict_types=1);

use function Nekofar\Slim\Pest\post;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/**
 * Regression guard for the impersonation stop route.
 *
 * Stop is registered at app level (outside the /admin group) and BEFORE the group's
 * variable catch-all (`/admin/{path:...}`). FastRoute rejects a static route that is
 * shadowed by a *previously* defined variable route, which — if this regresses — crashes
 * the router on EVERY request (500) instead of resolving. This test forces the dispatcher
 * to build and confirms the route resolves to the stop action rather than the admin 404
 * catch-all.
 */
describe('impersonation stop route', function (): void {
	it('is registered and resolves, not shadowed by the admin catch-all', function (): void {
		$response = post('/admin/impersonate/stop');

		// Resolved to the stop action (or its CSRF guard) — NOT a 404 (the admin
		// catch-all) and NOT a 500 (a router-build failure from a shadowed route).
		expect($response->getStatusCode())->not->toBe(404);
		expect($response->getStatusCode())->not->toBe(500);
	});
});
