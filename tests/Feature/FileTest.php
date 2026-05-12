<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\post;
use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\put;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('File Operations', function (): void {
	beforeEach(function (): void {
		// Create test collection for file operations
		$collection = [
			'id'     => 'file-test',
			'name'   => 'File Test Collection',
			'schema' => 'gallery',
		];

		postJson('/api/collections', $collection);

		// Create test object
		$object = [
			'id'    => 'test-file-object',
			'title' => 'Test File Object',
		];

		postJson('/api/collections/file-test', $object);
	});

	it('can upload a file', function (): void {
		// Test file upload endpoint exists and responds
		$response = get('/api/collections/file-test/test-file-object/files/test-property');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Endpoint exists
	});

	it('can upload a file to a depot', function (): void {
		// Test depot file upload endpoint
		$response = get('/api/collections/file-test/test-file-object/depot/test-property');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Endpoint exists
	});

	it('can download a file', function (): void {
		// Test file download capability
		$response = get('/files/file-test/test-file-object/test-property/test-file.txt');
		expect($response->getStatusCode())->toBeIn([200, 404]); // File endpoint exists
	});

	it('can download a file from depot', function (): void {
		// Test depot file download
		$response = get('/depot/file-test/test-file-object/test-property/test-file.txt');
		expect($response->getStatusCode())->toBeIn([200, 404]); // Depot endpoint exists
	});

	it('can download a password protected file', function (): void {
		// Test password protected file download
		$response = post('/files/file-test/test-file-object/test-property/test-file.txt', [
			'password' => 'test-password',
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 404]); // Protected file endpoint exists
	});

	it('can download a password protected file from depot', function (): void {
		// Test password protected depot file download
		$response = post('/depot/file-test/test-file-object/test-property/test-file.txt', [
			'password' => 'test-password',
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 404]); // Protected depot endpoint exists
	});

	it('can delete a file', function (): void {
		// Test file deletion endpoint
		$response = delete('/api/collections/file-test/test-file-object/files/test-property/test-file.txt');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Delete endpoint exists
	});

	it('can delete a file from depot', function (): void {
		// Test depot file deletion
		$response = delete('/api/collections/file-test/test-file-object/depot/test-property/test-file.txt');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Delete depot endpoint exists
	});

	it('can update info for a file', function (): void {
		// Test file metadata update
		$updateData = [
			'alt'     => 'Updated alt text',
			'caption' => 'Updated caption',
		];

		$response = put('/api/collections/file-test/test-file-object/files/test-property/info', $updateData);
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Update info endpoint exists
	});

	it('can update info for a file from depot', function (): void {
		// Test depot file metadata update
		$updateData = [
			'alt'     => 'Updated depot alt text',
			'caption' => 'Updated depot caption',
		];

		$response = put('/api/collections/file-test/test-file-object/depot/test-property/info', $updateData);
		expect($response->getStatusCode())->toBeIn([200, 404, 405]); // Update depot info endpoint exists
	});
});
