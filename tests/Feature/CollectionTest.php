<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\head;
use function Nekofar\Slim\Pest\patchJson;
use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\putJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

function collectionTestData(): array
{
	$json = file_get_contents(testData('new-text-collection.json'));

	return json_decode($json, true);
}

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		// tests with assertBadRequest do not seem to clean up the session
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

it('saves a new collection', function (): void {
	$collection = collectionTestData();
	$id         = $collection['id'];

	postJson('/api/collections', $collection)
		->assertOk()
		->assertJson()
		->assertJsonFragment($collection);

	$this->assertFileExists(metaPath($id));
});

it('does not save an existing collection', function (): void {
	$collection = collectionTestData();
	postJson('/api/collections', $collection)
		->assertBadRequest()
		->assertJson()
		->assertSee('already exists');
});

it('updates a collection', function (): void {
	$collection                = collectionTestData();
	$id                        = $collection['id'];
	$update                    = 'Updated description';
	$collection['description'] = $update;
	putJson('/api/collections/' . $id, $collection)
		->assertOk()
		->assertJson()
		->assertJsonFragment([
			'id'          => $id,
			'description' => $update,
		]);
});

it('updates a collection fragment', function (): void {
	$collection = collectionTestData();
	$id         = $collection['id'];
	$patch      = ['description' => 'Patched description'];
	patchJson('/api/collections/' . $id, $patch)
		->assertOk()
		->assertJson()
		->assertJsonFragment([
			'id'          => $id,
			'description' => $patch['description'],
		]);
});

it('can fetch a collection', function (): void {
	$collection = collectionTestData();
	$id         = $collection['id'];
	get("/api/collections/$id")
		->assertOk()
		->assertJson()
		->assertJsonFragment([
			'id' => $id,
		]);
});

it('can list all collections', function (): void {
	$collection = collectionTestData();
	$id         = $collection['id'];

	// Ensure at least one collection exists
	$createResponse = postJson('/api/collections', $collection);
	if ($createResponse->getStatusCode() === 400) {
		// Collection already exists, which is fine
	} else {
		$createResponse->assertOk();
	}

	// Fetch the list
	get('/api/collections')
		->assertOk()
		->assertJson()
		->assertSee($id); // Simple check - just verify our collection ID appears in the JSON response
});

it('checks if a collection exists', function (): void {
	$collection = collectionTestData();
	$id         = $collection['id'];

	// Ensure the collection exists
	$createResponse = postJson('/api/collections', $collection);
	if ($createResponse->getStatusCode() === 400) {
		// Collection already exists, which is fine
	} else {
		$createResponse->assertOk();
	}

	// HEAD request should return 200 for existing collection
	head("/api/collections/$id")->assertOk();

	// HEAD request should return 404 for non-existent collection
	head('/api/collections/does-not-exist-collection')->assertNotFound();
});

it('can fetch a schema for a collection', function (): void {
	$collection = collectionTestData();
	$id         = $collection['id'];
	$schema     = $collection['schema'];

	// Create the collection if it doesn't exist (it might exist from previous tests)
	$createResponse = postJson('/api/collections', $collection);
	if ($createResponse->getStatusCode() === 400) {
		// Collection already exists, which is fine
	} else {
		$createResponse->assertOk();
	}

	// Then fetch its schema
	get("/api/collections/{$id}/schema")
		->assertOk()
		->assertJson()
		->assertJsonFragment([
			'id'  => $schema,
			'$id' => "https://www.totalcms.co/schemas/{$schema}.json",
		]);
});

it('can delete a collection', function (): void {
	$collection = collectionTestData();
	$id         = $collection['id'];

	delete("/api/collections/$id")->assertOk();
	$this->assertFileDoesNotExist(metaPath($id));
});
