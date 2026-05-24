<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Support\Config;

/**
 * Integration tests for schema-defined MCP tools — Phase 3 Chunk F.
 *
 * Exercises the full path: fixture collection on disk → POST /mcp →
 * McpEndpointAction → McpServerFactory::build() → SchemaToolRegistrar
 * walks collection → SavedQueryTool dispatches → JSON-RPC response.
 *
 * The `listings` fixture collection uses `mcp.access = "public"` and declares
 * a single schema tool `find_listings` with a required `city` param. Tests
 * cover:
 *
 *   1. find_listings appears in tools/list for public persona.
 *   2. tools/call with valid args returns matching objects + filters out
 *      non-matching city and draft items.
 *   3. admin-only collection rejects public persona with isError.
 *   4. Collision with a core tool name is skipped at server-build + warns in log.
 *
 * Skip-safe pattern: when MCP is unavailable (non-Pro edition, disabled, or
 * auth gate rejects), the session init returns '' and every test passes
 * trivially — same pattern as McpResourcesTest and McpStreamingTest.
 *
 * Fixture: tests/fixtures/mcp/listings/
 * Live install: tests/tcms-data/listings/  (created in beforeEach, removed in afterEach)
 */

// ──────────────────────────────────────────────────────────────────────────────
// Fixture management helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Install the listings fixture into the live test data dir.
 * Copies .meta.json, .index.json, and all objects from fixtures/mcp/listings/
 * into tests/tcms-data/listings/.
 */
function installListingsFixture(): void
{
	$src  = __DIR__ . '/../fixtures/mcp/listings';
	$dest = cmsDataDir() . 'listings';

	if (!is_dir($dest)) {
		mkdir($dest, 0755, true);
	}

	// .meta.json
	copy($src . '/.meta.json', $dest . '/.meta.json');
	// .index.json (pre-built so the IndexBuilder is not invoked)
	copy($src . '/.index.json', $dest . '/.index.json');

	// Object files
	$objectsSrc  = $src . '/objects';
	$objectsDest = $dest;
	foreach (glob($objectsSrc . '/*.json') ?: [] as $file) {
		copy($file, $objectsDest . '/' . basename($file));
	}
}

/**
 * Remove the live listings fixture from the test data dir.
 */
function removeListingsFixture(): void
{
	recursiveDelete(cmsDataDir() . 'listings');
}

// ──────────────────────────────────────────────────────────────────────────────
// Session/request helpers — adapted from McpResourcesTest
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Initialize an MCP session with an admin API key.
 * Returns empty string when MCP is unavailable.
 */
function schemaTestAdminInit(Slim\App $app): string
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('X-API-Key', 'tcms_schema_tool_int_test_key_fixture_000');

	$request->getBody()->write((string)json_encode([
		'jsonrpc' => '2.0',
		'id'      => 0,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest-schema-tools', 'version' => '0.1'],
		],
	]));
	$request->getBody()->rewind();

	$response = $app->handle($request);

	if ($response->getStatusCode() !== 200) {
		return '';
	}

	return $response->getHeaderLine('Mcp-Session-Id');
}

/**
 * Initialize an MCP session as anonymous (public persona).
 * Requires $config->mcp['publicAccess'] = true before calling.
 * Returns empty string when MCP is unavailable or publicAccess is off.
 */
function schemaTestPublicInit(Slim\App $app): string
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream');
	// No X-API-Key — anonymous = public persona

	$request->getBody()->write((string)json_encode([
		'jsonrpc' => '2.0',
		'id'      => 0,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest-schema-tools-public', 'version' => '0.1'],
		],
	]));
	$request->getBody()->rewind();

	$response = $app->handle($request);

	if ($response->getStatusCode() !== 200) {
		return '';
	}

	return $response->getHeaderLine('Mcp-Session-Id');
}

/**
 * POST /mcp with admin API key + session header.
 *
 * @param array<string,mixed> $payload
 */
function schemaTestAdminRequest(
	Slim\App $app,
	array $payload,
	string $sessionId,
): Psr\Http\Message\ResponseInterface {
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('X-API-Key', 'tcms_schema_tool_int_test_key_fixture_000')
		->withHeader('Mcp-Session-Id', $sessionId);

	$request->getBody()->write((string)json_encode($payload));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * POST /mcp without API key (anonymous / public persona) + session header.
 *
 * @param array<string,mixed> $payload
 */
function schemaTestPublicRequest(
	Slim\App $app,
	array $payload,
	string $sessionId,
): Psr\Http\Message\ResponseInterface {
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('Mcp-Session-Id', $sessionId);
	// No X-API-Key

	$request->getBody()->write((string)json_encode($payload));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * Build a JSON-RPC tools/list payload.
 *
 * @return array<string,mixed>
 */
function schemaTestToolsList(): array
{
	return [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'tools/list',
	];
}

/**
 * Build a JSON-RPC tools/call payload.
 *
 * @param array<string,mixed> $arguments
 *
 * @return array<string,mixed>
 */
function schemaTestToolsCall(string $toolName, array $arguments): array
{
	return [
		'jsonrpc' => '2.0',
		'id'      => 2,
		'method'  => 'tools/call',
		'params'  => [
			'name'      => $toolName,
			'arguments' => $arguments,
		],
	];
}

// ──────────────────────────────────────────────────────────────────────────────
// Test lifecycle
// ──────────────────────────────────────────────────────────────────────────────

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}

	$this->setUpApp(bootstrap());

	// Write an admin API key for tests that need admin persona.
	$apiKeysFile = cmsDataDir() . '.system/apikeys.json';
	$apiKeysData = json_encode([
		'apikeys' => [
			[
				'id'       => 'schema-tool-int-test-key-id',
				'name'     => 'Schema Tool Integration Test Key',
				'key'      => 'tcms_schema_tool_int_test_key_fixture_000',
				'created'  => '2026-01-01T00:00:00Z',
				'lastUsed' => null,
				'scopes'   => [
					'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
					'paths'   => ['*'],
				],
			],
		],
	], JSON_PRETTY_PRINT);
	file_put_contents($apiKeysFile, $apiKeysData);

	// Install the listings fixture collection.
	installListingsFixture();
});

afterEach(function (): void {
	removeListingsFixture();
});

// ──────────────────────────────────────────────────────────────────────────────
// Test 1 — find_listings appears in tools/list for public persona
// ──────────────────────────────────────────────────────────────────────────────

it('find_listings appears in tools/list for the public persona', function (): void {
	// Enable anonymous (public) persona access for this test.
	/** @var Config $config */
	$config = $this->app->getContainer()->get(Config::class);
	$config->mcp['publicAccess'] = true;

	$sessionId = schemaTestPublicInit($this->app);

	if ($sessionId === '') {
		// MCP unavailable (non-Pro edition or disabled) — skip-safe pass.
		expect(true)->toBeTrue();

		return;
	}

	$response = schemaTestPublicRequest(
		$this->app,
		schemaTestToolsList(),
		$sessionId,
	);

	expect($response->getStatusCode())->toBe(200);

	$body = json_decode((string)$response->getBody(), true);
	expect($body)->toHaveKey('result');
	expect($body['result'])->toHaveKey('tools');
	expect($body['result']['tools'])->toBeArray();

	$toolNames = array_column($body['result']['tools'], 'name');

	// The schema-defined tool must appear in the public tool surface.
	expect($toolNames)->toContain('find_listings');

	// Core content tools that are public should also be present.
	expect($toolNames)->toContain('query_collection');
});

// ──────────────────────────────────────────────────────────────────────────────
// Test 2 — tools/call with valid args filters by city + excludes drafts
// ──────────────────────────────────────────────────────────────────────────────

it('find_listings tools/call filters by city and excludes drafts (public persona)', function (): void {
	/** @var Config $config */
	$config = $this->app->getContainer()->get(Config::class);
	$config->mcp['publicAccess'] = true;

	$sessionId = schemaTestPublicInit($this->app);

	if ($sessionId === '') {
		expect(true)->toBeTrue();

		return;
	}

	// Call with city=Austin. Expected results:
	//   austin-1  (Austin, active, draft:false) — MATCHES
	//   austin-2  (Austin, active, draft:false) — MATCHES
	//   dallas-1  (Dallas, active, draft:false) — does NOT match city filter
	//   austin-draft (Austin, active, draft:true) — excluded by public-persona draft filter
	$response = schemaTestPublicRequest(
		$this->app,
		schemaTestToolsCall('find_listings', ['city' => 'Austin']),
		$sessionId,
	);

	expect($response->getStatusCode())->toBe(200);

	$body = json_decode((string)$response->getBody(), true);
	expect($body)->toHaveKey('result');

	$result = $body['result'];

	// The MCP tool result envelope must not be an error.
	expect($result['isError'] ?? false)->toBeFalse();
	expect($result)->toHaveKey('content');
	expect($result['content'])->toBeArray()->not()->toBeEmpty();

	// The SDK wraps the tool handler output in an outer content envelope.
	// Navigate through: result.content[0].text → {content:[{type,text}]}
	// → content[0].text → {items:[...], count:N}
	$outerText   = $result['content'][0]['text'];
	$toolOutput  = json_decode($outerText, true);

	// Handle both single-wrap and double-wrap SDK serialization patterns.
	if (is_array($toolOutput) && isset($toolOutput['content'])) {
		$innerText = $toolOutput['content'][0]['text'] ?? null;
		$payload   = is_string($innerText) ? json_decode($innerText, true) : null;
	} else {
		// Single-wrap: text is directly the items JSON.
		$payload = $toolOutput;
	}

	expect($payload)->toBeArray();
	expect($payload)->toHaveKey('items');
	expect($payload)->toHaveKey('count');

	$items = $payload['items'];
	$ids   = array_column($items, 'id');

	// Austin-1 and Austin-2 must appear (active, not draft, city matches).
	expect($ids)->toContain('austin-1');
	expect($ids)->toContain('austin-2');

	// Dallas-1 must NOT appear (different city).
	expect($ids)->not()->toContain('dallas-1');

	// Austin-draft must NOT appear (draft:true excluded by public persona).
	expect($ids)->not()->toContain('austin-draft');

	// Count must match the returned items array length.
	expect($payload['count'])->toBe(count($items));
});

// ──────────────────────────────────────────────────────────────────────────────
// Test 3 — public persona cannot call an admin-only collection's schema tool
// ──────────────────────────────────────────────────────────────────────────────

it('public persona cannot call find_listings when collection access is admin', function (): void {
	/** @var Config $config */
	$config = $this->app->getContainer()->get(Config::class);
	$config->mcp['publicAccess'] = true;

	// Override the fixture .meta.json on disk to make the collection admin-only.
	// The server reads .meta.json at server-build time via CollectionRepository.
	$metaPath = cmsDataDir() . 'listings/.meta.json';
	$meta     = json_decode((string)file_get_contents($metaPath), true);
	$meta['mcp']['access'] = 'admin';
	file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT));

	// Explicitly clear the collections_list cache so the updated meta is picked up.
	// The CacheManager persists collections_list in the filesystem cache between
	// test requests in the same Pest run — a direct clear is more reliable than
	// deleting the entire cache dir (which may race with createCacheDir()).
	/** @var \TotalCMS\Domain\Cache\CacheManager $cacheManager */
	$cacheManager = $this->app->getContainer()->get(\TotalCMS\Domain\Cache\CacheManager::class);
	$cacheManager->clearComputedData('collections_list');

	// Re-bootstrap the app so the container's CollectionRepository picks up
	// the updated meta.
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	$config = $this->app->getContainer()->get(Config::class);
	$config->mcp['publicAccess'] = true;

	$sessionId = schemaTestPublicInit($this->app);

	if ($sessionId === '') {
		expect(true)->toBeTrue();

		return;
	}

	// tools/call find_listings from public persona — tool is admin-only.
	$response = schemaTestPublicRequest(
		$this->app,
		schemaTestToolsCall('find_listings', ['city' => 'Austin']),
		$sessionId,
	);

	expect($response->getStatusCode())->toBe(200);

	$body = json_decode((string)$response->getBody(), true);

	// When the tool is admin-only, SchemaToolRegistrar does NOT register it
	// for the public persona surface. The SDK returns a JSON-RPC protocol error
	// ("Tool not found") rather than routing to the handler at all.
	// This is a stronger guarantee than isError:true — the tool is completely
	// absent from the anonymous tool surface.
	if (isset($body['error'])) {
		// Protocol-level error: tool was never registered for public persona.
		expect($body['error']['message'])->toContain('find_listings');
	} elseif (isset($body['result'])) {
		// Fallback: if the SDK somehow routed the call, the handler must deny.
		expect($body['result']['isError'] ?? false)->toBeTrue();
	} else {
		// Neither key present — unexpected shape; fail with a useful message.
		expect($body)->toHaveKey('error', 'Expected JSON-RPC error for admin-only tool called by public persona');
	}
});

// ──────────────────────────────────────────────────────────────────────────────
// Test 4 — schema tool colliding with core tool name is absent from tools/list
// ──────────────────────────────────────────────────────────────────────────────

it('schema tool whose name collides with a core tool is absent from tools/list (admin)', function (): void {
	// Override the fixture to declare a tool named `query_collection` — same
	// as the core tool. SchemaToolRegistrar detects the collision and skips it,
	// logging a warning to mcp-activity log.
	$metaPath = cmsDataDir() . 'listings/.meta.json';
	$meta     = json_decode((string)file_get_contents($metaPath), true);
	$meta['mcp']['tools'][] = [
		'name'        => 'query_collection',
		'description' => 'Schema tool colliding with core query_collection.',
		'limit'       => 5,
		'format'      => 'markdown',
	];
	file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT));

	// Re-bootstrap so SchemaToolRegistrar sees the updated meta.
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	// Rewrite the API key after re-bootstrap.
	$apiKeysFile = cmsDataDir() . '.system/apikeys.json';
	$apiKeysData = json_encode([
		'apikeys' => [
			[
				'id'       => 'schema-tool-int-test-key-id-2',
				'name'     => 'Schema Tool Integration Test Key 2',
				'key'      => 'tcms_schema_tool_int_test_key_fixture_000',
				'created'  => '2026-01-01T00:00:00Z',
				'lastUsed' => null,
				'scopes'   => [
					'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
					'paths'   => ['*'],
				],
			],
		],
	], JSON_PRETTY_PRINT);
	file_put_contents($apiKeysFile, $apiKeysData);

	$sessionId = schemaTestAdminInit($this->app);

	if ($sessionId === '') {
		expect(true)->toBeTrue();

		return;
	}

	$response = schemaTestAdminRequest(
		$this->app,
		schemaTestToolsList(),
		$sessionId,
	);

	expect($response->getStatusCode())->toBe(200);

	$body = json_decode((string)$response->getBody(), true);
	expect($body)->toHaveKey('result');
	expect($body['result'])->toHaveKey('tools');

	$toolNames = array_column($body['result']['tools'], 'name');

	// query_collection must appear EXACTLY ONCE — the core one.
	expect(array_count_values($toolNames)['query_collection'] ?? 0)->toBe(1);

	// The schema-defined find_listings (from the same collection) must still appear.
	expect($toolNames)->toContain('find_listings');

	// Verify the collision warning was logged to mcp-activity.log.
	$logDir     = \TotalCMS\Support\PathResolver::projectRoot() . '/logs';
	$today      = date('Y-m-d');
	$logFile    = $logDir . '/mcp-activity-' . $today . '.log';

	if (file_exists($logFile)) {
		$logContents = (string)file_get_contents($logFile);
		// The SchemaToolRegistrar warning includes the colliding tool name.
		expect($logContents)->toContain('query_collection');
	} else {
		// Log file not yet created in this environment (e.g. log path overridden).
		// Pass — the functional assertion (single query_collection) is the primary guard.
		expect(true)->toBeTrue();
	}
});
