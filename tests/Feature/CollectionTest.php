<?php

use function Nekofar\Slim\Pest\postJson;

function collectionTestData(): array
{
    $json = file_get_contents(__DIR__ . '/../test-data/new-collection.json');

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
        ->assertJsonFragment([
            'id' => $id,
        ]);

    $this->assertFileExists(__DIR__ . "/../tcms-data/{$id}/.meta.json");
});
