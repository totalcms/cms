<?php

use function Nekofar\Slim\Pest\postJson;

function objectTestData(): array
{
    $json = file_get_contents(__DIR__ . '/../test-data/new-object.json');

    return json_decode($json, true);
}

beforeEach(function (): void {
    $app = require __DIR__ . '/../../config/bootstrap.php';
    $this->setUpApp($app);
});

it('saves a new object', function (): void {
    // $object = objectTestData();
    // $id     = $object['id'];
    // postJson('/collections', $object)
    //     ->assertOk()
    //     ->assertJson()
    //     ->assertJsonFragment([
    //         'id' => $id,
    //     ]);

    // $this->assertFileExists(__DIR__ . "/../tcms-data/{$id}/.meta.json");
});

// TODO: Don't forget to test Collection Index here
