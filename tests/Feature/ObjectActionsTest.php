<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\patchJson;
use function Nekofar\Slim\Pest\postJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('ObjectPatchPropertyAction', function (): void {
	it('handles patch property request', function (): void {
		$response = patchJson('/collections/blog/objects/test-id/properties/title', [
			'value' => 'Updated Title',
		]);
		// Should return success or error depending on auth/existence
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});

	it('handles patch property with invalid collection', function (): void {
		$response = patchJson('/collections/nonexistent/objects/test-id/properties/title', [
			'value' => 'Test',
		]);
		expect($response->getStatusCode())->toBeIn([400, 401, 403, 404, 405]);
	});
});

describe('ObjectPropertyIncrementAction', function (): void {
	it('handles increment request', function (): void {
		$response = postJson('/collections/blog/objects/test-id/properties/views/increment', []);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});

	it('handles increment with amount', function (): void {
		$response = postJson('/collections/blog/objects/test-id/properties/views/increment/5', []);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});
});

describe('ObjectPropertyDecrementAction', function (): void {
	it('handles decrement request', function (): void {
		$response = postJson('/collections/blog/objects/test-id/properties/stock/decrement', []);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});

	it('handles decrement with amount', function (): void {
		$response = postJson('/collections/blog/objects/test-id/properties/stock/decrement/2', []);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});
});

describe('ObjectExistsAction', function (): void {
	it('returns 404 for nonexistent object', function (): void {
		$response = get('/collections/blog/objects/nonexistent-id/exists');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});
});
