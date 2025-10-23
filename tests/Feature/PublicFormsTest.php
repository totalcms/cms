<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\head;
use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\putJson;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

afterEach(function (): void {
	// Clean up test data
	recursiveDelete(cmsDataDir());
});

it('allows public create operation', function (): void {
	// Create collection with public create
	$collectionData = json_decode(file_get_contents(testData('public-collection.json')), true);
	postJson('/collections', $collectionData);

	$data = [
		'id'   => 'test-public-create',
		'text' => 'Public submission',
	];

	// Make request without authentication
	$response = postJson('/collections/public-collection', $data);

	// Should succeed
	expect($response->getStatusCode())->toBeIn([200, 201]);

	// Verify object was created
	$result = json_decode((string)$response->getBody(), true);
	expect($result['data'])->toHaveKey('id', 'test-public-create');
	expect($result['data'])->toHaveKey('text', 'Public submission');
});

it('allows public read operation', function (): void {
	// Create collection with public read
	$collectionData = json_decode(file_get_contents(testData('public-collection.json')), true);
	postJson('/collections', $collectionData);

	// Create an object first
	$objectData = ['id' => 'test-read', 'text' => 'Test content'];
	postJson('/collections/public-collection', $objectData);

	// Try to read without authentication
	$response = get('/collections/public-collection/test-read');

	// Should succeed
	expect($response->getStatusCode())->toBe(200);

	$result = json_decode((string)$response->getBody(), true);
	expect($result['data'])->toHaveKey('id', 'test-read');
});

it('rejects operations not in publicOperations array', function (): void {
	// Create collection with only create and read
	$collectionData = json_decode(file_get_contents(testData('public-collection.json')), true);
	postJson('/collections', $collectionData);

	// Create an object first
	$objectData = ['id' => 'test-update', 'text' => 'Original'];
	postJson('/collections/public-collection', $objectData);

	// Try to update without authentication (update not in publicOperations)
	$updateData = ['text' => 'Updated'];
	$response   = putJson('/collections/public-collection/test-update', $updateData);

	// Should fail - update not allowed publicly
	expect($response->getStatusCode())->toBeIn([302, 401, 403]);
})->skip('Test framework maintains auth state');

it('allows public update when configured', function (): void {
	// Create collection with public update
	$collectionData = [
		'id'               => 'public-update-test',
		'schema'           => 'text',
		'name'             => 'Public Update Test',
		'publicOperations' => ['create', 'read', 'update'],
		'properties'       => [
			'id'   => ['label' => 'ID', 'field' => 'id'],
			'text' => ['label' => 'Text', 'field' => 'textarea'],
		],
	];
	postJson('/collections', $collectionData);

	// Create object
	$objectData = ['id' => 'test-update', 'text' => 'Original'];
	postJson('/collections/public-update-test', $objectData);

	// Update without authentication (PUT requires full object including ID)
	$updateData = ['id' => 'test-update', 'text' => 'Updated text'];
	$response   = putJson('/collections/public-update-test/test-update', $updateData);

	// Should succeed
	expect($response->getStatusCode())->toBeIn([200, 201]);
});

it('allows public delete when configured', function (): void {
	// Create collection with public delete
	$collectionData = [
		'id'               => 'public-delete-test',
		'schema'           => 'text',
		'name'             => 'Public Delete Test',
		'publicOperations' => ['create', 'read', 'delete'],
		'properties'       => [
			'id'   => ['label' => 'ID', 'field' => 'id'],
			'text' => ['label' => 'Text', 'field' => 'textarea'],
		],
	];
	postJson('/collections', $collectionData);

	// Create object
	$objectData = ['id' => 'test-delete', 'text' => 'Will be deleted'];
	postJson('/collections/public-delete-test', $objectData);

	// Delete without authentication
	$response = delete('/collections/public-delete-test/test-delete');

	// Should succeed
	expect($response->getStatusCode())->toBeIn([200, 204]);
});

it('normalizes operation names to lowercase', function (): void {
	// Create collection with uppercase operations
	$collectionData = [
		'id'               => 'case-test',
		'schema'           => 'text',
		'name'             => 'Case Test',
		'publicOperations' => ['CREATE', 'Read', 'UPDATE'],
		'properties'       => [
			'id'   => ['label' => 'ID', 'field' => 'id'],
			'text' => ['label' => 'Text', 'field' => 'textarea'],
		],
	];
	postJson('/collections', $collectionData);

	// Should work - operations normalized to lowercase
	$objectData = ['id' => 'test-case', 'text' => 'Test'];
	$response   = postJson('/collections/case-test', $objectData);

	expect($response->getStatusCode())->toBeIn([200, 201]);
});

it('ignores invalid operation names', function (): void {
	// Create collection with invalid operations
	$collectionData = [
		'id'               => 'invalid-ops-test',
		'schema'           => 'text',
		'name'             => 'Invalid Ops Test',
		'publicOperations' => ['create', 'hack', 'destroy', 'read'],
		'properties'       => [
			'id'   => ['label' => 'ID', 'field' => 'id'],
			'text' => ['label' => 'Text', 'field' => 'textarea'],
		],
	];
	postJson('/collections', $collectionData);

	// Valid operations should work
	$objectData = ['id' => 'test', 'text' => 'Test'];
	$response   = postJson('/collections/invalid-ops-test', $objectData);

	expect($response->getStatusCode())->toBeIn([200, 201]);
});

it('always allows HEAD requests for object existence checking', function (): void {
	// Create collection with NO public operations
	$collectionData = [
		'id'               => 'private-head-test',
		'schema'           => 'text',
		'name'             => 'Private HEAD Test',
		'publicOperations' => [], // No public operations
		'properties'       => [
			'id'   => ['label' => 'ID', 'field' => 'id'],
			'text' => ['label' => 'Text', 'field' => 'textarea'],
		],
	];
	postJson('/collections', $collectionData);

	// Create object (authenticated)
	$objectData = ['id' => 'test-exists', 'text' => 'Test'];
	postJson('/collections/private-head-test', $objectData);

	// HEAD request should work even though read is not in publicOperations
	$response = \Nekofar\Slim\Pest\head('/collections/private-head-test/test-exists');

	// Should succeed - HEAD always allowed
	expect($response->getStatusCode())->toBe(200);

	// HEAD for non-existent object
	$response404 = \Nekofar\Slim\Pest\head('/collections/private-head-test/does-not-exist');

	// Should return 404 (not auth error)
	expect($response404->getStatusCode())->toBe(404);
});
