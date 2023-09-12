<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\put;

function blogTestData(): array
{
    $json = file_get_contents(__DIR__ . '/../test-data/new-blogpost.json');

    return json_decode($json, true);
}

function metaPath(string $collection): string
{
    return __DIR__ . "/../tcms-data/$collection/.meta.json";
}

function indexPath(string $collection): string
{
    return __DIR__ . "/../tcms-data/$collection/.index.json";
}

function objectPath(string $collection, string $id): string
{
    return __DIR__ . "/../tcms-data/$collection/$id.json";
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

    $this->assertFileExists(metaPath($collection));

    $post = blogTestData();
    $id   = $post['id'];

    postJson("/collections/{$collection}", $post)
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment($post);

    $this->assertFileExists(objectPath($collection, $id));
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

it('the collection index gets rebuilt automatically', function (): void {
    $collection = 'blog';

    $index = indexPath($collection);
    unlink($index);

    $post = blogTestData();
    $id   = $post['id'];

    get("/collections/{$collection}/index")
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment([
            'id' => $id,
        ]);

    $this->assertFileExists($index);
});

it('the collection index gets rebuilt from api', function (): void {
    $collection = 'blog';

    $index = indexPath($collection);
    unlink($index);

    $post = blogTestData();
    $id   = $post['id'];

    put("/collections/{$collection}/index")
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment([
            'id' => $id,
        ]);

    $this->assertFileExists($index);
});

// TODO: Test image and file uploads
