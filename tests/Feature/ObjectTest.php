<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\head;
use function Nekofar\Slim\Pest\patchJson;
use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\put;
use function Nekofar\Slim\Pest\putJson;

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

function objectFilesPath(string $collection, string $id): string
{
    return __DIR__ . "/../tcms-data/$collection/$id";
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

it('can automativally rebuild the collection index', function (): void {
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

it('can rebuild the collection index from api', function (): void {
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

it('does not create an object if one exists', function (): void {
    $collection = 'blog';

    $post = blogTestData();
    $id   = $post['id'];

    $this->assertFileExists(objectPath($collection, $id));

    postJson("/collections/{$collection}", $post)
        ->assertBadRequest()
        ->assertSee('already exists');
});

it('knows if an object exists', function (): void {
    $collection = 'blog';

    $post = blogTestData();
    $id   = $post['id'];

    // !PR: https://github.com/nekofar/pest-plugin-slim/pull/85
    // !PR: https://github.com/nekofar/slim-test/pull/84
    head("/collections/{$collection}/$id")->assertOk();
})->skip('awaiting PRs');

it('test if an object does not exists', function (): void {
    $collection = 'blog';

    // !PR: https://github.com/nekofar/pest-plugin-slim/pull/85
    // !PR: https://github.com/nekofar/slim-test/pull/84
    head("/collections/{$collection}/does-not-exist")->assertNotFound();
})->skip('awaiting PRs');

it('can update an object with new data', function (): void {
    $collection = 'blog';

    $post    = blogTestData();
    $id      = $post['id'];
    $content = 'Updated content';

    $post['content'] = $content;

    putJson("/collections/{$collection}/{$id}", $post)
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment([
            'id'      => $id,
            'content' => $content,
        ]);

    $post['id'] = 'broken-id';

    putJson("/collections/{$collection}/{$id}", $post)
        ->assertInternalServerError()
        ->assertSee('Does not match object ID');
});

it('can patch an object with partial data', function (): void {
    $collection = 'blog';

    $post  = blogTestData();
    $id    = $post['id'];
    $patch = ['content' => 'Patched Content'];

    patchJson("/collections/{$collection}/{$id}", $patch)
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment([
            'id'      => $id,
            'content' => $patch['content'],
        ]);

    get("/collections/{$collection}/{$id}")
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment([
            'id'      => $id,
            'content' => $patch['content'],
        ]);
});

it('can delete an object', function (): void {
    $collection = 'blog';
    $post       = blogTestData();
    $id         = $post['id'];

    delete("/collections/{$collection}/{$id}")->assertOk();
    get("/collections/{$collection}/{$id}")->assertNotFound();

    // Verify object json is gone
    $this->assertFileDoesNotExist(objectPath($collection, $id));
    // Verify object files are gone
    $this->assertFileDoesNotExist(objectFilesPath($collection, $id));
});

it('can clone an object', function (): void {
    $collection = 'blog';
    $post       = blogTestData();
    $id         = $post['id'];

    postJson("/collections/{$collection}", $post);

    $to = [
        'id'         => 'cloned-blogpost',
        'collection' => 'archive',
    ];

    $verify = [
        'id'      => $to['id'],
        'content' => $post['content'],
    ];

    postJson("/collections/{$collection}/{$id}/clone", $to)
        ->assertOk()
        ->assertJson()
        ->assertJsonFragment($verify);

    get("/collections/{$to['collection']}/{$to['id']}")->assertOk();
})->skip('Need to implement clone');

afterAll(function (): void {
    $collection = 'blog';
    $object     = blogTestData();
    $id         = $object['id'];

    $cleanup = [
        objectPath($collection, $id),
        metaPath($collection),
        indexPath($collection),
    ];

    foreach ($cleanup as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
});

// TODO: Test image and file uploads
// TODO: Need to test every single possible property type
