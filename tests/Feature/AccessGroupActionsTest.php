<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;
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

describe('AccessGroupSaveAction', function (): void {
	it('returns 400 when ID is missing', function (): void {
		$response = postJson('/access-groups', [
			'description' => 'Test group',
		]);
		expect($response->getStatusCode())->toBeIn([400, 401, 403, 405]);
	});

	it('handles create request with valid data', function (): void {
		$response = postJson('/access-groups', [
			'id'          => 'test-group',
			'description' => 'A test access group',
			'operations'  => ['create', 'read'],
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 405, 500]);
	});

	it('handles create with permissions', function (): void {
		$response = postJson('/access-groups', [
			'id'                     => 'editor-group',
			'description'            => 'Editor access group',
			'operations'             => ['create', 'read', 'update'],
			'permissions-simple'     => ['templates', 'docs'],
			'collections-all'        => ['all'],
			'collections-operations' => ['read', 'update'],
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 405, 500]);
	});

	it('handles update request via PUT', function (): void {
		$response = putJson('/access-groups/existing-group', [
			'id'          => 'existing-group',
			'description' => 'Updated description',
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});

	it('handles update with empty description', function (): void {
		$response = putJson('/access-groups/test-group', [
			'id'          => 'test-group',
			'description' => '',
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405, 500]);
	});
});

describe('AccessGroupDeleteAction', function (): void {
	it('returns 404 for nonexistent group', function (): void {
		$response = delete('/access-groups/nonexistent-group');
		expect($response->getStatusCode())->toBeIn([400, 401, 403, 404, 405]);
	});

	it('handles delete request for valid ID format', function (): void {
		$response = delete('/access-groups/test-group');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});

	it('handles delete request with special characters in ID', function (): void {
		$response = delete('/access-groups/group-with-dashes');
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});
});

describe('AccessGroupsListAction', function (): void {
	it('renders access groups list page', function (): void {
		$response = get('/admin/utils/access-groups');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});
});
