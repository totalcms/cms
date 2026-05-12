<?php

use function Nekofar\Slim\Pest\deleteJson;
use function Nekofar\Slim\Pest\getJson;
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

describe('Collection Metadata Updates', function (): void {
	it('increments totalObjects when creating an object', function (): void {
		// Create collection
		$collection = [
			'id'     => 'metadata-test',
			'name'   => 'Metadata Test',
			'schema' => 'blog',
		];

		postJson('/api/collections', $collection)->assertOk();

		// Get initial collection state
		$initialResponse = getJson('/api/collections/metadata-test')->assertOk();
		$responseData    = json_decode((string)$initialResponse->getBody(), true);
		$initialData     = $responseData['data'];

		expect($initialData['totalObjects'])->toBe(0);

		// Create first object
		$object1 = [
			'title'   => 'Test Post 1',
			'content' => 'Content 1',
		];

		postJson('/api/collections/metadata-test', $object1)->assertOk();

		// Check totalObjects incremented
		$afterCreate1 = getJson('/api/collections/metadata-test')->assertOk();
		$response1    = json_decode((string)$afterCreate1->getBody(), true);
		$data1        = $response1['data'];
		expect($data1['totalObjects'])->toBe(1);
		expect($data1['lastUpdated'])->not()->toBe('');

		$lastUpdated1 = $data1['lastUpdated'];

		// Create second object
		$object2 = [
			'title'   => 'Test Post 2',
			'content' => 'Content 2',
		];

		postJson('/api/collections/metadata-test', $object2)->assertOk();

		// Check totalObjects incremented again
		$afterCreate2 = getJson('/api/collections/metadata-test')->assertOk();
		$response2    = json_decode((string)$afterCreate2->getBody(), true);
		$data2        = $response2['data'];
		expect($data2['totalObjects'])->toBe(2);
		expect($data2['lastUpdated'])->not()->toBe(''); // Should be set
	});

	it('decrements totalObjects when deleting an object', function (): void {
		// Create collection with objects
		$collection = [
			'id'     => 'delete-test',
			'name'   => 'Delete Test',
			'schema' => 'blog',
		];

		postJson('/api/collections', $collection)->assertOk();

		// Create three objects
		$object1 = postJson('/api/collections/delete-test', ['title' => 'Post 1', 'content' => 'Content 1'])->assertOk();
		$object2 = postJson('/api/collections/delete-test', ['title' => 'Post 2', 'content' => 'Content 2'])->assertOk();
		$object3 = postJson('/api/collections/delete-test', ['title' => 'Post 3', 'content' => 'Content 3'])->assertOk();

		$obj1Response = json_decode((string)$object1->getBody(), true);
		$obj1Data     = $obj1Response['data'] ?? $obj1Response;
		$obj1Id       = $obj1Data['id'];

		// Verify we have 3 objects
		$before         = getJson('/api/collections/delete-test')->assertOk();
		$beforeResponse = json_decode((string)$before->getBody(), true);
		$beforeData     = $beforeResponse['data'];
		expect($beforeData['totalObjects'])->toBe(3);

		// Delete one object
		deleteJson("/api/collections/delete-test/{$obj1Id}")->assertOk();

		// Check totalObjects decremented
		$after         = getJson('/api/collections/delete-test')->assertOk();
		$afterResponse = json_decode((string)$after->getBody(), true);
		$afterData     = $afterResponse['data'];
		expect($afterData['totalObjects'])->toBe(2);
		expect($afterData['lastUpdated'])->not()->toBe('');
	});

	it('updates lastUpdated when updating an object', function (): void {
		// Create collection
		$collection = [
			'id'     => 'update-test',
			'name'   => 'Update Test',
			'schema' => 'blog',
		];

		postJson('/api/collections', $collection)->assertOk();

		// Create object
		$createResponse = postJson('/api/collections/update-test', [
			'title'   => 'Original Title',
			'content' => 'Original Content',
		])->assertOk();

		$objResponse = json_decode((string)$createResponse->getBody(), true);
		$objData     = $objResponse['data'] ?? $objResponse;
		$objId       = $objData['id'];

		// Get initial lastUpdated
		$initial            = getJson('/api/collections/update-test')->assertOk();
		$initialResponse    = json_decode((string)$initial->getBody(), true);
		$initialData        = $initialResponse['data'];
		$initialLastUpdated = $initialData['lastUpdated'];
		expect($initialData['totalObjects'])->toBe(1);

		// Update object
		putJson("/api/collections/update-test/{$objId}", [
			'id'      => $objId,
			'title'   => 'Updated Title',
			'content' => 'Updated Content',
		])->assertOk();

		// Check lastUpdated is set and totalObjects stayed same
		$after         = getJson('/api/collections/update-test')->assertOk();
		$afterResponse = json_decode((string)$after->getBody(), true);
		$afterData     = $afterResponse['data'];
		expect($afterData['totalObjects'])->toBe(1); // Should not change
		expect($afterData['lastUpdated'])->not()->toBe(''); // Should be set
	});

	it('increments totalObjects when cloning an object', function (): void {
		// Create collection
		$collection = [
			'id'     => 'clone-test',
			'name'   => 'Clone Test',
			'schema' => 'blog',
		];

		postJson('/api/collections', $collection)->assertOk();

		// Create object
		$createResponse = postJson('/api/collections/clone-test', [
			'title'   => 'Original Post',
			'content' => 'Original Content',
		])->assertOk();

		$objResponse = json_decode((string)$createResponse->getBody(), true);
		$objData     = $objResponse['data'] ?? $objResponse;
		$objId       = $objData['id'];

		// Verify we have 1 object
		$before         = getJson('/api/collections/clone-test')->assertOk();
		$beforeResponse = json_decode((string)$before->getBody(), true);
		$beforeData     = $beforeResponse['data'];
		expect($beforeData['totalObjects'])->toBe(1);

		// Clone object to same collection (just need new ID)
		postJson("/api/collections/clone-test/{$objId}/clone", [
			'id' => 'cloned-post',
		])->assertOk();

		// Check totalObjects incremented
		$after         = getJson('/api/collections/clone-test')->assertOk();
		$afterResponse = json_decode((string)$after->getBody(), true);
		$afterData     = $afterResponse['data'];
		expect($afterData['totalObjects'])->toBe(2);
		expect($afterData['lastUpdated'])->not()->toBe('');
	});

	it('maintains accurate totalObjects through multiple operations', function (): void {
		// Create collection
		$collection = [
			'id'     => 'complex-test',
			'name'   => 'Complex Test',
			'schema' => 'blog',
		];

		postJson('/api/collections', $collection)->assertOk();

		// Create 5 objects
		$ids = [];
		for ($i = 1; $i <= 5; $i++) {
			$response = postJson('/api/collections/complex-test', [
				'title'   => "Post {$i}",
				'content' => "Content {$i}",
			])->assertOk();
			$responseData = json_decode((string)$response->getBody(), true);
			$data         = $responseData['data'] ?? $responseData;
			$ids[]        = $data['id'];
		}

		// Verify count is 5
		$check1    = getJson('/api/collections/complex-test')->assertOk();
		$response1 = json_decode((string)$check1->getBody(), true);
		$data1     = $response1['data'];
		expect($data1['totalObjects'])->toBe(5);

		// Delete 2 objects
		deleteJson("/api/collections/complex-test/{$ids[0]}")->assertOk();
		deleteJson("/api/collections/complex-test/{$ids[1]}")->assertOk();

		// Verify count is 3
		$check2    = getJson('/api/collections/complex-test')->assertOk();
		$response2 = json_decode((string)$check2->getBody(), true);
		$data2     = $response2['data'];
		expect($data2['totalObjects'])->toBe(3);

		// Create 1 more object
		postJson('/api/collections/complex-test', [
			'title'   => 'Post 6',
			'content' => 'Content 6',
		])->assertOk();

		// Verify count is 4
		$check3    = getJson('/api/collections/complex-test')->assertOk();
		$response3 = json_decode((string)$check3->getBody(), true);
		$data3     = $response3['data'];
		expect($data3['totalObjects'])->toBe(4);
	});
});
