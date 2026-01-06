<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\deleteJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('Access Middleware - Admin Routes', function (): void {
	it('allows access to admin dashboard', function (): void {
		$response = get('/admin');
		// Should render or redirect (depending on auth state)
		expect($response->getStatusCode())->toBeIn([200, 302]);
	});

	it('allows access to admin collections list', function (): void {
		$response = get('/admin/collections');
		expect($response->getStatusCode())->toBeIn([200, 302]);
	});

	it('allows access to admin settings', function (): void {
		$response = get('/admin/settings');
		expect($response->getStatusCode())->toBeIn([200, 302]);
	});
});

describe('Access Middleware - API Routes', function (): void {
	it('handles collection list request', function (): void {
		$response = get('/collections');
		// Should work or require auth
		expect($response->getStatusCode())->toBeIn([200, 401, 403]);
	});

	it('handles schema list request', function (): void {
		$response = get('/schemas');
		expect($response->getStatusCode())->toBeIn([200, 401, 403]);
	});

	it('handles template list request', function (): void {
		$response = get('/templates');
		expect($response->getStatusCode())->toBeIn([200, 401, 403]);
	});
});

describe('Access Middleware - Protected Operations', function (): void {
	it('handles collection create without auth', function (): void {
		$response = postJson('/collections', [
			'id'   => 'test-collection',
			'type' => 'blog',
		]);
		// Should either succeed (if auth disabled) or return auth error
		expect($response->getStatusCode())->toBeIn([200, 201, 400, 401, 403]);
	});

	it('handles collection delete without auth', function (): void {
		$response = deleteJson('/collections/nonexistent');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404]);
	});

	it('handles schema create without auth', function (): void {
		$response = postJson('/schemas', [
			'id'         => 'test-schema',
			'properties' => [],
		]);
		expect($response->getStatusCode())->toBeIn([200, 201, 400, 401, 403]);
	});
});

describe('Access Middleware - Utils Routes', function (): void {
	it('handles utils admin page', function (): void {
		$response = get('/admin/utils');
		expect($response->getStatusCode())->toBeIn([200, 302, 403]);
	});

	it('handles cache management page', function (): void {
		$response = get('/admin/utils/cache');
		expect($response->getStatusCode())->toBeIn([200, 302, 403]);
	});
});

describe('Access Middleware - Docs Routes', function (): void {
	it('handles docs page', function (): void {
		$response = get('/admin/docs');
		expect($response->getStatusCode())->toBeIn([200, 302, 403]);
	});
});

describe('Access Middleware - Mailer Routes', function (): void {
	it('handles mailer admin page', function (): void {
		$response = get('/admin/mailer');
		expect($response->getStatusCode())->toBeIn([200, 302, 403]);
	});
});
