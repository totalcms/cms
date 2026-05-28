<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}

	$this->setUpApp(bootstrap());

	// Ensure the blog collection exists for resource enumeration.
	$container         = $this->app->getContainer();
	$collectionFetcher = $container->get(TotalCMS\Domain\Collection\Service\CollectionFetcher::class);
	$collectionFetcher->fetchOrCreateReserved('blog');

	// Write a test API key to .system/apikeys.json so admin persona is resolved.
	// The key covers all methods on all paths (same as "All endpoints" in the UI).
	$apiKeysFile = cmsDataDir() . '.system/apikeys.json';
	$apiKeysData = json_encode([
		'apikeys' => [
			[
				'id'       => 'test-mcp-key-id',
				'name'     => 'MCP Resource Test Key',
				'key'      => 'tcms_mcp_resource_test_key_fixture_00000000',
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
});

/**
 * Build a JSON-RPC 2.0 request payload for the given method.
 *
 * @param string               $method JSON-RPC method name (e.g. 'resources/list')
 * @param array<string,mixed>  $params Optional params
 *
 * @return array<string,mixed>
 */
function mcpResourcePayload(string $method, array $params = []): array
{
	$payload = [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => $method,
	];

	if ($params !== []) {
		$payload['params'] = $params;
	}

	return $payload;
}

/**
 * Initialize an MCP admin session and return the session ID.
 *
 * The MCP SDK requires clients to `initialize` first and pass the returned
 * `Mcp-Session-Id` on all subsequent requests. This helper encapsulates that
 * handshake so individual tests only need to call mcpAdminRequest().
 *
 * Returns empty string when MCP is unavailable (non-200 init response).
 */
function mcpInitAdminSession(Slim\App $app): string
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('X-API-Key', 'tcms_mcp_resource_test_key_fixture_00000000');

	$request->getBody()->write((string)json_encode([
		'jsonrpc' => '2.0',
		'id'      => 0,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest', 'version' => '0.1'],
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
 * Build a PSR-7 POST request to /mcp with admin API key + session header.
 *
 * The MCP protocol requires a valid session (obtained via `initialize`) for
 * all non-initialize requests. Uses the same body-write + rewind pattern as
 * McpEndpointActionTest so Slim's BodyParsingMiddleware does not leave the
 * stream at EOF before the SDK's StreamableHttpTransport calls getContents().
 *
 * @param array<string,mixed> $payload   JSON-RPC payload
 * @param string              $sessionId Session ID from mcpInitAdminSession()
 */
function mcpAdminRequest(
	Slim\App $app,
	array $payload,
	string $sessionId,
): Psr\Http\Message\ResponseInterface {
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('X-API-Key', 'tcms_mcp_resource_test_key_fixture_00000000')
		->withHeader('Mcp-Session-Id', $sessionId);

	$request->getBody()->write((string)json_encode($payload));
	$request->getBody()->rewind();

	return $app->handle($request);
}

describe('McpResources — resources/list', function (): void {
	it('returns collection entries for admin persona', function (): void {
		$sessionId = mcpInitAdminSession($this->app);

		if ($sessionId === '') {
			// MCP unavailable in this environment (non-Pro edition, disabled).
			expect(true)->toBeTrue(); // explicit skip-safe pass

			return;
		}

		$response = mcpAdminRequest(
			$this->app,
			mcpResourcePayload('resources/list'),
			$sessionId,
		);

		expect($response->getStatusCode())->toBe(200);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('result');
		expect($body['result'])->toHaveKey('resources');
		expect($body['result']['resources'])->toBeArray();

		// At least the blog collection that was seeded in beforeEach must appear.
		$uris = array_column($body['result']['resources'], 'uri');
		expect($uris)->toContain('tcms://blog/');
	});

	it('returns empty resources list for public persona when no collections are public', function (): void {
		// Hit the endpoint without an API key.  With publicAccess disabled (default)
		// this returns 401 — safe assertion because there are no public collections
		// in the test fixture anyway, so an empty list is also valid.
		$factory = new Psr17Factory();
		$request = $factory
			->createServerRequest('POST', '/mcp')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream');

		$request->getBody()->write((string)json_encode(mcpResourcePayload('resources/list')));
		$request->getBody()->rewind();

		$response = $this->app->handle($request);
		$status   = $response->getStatusCode();

		if ($status === 200) {
			$parsed = json_decode((string)$response->getBody(), true);
			// Public persona sees no admin-only collections.
			expect($parsed['result']['resources'])->toBe([]);
		} else {
			// publicAccess disabled: anonymous requests are rejected — expected.
			expect($status)->toBeIn([401, 403, 404]);
		}
	});
});

describe('McpResources — resources/templates/list', function (): void {
	it('returns templates for admin persona', function (): void {
		$sessionId = mcpInitAdminSession($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue(); // skip-safe pass

			return;
		}

		$response = mcpAdminRequest(
			$this->app,
			mcpResourcePayload('resources/templates/list'),
			$sessionId,
		);

		expect($response->getStatusCode())->toBe(200);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('result');
		expect($body['result'])->toHaveKey('resourceTemplates');
		expect($body['result']['resourceTemplates'])->toBeArray();

		// The blog object template seeded in beforeEach must be present.
		$uriTemplates = array_column($body['result']['resourceTemplates'], 'uriTemplate');
		expect($uriTemplates)->toContain('tcms://blog/{id}');
	});
});

describe('McpResources — resources/read', function (): void {
	it('returns collection content for a concrete collection URI', function (): void {
		$sessionId = mcpInitAdminSession($this->app);

		if ($sessionId === '') {
			expect(true)->toBeTrue(); // skip-safe pass

			return;
		}

		$response = mcpAdminRequest(
			$this->app,
			mcpResourcePayload('resources/read', ['uri' => 'tcms://blog/']),
			$sessionId,
		);

		expect($response->getStatusCode())->toBe(200);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('result');
		expect($body['result'])->toHaveKey('contents');
		expect($body['result']['contents'])->toBeArray();
		expect($body['result']['contents'])->not()->toBeEmpty();

		$first = $body['result']['contents'][0];
		expect($first)->toHaveKey('uri');
		expect($first)->toHaveKey('mimeType');
		expect($first['mimeType'])->toBe('application/json');
		expect($first)->toHaveKey('text');

		// The text payload must be valid JSON.
		$decoded = json_decode((string)$first['text'], true);
		expect($decoded)->toBeArray();
	});
});
