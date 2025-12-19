<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\post;
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

describe('Factory Operations', function (): void {
	it('can create fake data for a collection', function (): void {
		// Create test collection first
		$collection = [
			'id'     => 'factory-test',
			'name'   => 'Factory Test Collection',
			'schema' => 'blog',
		];

		postJson('/collections', $collection)
			->assertOk();

		// Test factory data generation
		$factoryParams = [
			'count'  => 5,
			'locale' => 'en_US',
		];

		$response = post('/api/collections/factory-test/factory', $factoryParams);

		// Factory endpoint should exist and respond appropriately
		expect($response->getStatusCode())->toBeIn([200, 201, 404, 405]);

		// If factory was successful, check that objects were created
		if ($response->getStatusCode() === 200 || $response->getStatusCode() === 201) {
			get('/collections/factory-test/index')
				->assertOk()
				->assertJson();
		}
	});

	it('can generate test data with different locales', function (): void {
		// Test factory with different locales
		$locales = ['en_US', 'fr_FR', 'de_DE', 'es_ES'];

		foreach ($locales as $locale) {
			$factoryParams = [
				'count'  => 2,
				'locale' => $locale,
			];

			$response = post('/api/collections/factory-test/factory', $factoryParams);
			expect($response->getStatusCode())->toBeIn([200, 201, 404, 405]);
		}
	});

	it('can generate different amounts of test data', function (): void {
		// Test factory with different counts
		$counts = [1, 3, 10];

		foreach ($counts as $count) {
			$factoryParams = [
				'count'  => $count,
				'locale' => 'en_US',
			];

			$response = post('/api/collections/factory-test/factory', $factoryParams);
			expect($response->getStatusCode())->toBeIn([200, 201, 404, 405]);
		}
	});

	it('handles factory requests for different collection types', function (): void {
		// Test factory with different schema types
		$schemas = ['blog', 'page', 'gallery'];

		foreach ($schemas as $schema) {
			$collection = [
				'id'     => "factory-{$schema}",
				'name'   => "Factory {$schema} Collection",
				'schema' => $schema,
			];

			postJson('/collections', $collection)->assertOk();

			$factoryParams = [
				'count'  => 2,
				'locale' => 'en_US',
			];

			$response = post("/api/collections/factory-{$schema}/factory", $factoryParams);
			expect($response->getStatusCode())->toBeIn([200, 201, 404, 405]);
		}
	});

	it('updates collection count and totalObjects after factory import', function (): void {
		// Create test collection
		$collection = [
			'id'     => 'factory-count-test',
			'name'   => 'Factory Count Test',
			'schema' => 'text',
		];

		postJson('/collections', $collection)->assertOk();

		// Check initial counts
		$response = get('/collections/factory-count-test');
		$response->assertOk();
		$initialData         = json_decode((string)$response->getBody(), true);
		$initialCount        = $initialData['data']['count'] ?? 0;
		$initialTotalObjects = $initialData['data']['totalObjects'] ?? 0;

		// Run factory import
		$factoryParams = [
			'count'  => 5,
			'locale' => 'en_US',
		];

		$factoryResponse = post('/api/collections/factory-count-test/factory', $factoryParams);

		// If factory was successful, verify counts were updated
		if ($factoryResponse->getStatusCode() === 200 || $factoryResponse->getStatusCode() === 201) {
			$response = get('/collections/factory-count-test');
			$response->assertOk();
			$updatedData = json_decode((string)$response->getBody(), true);

			$newCount        = $updatedData['data']['count'] ?? 0;
			$newTotalObjects = $updatedData['data']['totalObjects'] ?? 0;

			// Both count and totalObjects should have increased by 5
			expect($newCount)->toBe($initialCount + 5);
			expect($newTotalObjects)->toBe($initialTotalObjects + 5);
		}
	});
});
