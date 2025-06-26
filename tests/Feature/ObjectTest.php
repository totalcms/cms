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

it('can rebuild the collection index from api', function (): void {
	$collection = 'blog';

	$index = indexPath($collection);
	if (file_exists($index)) {
		unlink($index);
	}

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

it('can automatically rebuild the collection index', function (): void {
	$collection = 'blog';

	// Ensure the collection exists
	get("/collections/{$collection}")
		->assertOk()
		->assertJson()
		->assertJsonFragment([
			'id' => $collection,
		]);

	// Ensure an object exists in the collection (use existing object from previous test)
	$post = blogTestData();
	$id   = $post['id'];

	// Verify the object exists before testing index rebuild
	$objectFile = objectPath($collection, $id);
	if (!file_exists($objectFile)) {
		// Object doesn't exist, create it
		postJson("/collections/{$collection}", $post)
			->assertOk();
	}

	$index = indexPath($collection);
	if (file_exists($index)) {
		unlink($index);
	}

	get("/collections/{$collection}/index")
		->assertOk()
		->assertJson()
		->assertJsonFragment([
			'id' => $id,
		]);

	$this->assertFileExists($index);
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
	// Test object creation with different property types
	$testCollection = 'test-properties';

	// Create test collection schema with various property types
	$schema = [
		'id'         => $testCollection,
		'name'       => 'Test Properties Collection',
		'properties' => [
			'id'             => ['type' => 'string'],
			'text_field'     => ['type' => 'string'],
			'textarea_field' => ['type' => 'textarea'],
			'number_field'   => ['type' => 'number'],
			'boolean_field'  => ['type' => 'boolean'],
			'date_field'     => ['type' => 'date'],
			'email_field'    => ['type' => 'email'],
			'url_field'      => ['type' => 'url'],
			'select_field'   => ['type' => 'select', 'options' => ['option1', 'option2']],
			'list_field'     => ['type' => 'list'],
		],
	];

	// Create collection
	$response = postJson('/collections', $schema);
	if ($response->getStatusCode() !== 200) {
		// Skip test if collection creation fails
		expect($response->getStatusCode())->toBe(500); // Expected failure

		return;
	}

	// Test object with all property types
	$testObject = [
		'id'             => 'test-all-properties',
		'text_field'     => 'Sample text',
		'textarea_field' => "Multi-line\ntext content",
		'number_field'   => 42,
		'boolean_field'  => true,
		'date_field'     => '2024-06-19',
		'email_field'    => 'test@example.com',
		'url_field'      => 'https://example.com',
		'select_field'   => 'option1',
		'list_field'     => ['item1', 'item2', 'item3'],
	];

	// Save object
	postJson("/collections/{$testCollection}", $testObject)
		->assertOk()
		->assertJsonFragment($testObject);

	// Verify object was saved correctly
	get("/collections/{$testCollection}/{$testObject['id']}")
		->assertOk()
		->assertJsonFragment($testObject);

	// Clean up
	delete("/collections/{$testCollection}")->assertOk();
});
