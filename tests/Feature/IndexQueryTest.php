<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$container         = $this->app->getContainer();
	$collectionFetcher = $container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class);
	$collectionFetcher->fetchOrCreateReserved('blog');
});

/**
 * Create multiple blog posts for testing pagination.
 *
 * @return array<string,array<string,mixed>>
 */
function createBlogPosts(int $count = 5): array
{
	$posts = [];

	for ($i = 1; $i <= $count; $i++) {
		$post = [
			'id'         => "test-post-{$i}",
			'title'      => "Test Post {$i}",
			'date'       => sprintf('2024-01-%02dT12:00:00+00:00', $i),
			'draft'      => $i > 4,  // post 5 is a draft
			'featured'   => $i <= 2, // posts 1-2 are featured
			'author'     => 'Test Author',
			'categories' => ['testing'],
			'tags'       => $i % 2 === 0 ? ['even'] : ['odd'],
			'summary'    => "Summary for post {$i}",
			'content'    => "Content for post {$i}",
		];

		postJson('/collections/blog', $post);
		$posts[$post['id']] = $post;
	}

	return $posts;
}

// ─── JSON Format ───────────────────────────────────────────────────

it('returns paginated JSON with default params', function (): void {
	createBlogPosts(5);

	get('/collections/blog/query')
		->assertOk()
		->assertJson()
		->assertHeader('X-Total', '5')
		->assertHeader('X-Offset', '0')
		->assertHeader('X-Limit', '20')
		->assertHeader('X-Has-More', 'false');
});

it('respects limit and offset for JSON', function (): void {
	get('/collections/blog/query?limit=2&offset=0')
		->assertOk()
		->assertJson()
		->assertHeader('X-Total', '5')
		->assertHeader('X-Limit', '2')
		->assertHeader('X-Offset', '0')
		->assertHeader('X-Has-More', 'true')
		->assertJsonCount(2, 'data');
});

it('returns second page of results', function (): void {
	get('/collections/blog/query?limit=2&offset=2')
		->assertOk()
		->assertJson()
		->assertHeader('X-Offset', '2')
		->assertHeader('X-Has-More', 'true')
		->assertJsonCount(2, 'data');
});

it('returns partial last page', function (): void {
	get('/collections/blog/query?limit=2&offset=4')
		->assertOk()
		->assertJson()
		->assertHeader('X-Has-More', 'false')
		->assertJsonCount(1, 'data');
});

it('returns empty data when offset exceeds total', function (): void {
	get('/collections/blog/query?limit=10&offset=100')
		->assertOk()
		->assertJson()
		->assertHeader('X-Total', '5')
		->assertHeader('X-Has-More', 'false')
		->assertJsonCount(0, 'data');
});

it('includes Fractal pagination metadata in JSON', function (): void {
	$response = get('/collections/blog/query?limit=2&offset=0');

	$response->assertOk()->assertJson();

	$json = json_decode((string)$response->getBody(), true);

	expect($json)->toHaveKey('meta.pagination');
	expect($json['meta']['pagination']['total'])->toBe(5);
	expect($json['meta']['pagination']['per_page'])->toBe(2);
	expect($json['meta']['pagination']['current_page'])->toBe(1);
	expect($json['meta']['pagination']['total_pages'])->toBe(3);
});

it('clamps limit to max 100', function (): void {
	get('/collections/blog/query?limit=999')
		->assertOk()
		->assertHeader('X-Limit', '100');
});

it('clamps limit to min 1', function (): void {
	get('/collections/blog/query?limit=0')
		->assertOk()
		->assertHeader('X-Limit', '1');
});

it('clamps negative offset to 0', function (): void {
	get('/collections/blog/query?offset=-5')
		->assertOk()
		->assertHeader('X-Offset', '0');
});

// ─── Filtering ─────────────────────────────────────────────────────

it('filters with include param', function (): void {
	// featured:true matches posts 1-2
	get('/collections/blog/query?include=featured:true')
		->assertOk()
		->assertHeader('X-Total', '2');
});

it('filters with exclude param', function (): void {
	// draft:true matches post 5 only, excluding it leaves 4
	get('/collections/blog/query?exclude=draft:true')
		->assertOk()
		->assertHeader('X-Total', '4');
});

it('combines include and exclude filters', function (): void {
	// draft:false matches posts 1-4, exclude featured:true removes posts 1-2, leaves 2
	get('/collections/blog/query?include=draft:false&exclude=featured:true')
		->assertOk()
		->assertHeader('X-Total', '2');
});

// ─── Sorting ───────────────────────────────────────────────────────

it('sorts results by property ascending', function (): void {
	$response = get('/collections/blog/query?sort=title:asc&limit=5');
	$response->assertOk();

	$json   = json_decode((string)$response->getBody(), true);
	$titles = array_column($json['data'], 'title');

	expect($titles)->toBe([
		'Test Post 1',
		'Test Post 2',
		'Test Post 3',
		'Test Post 4',
		'Test Post 5',
	]);
});

it('sorts results by property descending', function (): void {
	$response = get('/collections/blog/query?sort=title:desc&limit=5');
	$response->assertOk();

	$json   = json_decode((string)$response->getBody(), true);
	$titles = array_column($json['data'], 'title');

	expect($titles)->toBe([
		'Test Post 5',
		'Test Post 4',
		'Test Post 3',
		'Test Post 2',
		'Test Post 1',
	]);
});

// ─── Search ────────────────────────────────────────────────────────

it('returns search results', function (): void {
	get('/collections/blog/query?search=Test+Post+1')
		->assertOk()
		->assertJson();

	// Search should return at least the matching post
	$response = get('/collections/blog/query?search=Test+Post+1');
	$json     = json_decode((string)$response->getBody(), true);

	expect(count($json['data']))->toBeGreaterThanOrEqual(1);
});

// ─── HTML Format ───────────────────────────────────────────────────

it('returns 400 when HTML format missing template', function (): void {
	get('/collections/blog/query?format=html')
		->assertBadRequest();
});

it('renders HTML with template and pagination headers', function (): void {
	// Create a test template in the custom templates directory
	$templateDir = cmsDataDir() . 'templates/test/';
	if (!is_dir($templateDir)) {
		mkdir($templateDir, 0755, true);
	}
	file_put_contents($templateDir . 'card.twig', '<article class="post">{{ object.title }}</article>');

	// Re-bootstrap to pick up the new template directory
	$this->setUpApp(bootstrap());

	$response = get('/collections/blog/query?format=html&template=test/card&limit=2');

	$response->assertOk()
		->assertHeader('Content-Type', 'text/html')
		->assertHeader('X-Total', '5')
		->assertHeader('X-Limit', '2')
		->assertHeader('X-Has-More', 'true')
		->assertSee('<article class="post">');
});

it('renders HTMX trigger when more items exist', function (): void {
	$response = get('/collections/blog/query?format=html&template=test/card&limit=2');

	$response->assertOk()
		->assertSee('hx-get=')
		->assertSee('hx-trigger="revealed"')
		->assertSee('hx-swap="outerHTML"')
		->assertSee('cms-load-more')
		->assertSee('offset=2');
});

it('does not render HTMX trigger on last page', function (): void {
	$response = get('/collections/blog/query?format=html&template=test/card&limit=10');

	$response->assertOk()
		->assertDontSee('hx-get=')
		->assertDontSee('cms-load-more');
});

it('renders click trigger when requested', function (): void {
	$response = get('/collections/blog/query?format=html&template=test/card&limit=2&trigger=click&label=Show+More');

	$response->assertOk()
		->assertSee('<button')
		->assertSee('hx-trigger="click"')
		->assertSee('Show More');
});

// ─── CSV Format ────────────────────────────────────────────────────

it('returns CSV with pagination headers', function (): void {
	$response = get('/collections/blog/query?format=csv&limit=3');

	$response->assertOk()
		->assertHeader('Content-Type', 'text/csv')
		->assertHeader('X-Total', '5')
		->assertHeader('X-Limit', '3')
		->assertHeader('X-Has-More', 'true');

	$body = (string)$response->getBody();
	// CSV should have a header row plus data rows
	$lines = array_filter(explode("\n", trim($body)));
	// Header row + 3 data rows
	expect(count($lines))->toBe(4);
});

it('returns CSV with Content-Disposition header', function (): void {
	get('/collections/blog/query?format=csv')
		->assertOk()
		->assertHeader('Content-Disposition', 'attachment; filename="collection-blog.csv"');
});

// ─── Nonexistent Collection ────────────────────────────────────────

it('handles query on empty collection', function (): void {
	// Use a reserved collection type so it passes edition middleware
	$container         = $this->app->getContainer();
	$collectionFetcher = $container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class);
	$collectionFetcher->fetchOrCreateReserved('text');

	$response = get('/collections/text/query');
	expect($response->getStatusCode())->toBeIn([200, 400]);

	if ($response->getStatusCode() === 200) {
		$response->assertJson()
			->assertHeader('X-Total', '0')
			->assertHeader('X-Has-More', 'false')
			->assertJsonCount(0, 'data');
	}
});
