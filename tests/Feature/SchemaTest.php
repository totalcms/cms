<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;

function schemaTestData(): array
{
    $json = file_get_contents(__DIR__ . '/../test-data/new-schema.json');

    return json_decode($json, true);
}

beforeEach(function (): void {
    $app = require __DIR__ . '/../../config/bootstrap.php';
    $this->setUpApp($app);
});

it('saves a new schema', function (): void {
    $schema = schemaTestData();
    $id     = $schema['id'];
    postJson('/schemas/', $schema)
        ->assertOk()
        ->assertJsonFragment([
            'id'      => $id,
            '$id'     => "https://www.totalcms.co/schemas/{$id}.json",
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        ]);
});

it('cannot save a reserved schema', function (): void {
    $reservedSchemas = glob(__DIR__ . '/../../schemas/*.json');
    expect($reservedSchemas)->toBeArray()->not->toBeEmpty();
    foreach ($reservedSchemas as $schema) {
        $id = basename($schema, '.json');
        postJson('/schemas/', ['id' => $id])
            ->assertStatus(500)
            ->assertSee('is reserved');
    }
});

it('fetches a schema', function (): void {
    $schema = schemaTestData();
    $id     = $schema['id'];
    get("/schemas/$id")
        ->assertOk()
        ->assertJsonFragment([
            'id'      => $id,
            '$id'     => "https://www.totalcms.co/schemas/{$id}.json",
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        ]);
});

// TODO: Get all schemas, reserved schemas, custom schemas, delete schema

// it('gets available schemas', function (): void {
//     get('/schemas')
//         ->assertOk();
// });
