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
    $json = file_get_contents(testData('new-collection.json'));

    return json_decode($json, true);
}

beforeEach(function (): void {
    $app = require __DIR__ . '/../../config/bootstrap.php';
    $this->setUpApp($app);
});

it('saves a new collection', function (): void {
    $collection = collectionTestData();
    $id         = $collection['id'];
    postJson('/collections', $collection)
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment($collection);

    $this->assertFileExists(__DIR__ . "/../tcms-data/{$id}/.meta.json");
});

it('does not save an existing collection', function (): void {
    $collection = collectionTestData();
    postJson('/collections', $collection)
        ->assertBadRequest()
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
    get("/collections/$id/schema")
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment([
            'id'      => $id,
            '$id'     => "https://www.totalcms.co/schemas/{$id}.json",
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        ]);
});

it('cannot delete a collection by design', function (): void {
    $collection = collectionTestData();
    $id         = $collection['id'];
    delete("/collections/$id")->assertMethodNotAllowed();
});
