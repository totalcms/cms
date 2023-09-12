<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;

function blogTestData(): array
{
    $json = file_get_contents(__DIR__ . '/../test-data/new-blogpost.json');

    return json_decode($json, true);
}

beforeEach(function (): void {
    $app = require __DIR__ . '/../../config/bootstrap.php';
    $this->setUpApp($app);
});

it('saves a new object', function (): void {
    $collection = 'blog';

    get("/collections/{$collection}")
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment([
            'id' => $collection,
        ]);

    $this->assertFileExists(__DIR__ . '/../tcms-data/blog/.meta.json');

    $post = blogTestData();
    $id   = $post['id'];

    // dd($post);

    postJson("/collections/{$collection}", $post)
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment([
            'id' => $id,
        ]);

    $this->assertFileExists(__DIR__ . "/../tcms-data/blog/{$id}.json");
})->only();

// TODO: Don't forget to test Collection Index here
