<?php

declare(strict_types=1);
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\postJson;

/**
 * `POST /api/sync/import` writes collections, schemas and templates, so a
 * signed-in session must be an admin to use it (like `GET /api/sync/export`
 * and `/api/import/jumpstart`). API keys bypass AdminOnly and are covered by
 * their own path grant.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
});

describe('POST /api/sync/import access', function (): void {
	$payload = static fn (): array => ['name' => 'Sync access check'];

	it('refuses a signed-in non-admin session', function () use ($payload): void {
		signInAs($this->app, 'editor-user-test-com', '');

		postJson('/api/sync/import', $payload())->assertForbidden();
	});

	it('lets an admin session through', function () use ($payload): void {
		signInAs($this->app, 'admin-user-test-com', 'auth');

		$response = postJson('/api/sync/import', $payload());

		expect($response->getStatusCode())->toBeLessThan(300);
	});

	it('refuses an anonymous request', function () use ($payload): void {
		$config         = $this->app->getContainer()->get(Config::class);
		$auth           = $config->auth;
		$auth['enable'] = true;
		$config->auth   = $auth;

		postJson('/api/sync/import', $payload())->assertStatus(401);
	});
});
