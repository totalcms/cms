<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;

/**
 * The anonymous-only MCP endpoint at /mcp/public.
 *
 * Claude's consumer apps demand a login whenever a server's OAuth is
 * discoverable, so /mcp gates them off from the public tier. /mcp/public is
 * the same endpoint with the persona pinned to PUBLIC_: credentials are
 * ignored rather than validated, no response ever carries an OAuth
 * challenge, and path-scoped protected-resource metadata for it does not
 * exist. To any client it is indistinguishable from a plain no-auth MCP
 * server, while /mcp keeps serving all three personas.
 */

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	/** @var Config $config */
	$config      = $this->app->getContainer()->get(Config::class);
	$config->mcp = array_merge($config->mcp, ['publicAccess' => true]);
});

/**
 * POST a JSON-RPC payload to /mcp/public with standard MCP headers.
 *
 * @param array<string,mixed>  $payload
 * @param array<string,string> $headers
 */
function mcpPublicRequest(
	Slim\App $app,
	array $payload,
	string $sessionId = '',
	array $headers = [],
): Psr\Http\Message\ResponseInterface {
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp/public')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		// Dedicated IP so the anonymous per-IP rate limiter buckets this
		// file's traffic on its own key (convention across MCP test files).
		->withHeader('X-Forwarded-For', '203.0.113.88');

	foreach ($headers as $name => $value) {
		$request = $request->withHeader($name, $value);
	}
	if ($sessionId !== '') {
		$request = $request->withHeader('Mcp-Session-Id', $sessionId);
	}

	$request->getBody()->write((string)json_encode($payload));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * @param array<string,string> $headers
 */
function mcpPublicInitialize(Slim\App $app, array $headers = []): Psr\Http\Message\ResponseInterface
{
	return mcpPublicRequest($app, [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest-mcp-public', 'version' => '0.1'],
		],
	], '', $headers);
}

describe('McpPublicEndpoint', function (): void {
	it('serves an anonymous initialize', function (): void {
		$response = mcpPublicInitialize($this->app);

		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('Mcp-Session-Id'))->not->toBe('');
	});

	it('lists public tools and only public tools', function (): void {
		$init      = mcpPublicInitialize($this->app);
		$sessionId = $init->getHeaderLine('Mcp-Session-Id');

		$response = mcpPublicRequest($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/list',
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);

		$body  = json_decode((string)$response->getBody(), true);
		$names = array_column($body['result']['tools'] ?? [], 'name');

		expect($names)->toContain('list_collections');
		expect($names)->not->toContain('clear_cache');
	});

	it('ignores credentials instead of validating them', function (): void {
		// A garbage Bearer on /mcp would 401 with an OAuth challenge; the
		// public endpoint must treat every caller as anonymous instead.
		$response = mcpPublicInitialize($this->app, ['Authorization' => 'Bearer garbage']);

		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('WWW-Authenticate'))->toBe('');
	});

	it('ignores API keys too', function (): void {
		$init      = mcpPublicInitialize($this->app, ['X-API-Key' => 'not-a-real-key']);
		$sessionId = $init->getHeaderLine('Mcp-Session-Id');

		// An invalid key 401s on /mcp; here it is simply not consulted.
		expect($init->getStatusCode())->toBe(200);

		$response = mcpPublicRequest($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/list',
		], $sessionId, ['X-API-Key' => 'not-a-real-key']);

		$body  = json_decode((string)$response->getBody(), true);
		$names = array_column($body['result']['tools'] ?? [], 'name');
		expect($names)->not->toContain('clear_cache');
	});

	it('404s when public access is off', function (): void {
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, ['publicAccess' => false]);

		$response = mcpPublicInitialize($this->app);

		expect($response->getStatusCode())->toBe(404);
		expect($response->getHeaderLine('WWW-Authenticate'))->toBe('');
	});

	it('advertises the public endpoint in the MCP discovery document', function (): void {
		$payload = json_decode((string)get('/.well-known/mcp.json')->getBody(), true);

		expect($payload)->toBeArray();
		if (!($payload['disabled'] ?? false)) {
			expect($payload)->toHaveKey('publicEndpoint');
			expect($payload['publicEndpoint'])->toEndWith('/mcp/public');
		}
	});
});
