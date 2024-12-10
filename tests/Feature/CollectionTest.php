<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
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

	postJson('/collections', $collection)
		->assertOk()
		->assertJson()
		->assertJsonFragment($collection);

	$this->assertFileExists(metaPath($id));
});

it('does not save an existing collection', function (): void {
	$collection = collectionTestData();
	postJson('/collections', $collection)
		->assertBadRequest()
		->assertJson()
		->assertSee('already exists');
});

it('updates a collection', function (): void {
	$collection                = collectionTestData();
	$id                        = $collection['id'];
	$update                    = 'Updated description';
	$collection['description'] = $update;
	putJson('/collections/' . $id, $collection)
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
	patchJson('/collections/' . $id, $patch)
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
	get("/collections/$id")
		->assertOk()
		->assertJson()
		->assertJsonFragment([
			'id' => $id,
		]);
});

it('can fetch a schema for a collection', function (): void {
	$collection = collectionTestData();
	$id         = $collection['id'];
	$schema     = $collection['schema'];
	get("/collections/{$id}/schema")
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

	delete("/collections/$id")->assertOk();
	$this->assertFileDoesNotExist(metaPath($id));
});
