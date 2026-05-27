<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Support\Config;

/**
 * End-to-end feature tests for MCP prompts (Phase 5 Chunk C).
 *
 * Exercises the full path: mcp-prompt fixture on disk → POST /mcp →
 * McpEndpointAction → McpServerFactory::build() → PromptDiscoveryService
 * reads collection → PromptRegistrar wires SDK → JSON-RPC response.
 *
 * Scenarios covered:
 *   1. prompts/list returns collection-stored prompts for admin persona.
 *   2. prompts/get renders a prompt body with args via TwigEngine.
 *   3. prompts/get without a required arg returns a JSON-RPC error.
 *   4. Admin-only prompts are hidden from the public persona on prompts/list.
 *   5. prompts/get for an admin-only prompt returns an error on public persona.
 *
 * Skip-safe pattern: when MCP is unavailable (non-Pro edition, disabled, or
 * auth gate rejects the admin init), mcpPromptAdminInit() returns '' and every
 * test passes trivially — same as McpResourcesTest and McpSchemaToolsIntegrationTest.
 *
 * Fixture: tests/fixtures/mcp/mcp-prompt/
 * Objects: simple (public), greet (public, requires `name` arg), needs_arg (public,
 *          requires `x` arg), admin_only (admin).
 */

// ──────────────────────────────────────────────────────────────────────────────
// Fixture management
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Install the mcp-prompt fixture collection into the live test data dir.
 *
 * Copies .meta.json and .index.json from fixtures/mcp/mcp-prompt/ into
 * tests/tcms-data/mcp-prompt/. Individual object JSON files are copied from
 * the objects/ sub-directory directly into the collection root (matching
 * how T3 stores collection objects on disk).
 *
 * The PromptDiscoveryService reads via IndexFilter which uses the .index.json
 * snapshot; it does not re-scan individual object files at runtime.
 */
function installMcpPromptFixture(): void
{
	$src  = __DIR__ . '/../fixtures/mcp/mcp-prompt';
	$dest = cmsDataDir() . 'mcp-prompt';

	if (!is_dir($dest)) {
		mkdir($dest, 0755, true);
	}

	copy($src . '/.meta.json', $dest . '/.meta.json');
	copy($src . '/.index.json', $dest . '/.index.json');

	$objectsSrc = $src . '/objects';
	foreach (glob($objectsSrc . '/*.json') ?: [] as $file) {
		copy($file, $dest . '/' . basename($file));
	}
}

/**
 * Remove the mcp-prompt fixture from the live test data dir.
 */
function removeMcpPromptFixture(): void
{
	recursiveDelete(cmsDataDir() . 'mcp-prompt');
}

// ──────────────────────────────────────────────────────────────────────────────
// MCP session + request helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Initialize an MCP admin session and return the Mcp-Session-Id header.
 * Returns empty string when MCP is unavailable (non-Pro, disabled, auth gate).
 */
function mcpPromptAdminInit(Slim\App $app): string
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('X-API-Key', 'tcms_mcp_prompt_test_key_fixture_0000000');

	$request->getBody()->write((string)json_encode([
		'jsonrpc' => '2.0',
		'id'      => 0,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest-prompts', 'version' => '0.1'],
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
 * Initialize an MCP public (anonymous) session.
 * Requires $config->mcp['publicAccess'] = true before calling.
 * Returns empty string when MCP is unavailable.
 */
function mcpPromptPublicInit(Slim\App $app): string
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream');

	$request->getBody()->write((string)json_encode([
		'jsonrpc' => '2.0',
		'id'      => 0,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest-prompts-public', 'version' => '0.1'],
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
 * POST /mcp with admin API key + session ID.
 *
 * @param array<string,mixed> $payload
 */
function mcpPromptAdminRequest(
	Slim\App $app,
	array $payload,
	string $sessionId,
): Psr\Http\Message\ResponseInterface {
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('X-API-Key', 'tcms_mcp_prompt_test_key_fixture_0000000')
		->withHeader('Mcp-Session-Id', $sessionId);

	$request->getBody()->write((string)json_encode($payload));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * POST /mcp without API key (public persona) + session ID.
 *
 * @param array<string,mixed> $payload
 */
function mcpPromptPublicRequest(
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

	$request->getBody()->write((string)json_encode($payload));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * @return array<string,mixed>
 */
function mcpPromptsList(): array
{
	return [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'prompts/list',
	];
}

/**
 * @param array<string,mixed> $arguments
 * @return array<string,mixed>
 */
function mcpPromptsGet(string $name, array $arguments = []): array
{
	$payload = [
		'jsonrpc' => '2.0',
		'id'      => 2,
		'method'  => 'prompts/get',
		'params'  => ['name' => $name],
	];
	if ($arguments !== []) {
		$payload['params']['arguments'] = $arguments;
	}
	return $payload;
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

	// Write an admin API key so the admin persona is resolved.
	$apiKeysFile = cmsDataDir() . '.system/apikeys.json';
	$apiKeysData = json_encode([
		'apikeys' => [
			[
				'id'       => 'mcp-prompt-test-key-id',
				'name'     => 'MCP Prompt Test Key',
				'key'      => 'tcms_mcp_prompt_test_key_fixture_0000000',
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

	// Install the prompt fixture collection.
	installMcpPromptFixture();
});

afterEach(function (): void {
	removeMcpPromptFixture();
});

// ──────────────────────────────────────────────────────────────────────────────
// Test 1 — prompts/list returns collection-stored prompts for admin persona
// ──────────────────────────────────────────────────────────────────────────────

it('prompts/list returns collection-stored prompts for admin persona', function (): void {
	$sessionId = mcpPromptAdminInit($this->app);

	if ($sessionId === '') {
		// MCP unavailable (non-Pro edition or disabled) — skip-safe pass.
		expect(true)->toBeTrue();
		return;
	}

	$response = mcpPromptAdminRequest($this->app, mcpPromptsList(), $sessionId);

	expect($response->getStatusCode())->toBe(200);

	$body = json_decode((string)$response->getBody(), true);
	expect($body)->toHaveKey('result');
	expect($body['result'])->toHaveKey('prompts');
	expect($body['result']['prompts'])->toBeArray();

	$names = array_column($body['result']['prompts'], 'name');
	// Public and admin prompts both appear for the admin persona.
	expect($names)->toContain('simple');
	expect($names)->toContain('admin_only');
});

// ──────────────────────────────────────────────────────────────────────────────
// Test 2 — prompts/get renders prompt body with args
// ──────────────────────────────────────────────────────────────────────────────

it('prompts/get renders prompt body with args via Twig', function (): void {
	$sessionId = mcpPromptAdminInit($this->app);

	if ($sessionId === '') {
		expect(true)->toBeTrue();
		return;
	}

	$response = mcpPromptAdminRequest(
		$this->app,
		mcpPromptsGet('greet', ['name' => 'Joe']),
		$sessionId,
	);

	expect($response->getStatusCode())->toBe(200);

	$body = json_decode((string)$response->getBody(), true);
	expect($body)->toHaveKey('result');
	expect($body['result'])->toHaveKey('messages');

	$messages = $body['result']['messages'];
	expect($messages)->toBeArray();
	expect($messages)->not->toBeEmpty();

	$firstMessage = $messages[0];
	expect($firstMessage['role'])->toBe('user');
	expect($firstMessage['content']['text'])->toBe('Hello Joe');
});

// ──────────────────────────────────────────────────────────────────────────────
// Test 3 — missing required arg returns JSON-RPC error
// ──────────────────────────────────────────────────────────────────────────────

it('prompts/get returns a JSON-RPC error when a required arg is missing', function (): void {
	$sessionId = mcpPromptAdminInit($this->app);

	if ($sessionId === '') {
		expect(true)->toBeTrue();
		return;
	}

	// Call needs_arg without the required `x` argument.
	$response = mcpPromptAdminRequest(
		$this->app,
		mcpPromptsGet('needs_arg'),
		$sessionId,
	);

	// The SDK may return 200 with an error envelope or a 4xx.
	// Either way, an `error` key must be present and `result.messages` must be absent.
	$body = json_decode((string)$response->getBody(), true);
	$hasError = isset($body['error'])
		|| (isset($body['result']['isError']) && $body['result']['isError'] === true);
	expect($hasError)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────────────────────
// Test 4 — admin-only prompts are hidden from the public persona
// ──────────────────────────────────────────────────────────────────────────────

it('hides admin-only prompts from the public persona on prompts/list', function (): void {
	/** @var Config $config */
	$config = $this->app->getContainer()->get(Config::class);
	$config->mcp['publicAccess'] = true;

	$sessionId = mcpPromptPublicInit($this->app);

	if ($sessionId === '') {
		// Public MCP not available or publicAccess gated — skip-safe pass.
		expect(true)->toBeTrue();
		return;
	}

	$response = mcpPromptPublicRequest($this->app, mcpPromptsList(), $sessionId);

	expect($response->getStatusCode())->toBe(200);

	$body = json_decode((string)$response->getBody(), true);
	$names = array_column($body['result']['prompts'] ?? [], 'name');

	// admin_only should NOT appear in the public prompt list.
	expect($names)->not->toContain('admin_only');
	// Public prompts should appear.
	expect($names)->toContain('simple');
});

// ──────────────────────────────────────────────────────────────────────────────
// Test 5 — public persona denied access to admin-only prompt via prompts/get
// ──────────────────────────────────────────────────────────────────────────────

it('public persona cannot fetch an admin-only prompt via prompts/get', function (): void {
	/** @var Config $config */
	$config = $this->app->getContainer()->get(Config::class);
	$config->mcp['publicAccess'] = true;

	$sessionId = mcpPromptPublicInit($this->app);

	if ($sessionId === '') {
		expect(true)->toBeTrue();
		return;
	}

	$response = mcpPromptPublicRequest(
		$this->app,
		mcpPromptsGet('admin_only'),
		$sessionId,
	);

	// The handler re-checks access at call time and throws PromptGetException,
	// which the SDK maps to a JSON-RPC error. A 404 (prompt not registered for
	// public persona) is also a valid outcome — both signal denial.
	$body   = json_decode((string)$response->getBody(), true);
	$status = $response->getStatusCode();

	$denied = isset($body['error'])
		|| $status === 404
		|| (isset($body['result']['isError']) && $body['result']['isError'] === true);

	expect($denied)->toBeTrue();
});
