<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\post;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

it('rejects the connection test route without an admin session', function (): void {
	$response = post('/admin/settings/mcp/connection-test');

	// Unauthenticated: redirect to login or 401/403 depending on middleware chain
	expect($response->getStatusCode())->toBeIn([302, 401, 403]);
});
