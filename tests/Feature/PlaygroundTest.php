<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\putJson;

beforeAll(function (): void {
	// Clean up any existing playground data
	$playgroundDir = cmsDataDir() . '/playground';
	if (is_dir($playgroundDir)) {
		recursiveDelete($playgroundDir);
	}
});

function playgroundTestData(): array
{
	return [
		'id'       => 'test-snippet',
		'name'     => 'Test Snippet',
		'category' => 'Testing',
		'snippet'  => '{% set message = "Hello World" %}{{ message }}',
	];
}

function playgroundTestDataAdvanced(): array
{
	return [
		'id'       => 'advanced-snippet',
		'name'     => 'Advanced Test Snippet',
		'category' => 'Advanced',
		'snippet'  => "{% for item in cms.objects('blog') %}\n  <h2>{{ item.title }}</h2>\n  <p>{{ item.summary }}</p>\n{% endfor %}",
	];
}

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	// Create the playground collection for tests (reserved collections no longer auto-create)
	$container         = $this->app->getContainer();
	$collectionFetcher = $container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class);
	$collectionFetcher->fetchOrCreateReserved('playground');
});

// Test playground collection access
it('provides access to playground collection', function (): void {
	get('/api/playground')
		->assertOk()
		->assertJson();
});

// Test saving a new playground snippet
it('saves a new playground snippet', function (): void {
	$collection = 'playground';
	$snippet    = playgroundTestData();
	$id         = $snippet['id'];

	postJson('/api/playground', $snippet)
		->assertOk()
		->assertJson()
		->assertJsonFragment($snippet);

	$this->assertFileExists(objectPath($collection, $id));
});

// Test fetching a specific playground snippet
it('fetches a specific playground snippet', function (): void {
	$snippet       = playgroundTestData();
	$snippet['id'] = 'fetch-test-snippet'; // Unique ID for this test
	$id            = $snippet['id'];

	// First create the snippet
	postJson('/api/playground', $snippet)
		->assertOk()
		->assertJson();

	// Then fetch it
	get("/api/playground/{$id}")
		->assertOk()
		->assertJson()
		->assertJsonFragment(['id' => $id]);
});

// Test updating a playground snippet
it('updates a playground snippet', function (): void {
	$collection    = 'playground';
	$snippet       = playgroundTestData();
	$snippet['id'] = 'update-test-snippet'; // Unique ID for this test
	$id            = $snippet['id'];

	// Create initial snippet
	postJson('/api/playground', $snippet)
		->assertOk();

	// Update the snippet
	$updatedSnippet = array_merge($snippet, [
		'name'    => 'Updated Test Snippet',
		'snippet' => '{% set updated = "Updated Hello World" %}{{ updated }}',
	]);

	putJson("/api/playground/{$id}", $updatedSnippet)
		->assertOk()
		->assertJson()
		->assertJsonFragment(['name' => 'Updated Test Snippet']);
});

// Test deleting a playground snippet
it('deletes a playground snippet', function (): void {
	$collection    = 'playground';
	$snippet       = playgroundTestData();
	$snippet['id'] = 'delete-test-snippet'; // Unique ID for this test
	$id            = $snippet['id'];

	// Create snippet
	postJson('/api/playground', $snippet)
		->assertOk();

	$this->assertFileExists(objectPath($collection, $id));

	// Delete snippet
	delete("/api/playground/{$id}")
		->assertOk();

	$this->assertFileDoesNotExist(objectPath($collection, $id));
});

// Test listing all playground snippets
it('lists all playground snippets', function (): void {
	$snippet1       = playgroundTestData();
	$snippet1['id'] = 'list-test-snippet-1'; // Unique ID for this test
	$snippet2       = playgroundTestDataAdvanced();
	$snippet2['id'] = 'list-test-snippet-2'; // Unique ID for this test

	// Create two snippets
	postJson('/api/playground', $snippet1)->assertOk();
	postJson('/api/playground', $snippet2)->assertOk();

	// List all snippets
	get('/api/playground')
		->assertOk()
		->assertJson();
});

// Test playground snippet with special characters
it('handles playground snippets with special characters', function (): void {
	$snippet = [
		'id'       => 'special-chars-snippet',
		'name'     => 'Special Characters Test',
		'category' => 'Testing',
		'snippet'  => "{% set data = {'key': \"value with 'quotes'\"} %}\n{{ data.key|escape }}",
	];

	postJson('/api/playground', $snippet)
		->assertOk()
		->assertJson();

	// Verify it can be fetched back correctly
	get("/api/playground/{$snippet['id']}")
		->assertOk()
		->assertJson()
		->assertJsonFragment(['id' => $snippet['id']]);
});

// Test error handling for non-existent snippets
it('returns 404 for non-existent playground snippets', function (): void {
	get('/playground/non-existent-snippet')
		->assertNotFound();
});

// Test basic playground functionality
it('supports full CRUD operations on playground snippets', function (): void {
	$snippet       = playgroundTestData();
	$snippet['id'] = 'crud-test-snippet'; // Unique ID for this test
	$id            = $snippet['id'];

	// Create
	postJson('/api/playground', $snippet)->assertOk();

	// Read
	get("/api/playground/{$id}")->assertOk();

	// Update
	$updated = array_merge($snippet, ['name' => 'Updated Name']);
	putJson("/api/playground/{$id}", $updated)->assertOk();

	// Delete
	delete("/api/playground/{$id}")->assertOk();

	// Verify deletion
	get("/api/playground/{$id}")->assertNotFound();
});
