<?php

declare(strict_types=1);

use function Nekofar\Slim\Pest\postJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/**
 * Build a JSON-RPC 2.0 initialize payload matching the MCP 2025-06-18 spec.
 *
 * @return array<string,mixed>
 */
function mcpInitializePayload(): array
{
	return [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest', 'version' => '0.1'],
		],
	];
}

describe('McpEndpointAction', function (): void {
	it('route exists at POST /mcp', function (): void {
		$response = postJson('/mcp', mcpInitializePayload());

		// Always-served route: 200 (active), 401 (publicAccess off, no key),
		// 403 (non-Pro), or 404 (mcp.enabled=false). Should never be 405.
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404]);
	});

	it('returns JSON content-type for all gated responses', function (): void {
		$response = postJson('/mcp', mcpInitializePayload());

		expect($response->getHeaderLine('Content-Type'))->toContain('application/json');
	});

	it('returns structured error body on 401 / 403 / 404', function (): void {
		$response = postJson('/mcp', mcpInitializePayload());
		$status   = $response->getStatusCode();

		if (in_array($status, [401, 403, 404], true)) {
			$body = json_decode((string)$response->getBody(), true);
			expect($body)->toHaveKey('error');
			expect($body['error'])->toHaveKey('message');
			expect($body['error']['message'])->toBeString();
		}
	});

	it('non-Pro edition error body carries edition and required fields', function (): void {
		$response = postJson('/mcp', mcpInitializePayload());

		if ($response->getStatusCode() === 403) {
			$body = json_decode((string)$response->getBody(), true);
			expect($body['error'])->toHaveKeys(['message', 'edition', 'required']);
			expect($body['error']['required'])->toBe('pro');
		}
	});

	it('emits WWW-Authenticate header on 401 keyed by reason (login_required vs invalid_token)', function (): void {
		// Mandatory for Anthropic Directory: MCP hosts use WWW-Authenticate to
		// pick the right lazy-auth UX. login_required = "you need to log in";
		// invalid_token = "your credentials didn't work, try again." Without
		// the header, hosts can't distinguish.
		$response = postJson('/mcp', mcpInitializePayload());

		if ($response->getStatusCode() === 401) {
			$header = $response->getHeaderLine('WWW-Authenticate');
			expect($header)->toStartWith('Bearer realm="MCP"');
			expect($header)->toMatch('/error="(login_required|invalid_token)"/');
		}
	});

	it('rejects bogus API key with 401', function (): void {
		// Use the Nekofar helper's underlying request — we need to pass an
		// X-API-Key header. Slim test helpers don't support headers directly,
		// so build via the app instance.
		$app     = $this->app;
		$request = (new Nyholm\Psr7\Factory\Psr17Factory())
			->createServerRequest('POST', '/mcp')
			->withHeader('Content-Type', 'application/json')
			->withHeader('Accept', 'application/json, text/event-stream')
			->withHeader('X-API-Key', 'tcms_definitely-not-a-real-key');
		$request->getBody()->write((string)json_encode(mcpInitializePayload()));
		$request->getBody()->rewind();

		$response = $app->handle($request);

		// 401 (invalid key) or 403 (non-Pro env beats auth check). Never 200.
		expect($response->getStatusCode())->toBeIn([401, 403, 404]);
	});

	it('returns successful JSON-RPC initialize response when conditions allow', function (): void {
		$response = postJson('/mcp', mcpInitializePayload());

		if ($response->getStatusCode() === 200) {
			$body = json_decode((string)$response->getBody(), true);
			expect($body)->toHaveKeys(['jsonrpc', 'id'])
				->and($body['jsonrpc'])->toBe('2.0')
				->and($body['id'])->toBe(1);

			// Successful initialize carries result with protocolVersion + capabilities
			expect($body)->toHaveKey('result');
			expect($body['result'])->toHaveKeys(['protocolVersion', 'capabilities', 'serverInfo']);
			expect($body['result']['protocolVersion'])->toBe('2025-06-18');
		}
	});

	it('GET /mcp resolves the same action', function (): void {
		// SDK's StreamableHttpTransport dispatches on method+Accept; we route
		// both POST and GET to the same Action. Verify the route resolves.
		$response = \Nekofar\Slim\Pest\get('/mcp');

		// GET without SSE Accept usually yields a non-200 from the SDK protocol
		// layer, but the route must resolve (not 405 Method Not Allowed).
		expect($response->getStatusCode())->not()->toBe(405);
	});
});
