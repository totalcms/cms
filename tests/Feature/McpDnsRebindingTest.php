<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Support\Config;

const DNS_TEST_KEY = 'tcms_dns_rebinding_test_key_0000000000000';

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	// Admin API key so requests pass the auth gate and reach the SDK transport,
	// where the DNS-rebinding middleware lives. Without this they'd 401 first.
	$apiKeysFile = cmsDataDir() . '.system/apikeys.json';
	file_put_contents($apiKeysFile, (string)json_encode([
		'apikeys' => [[
			'id'       => 'test-dns-key',
			'name'     => 'DNS Rebinding Test Key',
			'key'      => DNS_TEST_KEY,
			'created'  => '2026-01-01T00:00:00Z',
			'lastUsed' => null,
			'scopes'   => ['methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'paths' => ['*']],
		]],
	], JSON_PRETTY_PRINT));
});

/**
 * POST an MCP `initialize` to /mcp on a given Host, optionally with an Origin.
 * Uses an absolute URI so the request's host (used by the DNS-rebinding allowlist)
 * is meaningful.
 */
function dnsRebindingRequest(Slim\App $app, string $host, ?string $origin): Psr\Http\Message\ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', 'http://' . $host . '/mcp')
		->withHeader('Host', $host)
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('X-API-Key', DNS_TEST_KEY);

	if ($origin !== null) {
		$request = $request->withHeader('Origin', $origin);
	}

	$request->getBody()->write((string)json_encode([
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-11-25',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest', 'version' => '0.1'],
		],
	]));
	$request->getBody()->rewind();

	return $app->handle($request);
}

function dnsSetAllowedOrigins(Slim\App $app, array $origins): void
{
	$app->getContainer()->get(Config::class)->mcp['allowedOrigins'] = $origins;
}

/**
 * GET /mcp with the SSE Accept header on a given Host, optionally with an
 * Origin. Exercises the listening-stream's own Origin/Host check
 * (McpTransportSecurity::originAllowed()), which runs before
 * StreamableHttpTransport — and its DnsRebindingProtectionMiddleware — is
 * ever constructed for this branch.
 */
function dnsRebindingGetStreamRequest(Slim\App $app, string $host, ?string $origin): Psr\Http\Message\ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('GET', 'http://' . $host . '/mcp')
		->withHeader('Host', $host)
		->withHeader('Accept', 'text/event-stream')
		->withHeader('X-API-Key', DNS_TEST_KEY);

	if ($origin !== null) {
		$request = $request->withHeader('Origin', $origin);
	}

	return $app->handle($request);
}

describe('MCP DNS-rebinding / Origin protection', function (): void {
	it('does not 403 a non-localhost Host in open mode (production regression guard)', function (): void {
		// Open by default: an empty allowlist must NOT apply the SDK's localhost-only
		// DNS-rebinding, or every production request (Host = the site domain) would 403.
		dnsSetAllowedOrigins($this->app, []);

		$response = dnsRebindingRequest($this->app, 'mcp.example.com', null);

		expect((string)$response->getBody())->not->toContain('Invalid Host header');
		expect($response->getStatusCode())->not->toBe(403);
	});

	it('403s a disallowed Origin in restricted mode (spec MUST)', function (): void {
		dnsSetAllowedOrigins($this->app, ['https://app.example.com']);

		$response = dnsRebindingRequest($this->app, 'mcp.example.com', 'https://evil.example');

		expect($response->getStatusCode())->toBe(403);
		expect((string)$response->getBody())->toContain('Invalid Origin');
	});

	it('allows a configured Origin in restricted mode', function (): void {
		dnsSetAllowedOrigins($this->app, ['https://app.example.com']);

		$response = dnsRebindingRequest($this->app, 'mcp.example.com', 'https://app.example.com');

		expect((string)$response->getBody())->not->toContain('Invalid Origin');
		expect($response->getStatusCode())->not->toBe(403);
	});

	it('allows the server own host as Origin in restricted mode (same-origin admin)', function (): void {
		dnsSetAllowedOrigins($this->app, ['https://app.example.com']);

		$response = dnsRebindingRequest($this->app, 'mcp.example.com', 'https://mcp.example.com');

		expect((string)$response->getBody())->not->toContain('Invalid Origin');
		expect($response->getStatusCode())->not->toBe(403);
	});
});

// ──────────────────────────────────────────────────────────────────────────────
// GET listening-stream branch — mirrors the POST checks above. This branch
// returns before StreamableHttpTransport (and its DnsRebindingProtectionMiddleware)
// is ever constructed, so it needs its own Origin/Host check
// (McpTransportSecurity::originAllowed()) to keep the operator-facing promise
// that restricted mode enforces the spec's 403-on-invalid-Origin for every
// verb, not just POST.
// ──────────────────────────────────────────────────────────────────────────────

describe('MCP DNS-rebinding / Origin protection — GET listening stream', function (): void {
	it('does not 403 a non-localhost Host in open mode (production regression guard)', function (): void {
		dnsSetAllowedOrigins($this->app, []);

		$response = dnsRebindingGetStreamRequest($this->app, 'mcp.example.com', null);

		expect($response->getStatusCode())->not->toBe(403);
	});

	it('403s a disallowed Origin in restricted mode, same as POST', function (): void {
		dnsSetAllowedOrigins($this->app, ['https://app.example.com']);

		$response = dnsRebindingGetStreamRequest($this->app, 'mcp.example.com', 'https://evil.example');

		expect($response->getStatusCode())->toBe(403);
		expect((string)$response->getBody())->toContain('Invalid Origin');
		// The 403 must be the plain-text Origin rejection, never a stream.
		expect($response->getHeaderLine('Content-Type'))->not->toContain('text/event-stream');
	});

	it('allows a configured Origin in restricted mode', function (): void {
		dnsSetAllowedOrigins($this->app, ['https://app.example.com']);

		$response = dnsRebindingGetStreamRequest($this->app, 'mcp.example.com', 'https://app.example.com');

		expect($response->getStatusCode())->not->toBe(403);
	});

	it('allows the server own host as Origin in restricted mode (same-origin admin)', function (): void {
		dnsSetAllowedOrigins($this->app, ['https://app.example.com']);

		$response = dnsRebindingGetStreamRequest($this->app, 'mcp.example.com', 'https://mcp.example.com');

		expect($response->getStatusCode())->not->toBe(403);
	});
});
