<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\head;
use function Nekofar\Slim\Pest\patchJson;
use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\put;
use function Nekofar\Slim\Pest\putJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

function blogTestData(): array
{
	$json = file_get_contents(testData('new-blogpost.json'));

	return json_decode($json, true);
}

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		// tests with assertBadRequest do not seem to clean up the session
		session_destroy();
	}
	$this->setUpApp(bootstrap());
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

	// !PR: Using my own fork for now
	// !PR: https://github.com/nekofar/pest-plugin-slim/pull/85
	// !PR: https://github.com/nekofar/slim-test/pull/84
	head("/collections/{$collection}/$id")->assertOk();
});

it('test if an object does not exists', function (): void {
	$collection = 'blog';

	// !PR: Using my own fork for now
	// !PR: https://github.com/nekofar/pest-plugin-slim/pull/85
	// !PR: https://github.com/nekofar/slim-test/pull/84
	head("/collections/{$collection}/does-not-exist")->assertNotFound();
});

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

it('can clone an object to a new collection', function (): void {
	$collection = 'blog';
	$post       = blogTestData();
	$id         = $post['id'];

	// Save test object
	postJson("/collections/{$collection}", $post)->assertOk();

	// Create archive collection
	$archive = [
		'id'     => 'archive',
		'schema' => 'blog',
	];
	postJson('/collections', $archive)
		->assertOk()
		->assertJson()
		->assertJsonFragment($archive);

	// Clone object to archive collection
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

	get("/collections/{$to['collection']}/{$to['id']}")
		->assertOk()
		->assertJson()
		->assertJsonFragment($verify);

	get("/collections/{$to['collection']}/index")
		->assertOk()
		->assertJson()
		->assertJsonFragment([
			'id' => $to['id'],
		]);
});

it('can clone an object to the same collection', function (): void {
	$collection = 'blog';
	$post       = blogTestData();
	$id         = $post['id'];

	// Clone object to archive collection
	$to = [
		'id' => 'cloned-blogpost',
	];
	$verify = [
		'id'    => $to['id'],
		'title' => $post['title'],
	];

	// Clone object to same collection
	postJson("/collections/{$collection}/{$id}/clone", $to)
		->assertOk()
		->assertJson()
		->assertJsonFragment($verify);

	// Verify object exists
	get("/collections/{$collection}/{$to['id']}")
		->assertOk()
		->assertJson()
		->assertJsonFragment($verify);

	// Verify object is in index
	get("/collections/{$collection}/index")
		->assertOk()
		->assertJson()
		->assertJsonFragment([
			'id' => $to['id'],
		]);
});

it('can save an objects for every property type', function (): void {
	// Need to test every single possible property type
	// loop through all the property types and save an object with each one
})->todo();
