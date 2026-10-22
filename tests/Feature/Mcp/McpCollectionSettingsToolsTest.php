<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;

require_once __DIR__ . '/McpAuthHelpers.php';

// update_collection and delete_collection over MCP, as the operator's admin
// key. Group and key limits are the shared ToolRequirement path, covered by
// the write-tool tests.

const EDIT_TOOLS_API_KEY = 'tcms_mcp_collection_edit_key_000000000000';

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	file_put_contents(cmsDataDir() . '.system/apikeys.json', (string)json_encode([
		'apikeys' => [[
			'id'       => 'edit-key-id',
			'name'     => 'Admin',
			'key'      => EDIT_TOOLS_API_KEY,
			'created'  => '2026-01-01T00:00:00Z',
			'lastUsed' => null,
			'scopes'   => ['methods' => ['GET', 'POST', 'PUT', 'DELETE'], 'paths' => ['*']],
		]],
	], JSON_PRETTY_PRINT));

	$container = $this->app->getContainer();
	$settings  = [
		'name'       => 'Edit Me',
		'properties' => ['title' => ['field' => 'text', 'label' => 'Headline', 'help' => 'Keep it short']],
		'mcp'        => [],
	];
	if ($container->get(CollectionFetcher::class)->collectionExists('mcp-edit')) {
		$container->get(CollectionSaver::class)->patchCollection('mcp-edit', $settings);
	} else {
		$container->get(CollectionSaver::class)->saveCollection(['id' => 'mcp-edit', 'schema' => 'blog'] + $settings);
	}
});

/**
 * @param array<string,mixed> $arguments
 *
 * @return array<string,mixed>
 */
function collectionEditCall(mixed $app, string $tool, array $arguments, string $ip): array
{
	$response = mcpStatelessCall($app, 'tools/call', ['name' => $tool, 'arguments' => $arguments], ['X-API-Key' => EDIT_TOOLS_API_KEY], $ip);
	expect($response->getStatusCode())->toBe(200, (string)$response->getBody());

	return json_decode((string)$response->getBody(), true)['result'] ?? [];
}

test('update_collection merges field overrides one attribute at a time, and fills in each field type', function (): void {
	$result = collectionEditCall($this->app, 'update_collection', [
		'id'       => 'mcp-edit',
		'settings' => [
			'name'       => 'Edited',
			'properties' => ['title' => ['label' => 'Title', 'help' => null], 'summary' => ['label' => 'Teaser']],
		],
	], '203.0.113.140');

	expect($result['isError'] ?? false)->toBeFalse();

	$stored = $this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('mcp-edit');
	expect($stored?->name)->toBe('Edited')
		->and($stored?->properties['title'] ?? null)->toBe(['field' => 'text', 'label' => 'Title'])
		->and($stored?->properties['summary'] ?? null)->toBe(['field' => 'styledtext', 'label' => 'Teaser']);
});

test('update_collection keeps the rest of a settings card', function (): void {
	$this->app->getContainer()->get(CollectionSaver::class)->patchCollection('mcp-edit', ['mcp' => ['access' => 'admin', 'description' => 'Keep me']]);

	$result = collectionEditCall($this->app, 'update_collection', ['id' => 'mcp-edit', 'settings' => ['mcp' => ['access' => 'authenticated']]], '203.0.113.141');

	expect($result['isError'] ?? false)->toBeFalse();
	$mcp = $this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('mcp-edit')?->mcp ?? [];
	expect($mcp['access'] ?? null)->toBe('authenticated')
		->and($mcp['description'] ?? null)->toBe('Keep me');
});

test('update_collection refuses a rename', function (): void {
	$result = collectionEditCall($this->app, 'update_collection', ['id' => 'mcp-edit', 'settings' => ['id' => 'other']], '203.0.113.142');

	expect($result['isError'] ?? false)->toBeTrue();
});

test('delete_collection keeps the objects, and recreating the collection brings them back', function (): void {
	$survivor = 'survivor-' . bin2hex(random_bytes(4));
	$this->app->getContainer()->get(ObjectSaver::class)->saveObject('mcp-edit', ['id' => $survivor, 'title' => 'Still here']);

	$result = collectionEditCall($this->app, 'delete_collection', ['id' => 'mcp-edit'], '203.0.113.143');

	expect($result['isError'] ?? false)->toBeFalse()
		->and($result['structuredContent']['deleted'] ?? null)->toBeTrue()
		->and($result['structuredContent']['settings']['schema'] ?? null)->toBe('blog')
		->and($this->app->getContainer()->get(CollectionFetcher::class)->collectionExists('mcp-edit'))->toBeFalse()
		->and(is_file(cmsDataDir() . 'mcp-edit/' . $survivor . '.json'))->toBeTrue();

	$settings = $result['structuredContent']['settings'];
	unset($settings['id'], $settings['schema']);
	$created = collectionEditCall($this->app, 'create_collection', ['id' => 'mcp-edit', 'schema' => 'blog', 'extra' => $settings], '203.0.113.144');
	expect($created['isError'] ?? false)->toBeFalse();

	$fetched = collectionEditCall($this->app, 'get_object', ['collection' => 'mcp-edit', 'id' => $survivor], '203.0.113.145');
	expect(json_encode($fetched))->toContain('Still here');
});

test('delete_collection refuses the login collection', function (): void {
	$result = collectionEditCall($this->app, 'delete_collection', ['id' => 'auth'], '203.0.113.146');

	expect($result['isError'] ?? false)->toBeTrue()
		->and($this->app->getContainer()->get(CollectionFetcher::class)->collectionExists('auth'))->toBeTrue();
});

/**
 * @param list<string> $methods
 *
 * @return array{tools: list<string>, update: array<string,mixed>}
 */
function limitedKeyCollectionReach(mixed $app, array $methods, string $ipPrefix): array
{
	file_put_contents(cmsDataDir() . '.system/apikeys.json', (string)json_encode([
		'apikeys' => [[
			'id'       => 'edit-limited-id',
			'name'     => 'Limited',
			'key'      => EDIT_TOOLS_API_KEY,
			'created'  => '2026-01-01T00:00:00Z',
			'lastUsed' => null,
			'scopes'   => ['methods' => $methods, 'paths' => ['/mcp', '/collections/mcp-edit']],
		]],
	], JSON_PRETTY_PRINT));

	$list  = mcpStatelessCall($app, 'tools/list', [], ['X-API-Key' => EDIT_TOOLS_API_KEY], $ipPrefix . '1');
	$tools = array_column(json_decode((string)$list->getBody(), true)['result']['tools'] ?? [], 'name');

	$update = mcpStatelessCall($app, 'tools/call', ['name' => 'update_collection', 'arguments' => ['id' => 'mcp-edit', 'settings' => ['name' => 'By key']]], ['X-API-Key' => EDIT_TOOLS_API_KEY], $ipPrefix . '2');

	return ['tools' => $tools, 'update' => json_decode((string)$update->getBody(), true) ?? []];
}

test('a limited key never changes collection settings or schemas, whatever its methods', function (): void {
	// Open to keys, so only the structure rule can refuse it.
	$this->app->getContainer()->get(CollectionSaver::class)->patchCollection('mcp-edit', ['mcp' => ['access' => 'apikey']]);

	$reach = limitedKeyCollectionReach($this->app, ['GET', 'POST', 'PUT', 'DELETE'], '203.0.113.15');

	expect($reach['tools'])->toContain('patch_object')
		->not->toContain('create_collection')
		->not->toContain('update_collection')
		->not->toContain('delete_collection')
		->not->toContain('update_schema')
		->and(($reach['update']['result']['isError'] ?? false) || isset($reach['update']['error']))->toBeTrue()
		->and($this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('mcp-edit')?->name)->toBe('Edit Me');
});

test('an OAuth member whose group grants collection settings update gets update_collection, but not delete', function (): void {
	// editor: collectionsMeta read + update on blog and image, no delete.
	mcpAuthSetupOAuthKeys($this->app);
	mcpAuthSeedAccessGroups();
	mcpAuthSeedUser('editor-user-test-com');
	mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');

	try {
		$token     = mcpAuthIssueToken($this->app, 'mcp-coll-edit-' . uniqid('', true), 'secret', ['cms:read', 'cms:write', 'cms:admin', 'mcp:tools'], 'editor-user-test-com');
		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($token)->not->toBe('')->and($sessionId)->not->toBe('');

		$list  = json_decode((string)mcpAuthRequest($this->app, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $sessionId)->getBody(), true);
		$names = array_column($list['result']['tools'] ?? [], 'name');
		expect($names)->toContain('update_collection')->not->toContain('delete_collection');

		$call = json_decode((string)mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => ['name' => 'update_collection', 'arguments' => ['id' => 'blog', 'settings' => ['description' => 'Edited by an editor']]],
		], $sessionId)->getBody(), true);
		expect($call['result']['isError'] ?? true)->toBeFalse()
			->and($this->app->getContainer()->get(CollectionFetcher::class)->fetchCollection('blog')?->description)->toBe('Edited by an editor');

		$denied = json_decode((string)mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'tools/call',
			'params'  => ['name' => 'update_collection', 'arguments' => ['id' => 'mcp-edit', 'settings' => ['description' => 'Not mine']]],
		], $sessionId)->getBody(), true);
		expect(($denied['result']['isError'] ?? false) || isset($denied['error']))->toBeTrue();
	} finally {
		mcpAuthSetCollectionAccess($this->app, 'blog', 'admin');
	}
});
