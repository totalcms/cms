<?php

declare(strict_types=1);

require_once __DIR__ . '/McpAuthHelpers.php';

// An API key limited to some collections reaches only those over MCP, and
// only where the collection's `mcp.access` lets keys in: `apikey` or wider,
// never `admin`. Keys that list `*` stay the operator's admin key
// (McpToolSurfaceBaselineTest).

const LIMITED_API_KEY = 'tcms_mcp_limited_test_key_00000000000000';

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	file_put_contents(cmsDataDir() . '.system/apikeys.json', (string)json_encode([
		'apikeys' => [[
			'id'       => 'limited-key-id',
			'name'     => 'Blog Key',
			'key'      => LIMITED_API_KEY,
			'created'  => '2026-01-01T00:00:00Z',
			'lastUsed' => null,
			'scopes'   => ['methods' => ['GET', 'POST', 'PUT', 'DELETE'], 'paths' => ['/mcp', '/collections/blog']],
		]],
	], JSON_PRETTY_PRINT));

	// blog: named by the key and open to keys. text: open to keys but not
	// named. image: named by no one and left at admin.
	mcpAuthSetCollectionAccess($this->app, 'blog', 'apikey');
	mcpAuthSetCollectionAccess($this->app, 'text', 'apikey');
});

afterEach(function (): void {
	foreach (['blog', 'text'] as $collection) {
		mcpAuthSetCollectionAccess($this->app, $collection, 'admin');
	}
});

/**
 * @param array<string,mixed> $arguments
 *
 * @return array<string,mixed>
 */
function limitedKeyCall(mixed $app, string $tool, array $arguments, string $ip): array
{
	$response = mcpStatelessCall($app, 'tools/call', ['name' => $tool, 'arguments' => $arguments], ['X-API-Key' => LIMITED_API_KEY], $ip);
	expect($response->getStatusCode())->toBe(200);

	return json_decode((string)$response->getBody(), true);
}

test('a key limited to blog lists and reads blog only', function (): void {
	$listed = limitedKeyCall($this->app, 'list_collections', [], '203.0.113.120');
	expect($listed['result']['isError'] ?? false)->toBeFalse();
	$ids = array_column($listed['result']['structuredContent']['collections'] ?? [], 'id');
	expect($ids)->toContain('blog')->not->toContain('image')->not->toContain('text');

	$blog = limitedKeyCall($this->app, 'query_collection', ['collection' => 'blog'], '203.0.113.121');
	expect($blog['result']['isError'] ?? false)->toBeFalse();

	$text = limitedKeyCall($this->app, 'query_collection', ['collection' => 'text'], '203.0.113.122');
	expect(($text['result']['isError'] ?? false) || isset($text['error']))->toBeTrue();
});

test('a key limited to blog cannot see admin-only tools', function (): void {
	$response = mcpStatelessCall($this->app, 'tools/list', [], ['X-API-Key' => LIMITED_API_KEY], '203.0.113.123');
	$names    = array_column(json_decode((string)$response->getBody(), true)['result']['tools'] ?? [], 'name');

	expect($names)->toContain('patch_object')
		->not->toContain('list_extensions')
		->not->toContain('clear_cache');
});

test('a key cannot reach a collection it names while that collection is admin only', function (): void {
	mcpAuthSetCollectionAccess($this->app, 'blog', 'admin');

	$listed = limitedKeyCall($this->app, 'list_collections', [], '203.0.113.124');
	expect(array_column($listed['result']['structuredContent']['collections'] ?? [], 'id'))->not->toContain('blog');

	$blog = limitedKeyCall($this->app, 'query_collection', ['collection' => 'blog'], '203.0.113.125');
	expect(($blog['result']['isError'] ?? false) || isset($blog['error']))->toBeTrue();
});

test('an OAuth member cannot reach an apikey collection', function (): void {
	// An anonymous caller stands in for every non-key, non-admin caller: the
	// level lets in admins and limited keys only.
	/** @var TotalCMS\Support\Config $config */
	$config      = $this->app->getContainer()->get(TotalCMS\Support\Config::class);
	$config->mcp = array_merge($config->mcp, ['publicAccess' => true]);

	$response = mcpStatelessCall($this->app, 'tools/call', ['name' => 'list_collections', 'arguments' => []], [], '203.0.113.126');
	$ids      = array_column(json_decode((string)$response->getBody(), true)['result']['structuredContent']['collections'] ?? [], 'id');

	expect($ids)->not->toContain('blog');
});

test('a GET-only key connects read-only: no write tools, and writes are refused', function (): void {
	file_put_contents(cmsDataDir() . '.system/apikeys.json', (string)json_encode([
		'apikeys' => [[
			'id'       => 'read-key-id',
			'name'     => 'Reader',
			'key'      => LIMITED_API_KEY,
			'created'  => '2026-01-01T00:00:00Z',
			'lastUsed' => null,
			'scopes'   => ['methods' => ['GET'], 'paths' => ['/mcp', '/collections/blog']],
		]],
	], JSON_PRETTY_PRINT));

	$response = mcpStatelessCall($this->app, 'tools/list', [], ['X-API-Key' => LIMITED_API_KEY], '203.0.113.127');
	expect($response->getStatusCode())->toBe(200);
	$names = array_column(json_decode((string)$response->getBody(), true)['result']['tools'] ?? [], 'name');
	expect($names)->toContain('query_collection')
		->not->toContain('create_object')
		->not->toContain('patch_object')
		->not->toContain('update_object');

	$blog = limitedKeyCall($this->app, 'query_collection', ['collection' => 'blog'], '203.0.113.128');
	expect($blog['result']['isError'] ?? false)->toBeFalse();
});
