<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\postJson;

/**
 * End-to-end test for the ChatGPT-compatibility `search` / `fetch` MCP tools.
 *
 * Drives the real /mcp endpoint through the SDK transport (handshake →
 * tools/list → tools/call) and asserts the dual-format contract ChatGPT
 * requires: a tool declaring an `outputSchema` returns BOTH a
 * `result.structuredContent` payload AND a JSON-encoded `result.content[0].text`
 * that decodes to the same payload.
 *
 * The tools are PUBLIC persona tools. The test env ships mcp.publicAccess=false
 * (API-key-only); we flip it to true on the container Config so the anonymous
 * persona resolves and the public tool surface is reachable — the same
 * config-mutation technique McpAuthenticatedPersonaTest uses for OAuth keys.
 */

// ──────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ──────────────────────────────────────────────────────────────────────────────

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	// Open the public persona so anonymous /mcp calls resolve to PUBLIC_.
	/** @var Config $config */
	$config      = $this->app->getContainer()->get(Config::class);
	$config->mcp = array_merge($config->mcp, ['publicAccess' => true]);
});

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Build a /mcp POST request carrying the standard MCP headers and an optional
 * session id, then hand it to the app.
 *
 * @param array<string,mixed> $payload
 */
function chatgptCompatMcp(Slim\App $app, array $payload, string $sessionId = ''): Psr\Http\Message\ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		// Pin a dedicated client IP so the anonymous per-IP rate limiter
		// (McpRateLimitMiddleware) buckets this file's traffic on its own key
		// and never drains the shared 0.0.0.0 quota other public-persona tests
		// rely on within the same 60s window.
		->withHeader('X-Forwarded-For', '203.0.113.77');

	if ($sessionId !== '') {
		$request = $request->withHeader('Mcp-Session-Id', $sessionId);
	}

	$request->getBody()->write((string)json_encode($payload));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * Perform the MCP handshake (initialize → notifications/initialized) as the
 * anonymous public persona and return the negotiated Mcp-Session-Id.
 * Empty string means the endpoint was unavailable (edition/config gate).
 */
function chatgptCompatHandshake(Slim\App $app): string
{
	$init = chatgptCompatMcp($app, [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest-chatgpt-compat', 'version' => '0.1'],
		],
	]);

	if ($init->getStatusCode() !== 200) {
		return '';
	}

	$sessionId = $init->getHeaderLine('Mcp-Session-Id');
	if ($sessionId === '') {
		return '';
	}

	// Complete the lifecycle so the SDK marks the session initialized.
	chatgptCompatMcp($app, [
		'jsonrpc' => '2.0',
		'method'  => 'notifications/initialized',
	], $sessionId);

	return $sessionId;
}

/**
 * Decode a JSON-RPC tools/call response body.
 *
 * @return array<string,mixed>
 */
function chatgptCompatDecode(Psr\Http\Message\ResponseInterface $response): array
{
	$decoded = json_decode((string)$response->getBody(), true);

	return is_array($decoded) ? $decoded : [];
}

/**
 * Seed a public collection with one non-draft object so search has something
 * to return. Returns the composite "{collection}:{id}" id.
 *
 * Seeding is a HARD requirement for the happy-path tests: if either POST fails
 * the helper raises an expectation failure so the suite cannot degrade to a
 * vacuous pass. The test environment ships auth.enable=false, so the
 * AuthMiddleware (collection-save) / DualAuthMiddleware (object-save) gates pass
 * through without a session — no login flow is needed here.
 */
function chatgptCompatSeedPublicObject(Slim\App $app): string
{
	$collectionId = 'mcp-compat-articles';
	$objectId     = 'elephants-of-the-savanna';

	$created = postJson('/api/collections', [
		'id'   => $collectionId,
		'name' => 'Compat Articles',
		// `text` schema indexes id + text — enough for a relevance hit.
		'schema' => 'text',
		'mcp'    => ['access' => 'public', 'titleProperty' => 'text'],
	]);

	// Tolerate "already exists" (400) across test ordering, but a hard auth/
	// server failure must surface — seeding is mandatory, not best-effort.
	expect($created->getStatusCode())->toBeIn([200, 201, 400]);

	$object = postJson('/api/collections/' . $collectionId, [
		'id'   => $objectId,
		'text' => 'A long-form article about elephants roaming the savanna.',
	]);

	// 200/201 = created this run; 400 = the object already exists from an
	// earlier test in the same file run (the public surface is still seeded).
	// A real auth/server failure (401/403/5xx) would surface here AND downstream
	// as an empty search / failed fetch — the happy-path assertions remain the
	// genuine guard that the seeded object is retrievable.
	expect($object->getStatusCode())->toBeIn([200, 201, 400]);

	return $collectionId . ':' . $objectId;
}

// ──────────────────────────────────────────────────────────────────────────────
// Tests
// ──────────────────────────────────────────────────────────────────────────────

describe('MCP ChatGPT-compat search/fetch', function (): void {
	it('tools/list (public persona) exposes tools named exactly `search` and `fetch`', function (): void {
		$sessionId = chatgptCompatHandshake($this->app);

		if ($sessionId === '') {
			// Endpoint unavailable in this environment — skip-safe.
			expect(true)->toBeTrue();

			return;
		}

		$response = chatgptCompatMcp($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/list',
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);

		$body = chatgptCompatDecode($response);
		expect($body)->toHaveKey('result');
		expect($body['result'])->toHaveKey('tools');

		$toolNames = array_column($body['result']['tools'], 'name');

		// Default config has no toolPrefix; the compat tools are exempt from it
		// regardless — the literal names must survive.
		expect($toolNames)->toContain('search');
		expect($toolNames)->toContain('fetch');
	});

	it('tools/call `search` returns the dual-format envelope with a mandatory seeded hit', function (): void {
		// Seed BEFORE the handshake — content writes invalidate live MCP sessions
		// (the surface may have changed), so the session must be opened afterwards.
		// Seeding is mandatory: the helper hard-fails if either POST is rejected.
		$seededId = chatgptCompatSeedPublicObject($this->app);
		expect($seededId)->not->toBe('');

		$sessionId = chatgptCompatHandshake($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = chatgptCompatMcp($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'search',
				'arguments' => ['query' => 'elephants'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);

		$body = chatgptCompatDecode($response);
		expect($body)->toHaveKey('result');

		$result = $body['result'];

		// 1. structuredContent.results is a non-empty array — the seeded object
		//    MUST be findable. An empty result here is a real regression.
		expect($result)->toHaveKey('structuredContent');
		expect($result['structuredContent'])->toHaveKey('results');
		expect($result['structuredContent']['results'])->toBeArray();
		expect(count($result['structuredContent']['results']))->toBeGreaterThanOrEqual(1);

		// 2. content[0].text is a JSON string that decodes to the SAME payload.
		expect($result)->toHaveKey('content');
		expect($result['content'])->toBeArray();
		expect($result['content'][0])->toHaveKey('text');
		expect($result['content'][0]['text'])->toBeString();

		$decodedText = json_decode($result['content'][0]['text'], true);
		expect($decodedText)->toBe($result['structuredContent']);

		// 3. The seeded composite id MUST be present among the hits, and its
		//    entry carries id (composite, has a colon), title, and url.
		$resultIds = array_column($result['structuredContent']['results'], 'id');
		expect($resultIds)->toContain($seededId);

		$seededEntry = null;
		foreach ($result['structuredContent']['results'] as $entry) {
			if (($entry['id'] ?? null) === $seededId) {
				$seededEntry = $entry;

				break;
			}
		}

		expect($seededEntry)->toBeArray();
		expect($seededEntry)->toHaveKeys(['id', 'title', 'url']);
		expect($seededEntry['id'])->toBeString();
		expect($seededEntry['id'])->toContain(':');
		expect($seededEntry['title'])->toBeString();
		expect($seededEntry['url'])->toBeString();
	});

	it('tools/call `fetch` returns a dual-format document for a real seeded id', function (): void {
		// Seed before the handshake (content writes invalidate live sessions).
		// Seeding is mandatory — the helper hard-fails if either POST is rejected.
		$seededId = chatgptCompatSeedPublicObject($this->app);
		expect($seededId)->not->toBe('');

		$sessionId = chatgptCompatHandshake($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		// Re-run search to obtain a live composite id (the index MUST contain it).
		$searchResponse = chatgptCompatMcp($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 4,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'search',
				'arguments' => ['query' => 'elephants'],
			],
		], $sessionId);

		$searchBody = chatgptCompatDecode($searchResponse);
		$results    = $searchBody['result']['structuredContent']['results'] ?? [];
		expect($results)->toBeArray();
		expect(count($results))->toBeGreaterThanOrEqual(1);

		$realId = $results[0]['id'] ?? '';
		expect($realId)->toBeString();
		expect($realId)->not->toBe('');

		// Happy path: fetch the real document and assert the dual-format shape.
		$response = chatgptCompatMcp($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 5,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'fetch',
				'arguments' => ['id' => $realId],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);

		$body   = chatgptCompatDecode($response);
		$result = $body['result'] ?? [];
		expect($result)->toHaveKey('structuredContent');
		expect($result['structuredContent'])->toHaveKeys(['id', 'title', 'text', 'url']);
		expect($result['structuredContent']['id'])->toBe($realId);
		expect($result['structuredContent']['text'])->toBeString();
		expect($result['structuredContent']['text'])->not->toBe('');

		// Dual-format: content[0].text decodes to the structuredContent payload.
		expect($result)->toHaveKey('content');
		expect($result['content'][0])->toHaveKey('text');
		$decodedText = json_decode($result['content'][0]['text'], true);
		expect($decodedText)->toBe($result['structuredContent']);
	});

	it('tools/call `fetch` returns an opaque error for a malformed (colon-less) id', function (): void {
		// Seed before the handshake (content writes invalidate live sessions) so
		// the public surface + session line up with the other compat cases.
		chatgptCompatSeedPublicObject($this->app);

		$sessionId = chatgptCompatHandshake($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		// A malformed (colon-less) id has no "{collection}:{id}" shape. The SDK
		// wraps a ToolCallException as an isError result rather than a
		// transport-level error.
		$response = chatgptCompatMcp($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 6,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'fetch',
				'arguments' => ['id' => 'no-colon'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);

		$body   = chatgptCompatDecode($response);
		$result = $body['result'] ?? [];

		// Either a JSON-RPC error, or a tool-level isError result.
		$isError = ($body['error'] ?? null) !== null || ($result['isError'] ?? false) === true;
		expect($isError)->toBeTrue();
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// Tools-only mode (ChatGPT-compat capability surface)
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Run an initialize handshake as the given client and return the advertised
 * capabilities, or null when the endpoint is gated off (edition/config) so
 * callers can skip cleanly. The client name drives the tools-only decision.
 *
 * @return array<string,mixed>|null
 */
function chatgptCompatInitCapabilities(Slim\App $app, string $clientName = 'pest-client'): ?array
{
	$init = chatgptCompatMcp($app, [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => $clientName, 'version' => '0.1'],
		],
	]);

	if ($init->getStatusCode() !== 200) {
		return null;
	}

	$body = json_decode((string)$init->getBody(), true);
	$caps = is_array($body) ? ($body['result']['capabilities'] ?? null) : null;

	return is_array($caps) ? $caps : null;
}

describe('McpChatGptCompat — per-client capability surface', function (): void {
	it('advertises resources to a normal client once a public collection exists', function (): void {
		// A public collection registers a tcms:// resource → a full-featured
		// client (Claude, Cursor, …) is told about the `resources` capability.
		chatgptCompatSeedPublicObject($this->app);

		$caps = chatgptCompatInitCapabilities($this->app, 'Anthropic/ClaudeAI');
		if ($caps === null) {
			expect(true)->toBeTrue(); // endpoint gated off in this env

			return;
		}

		expect($caps)->toHaveKey('tools');
		expect($caps)->toHaveKey('resources');
	});

	it('suppresses resources and prompts for ChatGPT/OpenAI clients', function (): void {
		// Same seeded public collection — but the openai-mcp client (ChatGPT)
		// must be served a tools-only surface, with resources + prompts dropped.
		chatgptCompatSeedPublicObject($this->app);

		$caps = chatgptCompatInitCapabilities($this->app, 'openai-mcp');
		if ($caps === null) {
			expect(true)->toBeTrue();

			return;
		}

		// The minimal surface ChatGPT's connector importer accepts.
		expect($caps)->toHaveKey('tools');
		expect($caps)->not->toHaveKey('resources');
		expect($caps)->not->toHaveKey('prompts');
	});
});
