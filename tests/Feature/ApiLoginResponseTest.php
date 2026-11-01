<?php

declare(strict_types=1);

use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;

/**
 * Session-only /api routes answer a caller without a session in kind: a JSON
 * 401 for scripts and API clients, an HX-Redirect for htmx requests from an
 * admin page whose session ran out. Admin pages still redirect to the login
 * (security scan finding 15, 2026-10-30).
 */
beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	// Auth is off in the test config; turn it on without signing anyone in.
	/** @var Config $config */
	$config         = $this->app->getContainer()->get(Config::class);
	$auth           = $config->auth;
	$auth['enable'] = true;
	$config->auth   = $auth;
});

test('an /api route answers an anonymous caller with a JSON 401', function (): void {
	$response = post('/api/extensions/install');

	expect($response->getStatusCode())->toBe(401)
		->and($response->getHeaderLine('Content-Type'))->toContain('application/json')
		->and($response->getHeaderLine('Location'))->toBe('')
		->and(json_decode((string)$response->getBody(), true))->toHaveKey('error');
});

test('an htmx request to an /api route is sent to the login page', function (): void {
	$response = post('/api/extensions/install', [], [
		'HX-Request'     => 'true',
		'HX-Current-URL' => 'http://localhost/admin/extensions',
	]);

	expect($response->getStatusCode())->toBe(401)
		->and($response->getHeaderLine('HX-Redirect'))->toEndWith('/admin/login');
});

test('an admin page still redirects to the login page', function (): void {
	$response = get('/admin/collections');

	expect($response->getStatusCode())->toBe(302)
		->and($response->getHeaderLine('Location'))->toEndWith('/admin/login');
});
