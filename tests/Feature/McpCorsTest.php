<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Support\Config;

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
 * Build a PSR-7 request with the given method, route, and optional Origin
 * header. Mirrors the pattern used by McpResourcesTest / McpSubscriptionsTest.
 */
function corsRequest(string $method, string $path, ?string $origin = null): Psr\Http\Message\ServerRequestInterface
{
	$request = (new Psr17Factory())->createServerRequest($method, $path);
	if ($origin !== null) {
		$request = $request->withHeader('Origin', $origin);
	}
	if ($method === 'OPTIONS') {
		$request = $request->withHeader('Access-Control-Request-Method', 'POST');
	}

	return $request;
}

function setMcpAllowedOrigins(Slim\App $app, array $origins): void
{
	$app->getContainer()->get(Config::class)->mcp['allowedOrigins'] = $origins;
}

describe('McpCors — /mcp endpoint', function (): void {
	it('treats an empty allowlist as open (echoes any origin)', function (): void {
		setMcpAllowedOrigins($this->app, []);

		// Blank allowlist == `*`: the preflight echoes the request origin so
		// browser-based AI clients work out of the box.
		$response = $this->app->handle(corsRequest('OPTIONS', '/mcp', 'https://example.com'));

		expect($response->getStatusCode())->toBe(204);
		expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('https://example.com');
	});

	it('echoes an allowed origin on preflight', function (): void {
		setMcpAllowedOrigins($this->app, ['https://app.example.com']);

		$response = $this->app->handle(corsRequest('OPTIONS', '/mcp', 'https://app.example.com'));

		expect($response->getStatusCode())->toBe(204);
		expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('https://app.example.com');
		expect($response->getHeaderLine('Access-Control-Allow-Methods'))->toContain('POST');
		expect($response->getHeaderLine('Access-Control-Allow-Headers'))->toContain('Mcp-Session-Id');
	});

	it('returns no Allow-Origin header for a disallowed origin on preflight', function (): void {
		setMcpAllowedOrigins($this->app, ['https://app.example.com']);

		$response = $this->app->handle(corsRequest('OPTIONS', '/mcp', 'https://evil.example'));

		expect($response->getStatusCode())->toBe(204);
		expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('');
	});

	it('supports wildcard echoing the request origin', function (): void {
		setMcpAllowedOrigins($this->app, ['*']);

		$response = $this->app->handle(corsRequest('OPTIONS', '/mcp', 'https://anywhere.test'));

		expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('https://anywhere.test');
	});
});

describe('McpCors — /.well-known/mcp.json discovery', function (): void {
	it('attaches the Allow-Origin header to discovery responses for allowed origins', function (): void {
		setMcpAllowedOrigins($this->app, ['https://claude.ai']);

		$response = $this->app->handle(corsRequest('GET', '/.well-known/mcp.json', 'https://claude.ai'));

		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('https://claude.ai');
		expect($response->getHeaderLine('Vary'))->toBe('Origin');
	});

	it('responds 204 with Allow-Methods for OPTIONS preflight to discovery', function (): void {
		setMcpAllowedOrigins($this->app, ['https://claude.ai']);

		$response = $this->app->handle(corsRequest('OPTIONS', '/.well-known/mcp.json', 'https://claude.ai'));

		expect($response->getStatusCode())->toBe(204);
		expect($response->getHeaderLine('Access-Control-Allow-Methods'))->toContain('GET');
	});
});
