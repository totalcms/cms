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

    postJson("/collections/{$collection}", $post)
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment($post);

    $this->assertFileExists(__DIR__ . "/../tcms-data/blog/{$id}.json");
});

it('can get an object', function (): void {
    $collection = 'blog';

    $post = blogTestData();
    $id   = $post['id'];

    get("/collections/{$collection}/{$id}")
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment($post);
});

it('add an object to the collection index', function (): void {
    $collection = 'blog';

    $post = blogTestData();
    $id   = $post['id'];

    get("/collections/{$collection}/index")
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment([
            'id' => $id,
        ]);
});

// TODO: Don't forget to test Collection Index here
// TODO: Test image and file uploads
