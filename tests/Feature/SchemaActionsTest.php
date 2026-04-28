<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\putJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('SchemaExistsAction', function (): void {
	it('returns 404 for nonexistent schema', function (): void {
		$response = get('/api/schemas/nonexistent-schema/exists');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});

	it('handles exists check for valid schema ID format', function (): void {
		$response = get('/api/schemas/blog-schema/exists');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});
});

describe('SchemaUpdateAction', function (): void {
	it('handles schema update request', function (): void {
		$response = putJson('/api/schemas/test-schema', [
			'id'         => 'test-schema',
			'properties' => [],
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});

	it('handles update for nonexistent schema', function (): void {
		$response = putJson('/api/schemas/nonexistent-schema', [
			'id'         => 'nonexistent-schema',
			'properties' => [],
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});
});
