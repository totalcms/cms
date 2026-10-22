<?php

declare(strict_types=1);

require_once __DIR__ . '/McpAuthHelpers.php';

// An API key limited to some collections reaches only those over MCP, and
// only where the collection is open to signed-in MCP callers (its `mcp.access`
// is authenticated or public), as for an OAuth member. Keys that list `*`
// stay the operator's admin key (McpToolSurfaceBaselineTest).

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
			'scopes'   => ['methods' => ['GET', 'POST'], 'paths' => ['/mcp', '/collections/blog']],
		]],
	], JSON_PRETTY_PRINT));

	foreach (['blog', 'text', 'image'] as $collection) {
		mcpAuthSetCollectionAccess($this->app, $collection, 'authenticated');
	}
});

afterEach(function (): void {
	foreach (['blog', 'text', 'image'] as $collection) {
		mcpAuthSetCollectionAccess($this->app, $collection, 'private');
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
