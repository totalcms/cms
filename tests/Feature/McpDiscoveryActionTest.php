<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('McpDiscoveryAction', function (): void {
	it('responds to GET /.well-known/mcp.json with HTTP 200', function (): void {
		$response = get('/.well-known/mcp.json');

		// Always 200 — agents probing many sites get a deterministic answer
		// instead of guessing from a 404.
		expect($response->getStatusCode())->toBe(200);
	});

	it('returns JSON with mcpVersion field regardless of state', function (): void {
		$response = get('/.well-known/mcp.json');
		$body     = (string)$response->getBody();
		$payload  = json_decode($body, true);

		expect($payload)->toBeArray()
			->and($payload)->toHaveKey('mcpVersion')
			->and($payload['mcpVersion'])->toBe('2025-11-25');
	});

	it('returns content-type application/json', function (): void {
		$response = get('/.well-known/mcp.json');

		expect($response->getHeaderLine('Content-Type'))->toContain('application/json');
	});

	it('shape signals availability via disabled flag', function (): void {
		$response = get('/.well-known/mcp.json');
		$payload  = json_decode((string)$response->getBody(), true);

		// Either the endpoint advertises full discovery, or it sets disabled:true
		// with a reason. Tests should not depend on the test environment's license
		// state — but the shape contract must hold.
		if ($payload['disabled'] ?? false) {
			expect($payload)->toHaveKeys(['reason', 'edition']);
			expect($payload['reason'])->toBeIn(['config', 'edition']);
		} else {
			expect($payload)->toHaveKeys(['endpoint', 'name', 'auth', 'publicTools']);
			expect($payload['auth'])->toHaveKey('apiKey');
			expect($payload['auth']['apiKey'])->toHaveKey('header');
			expect($payload['auth']['apiKey']['header'])->toBe('X-API-Key');
			expect($payload['publicTools'])->toBeArray();
		}
	});

	it('endpoint URL points at /mcp on the requesting host', function (): void {
		$response = get('/.well-known/mcp.json');
		$payload  = json_decode((string)$response->getBody(), true);

		// Only assert URL shape when discovery is active.
		// The Slim test transport produces request URIs without a real
		// scheme/host (e.g. `:///mcp`), so we assert the trailing path
		// contract rather than full URL absolutism — real traffic comes
		// in through a SAPI that populates the URI properly.
		if (!($payload['disabled'] ?? false)) {
			expect($payload['endpoint'])->toBeString()
				->and($payload['endpoint'])->toEndWith('/mcp');
		} else {
			// When disabled, endpoint is intentionally omitted — confirm that.
			expect($payload)->not()->toHaveKey('endpoint');
		}
	});

	it('publicTools is an array when discovery is active', function (): void {
		$response = get('/.well-known/mcp.json');
		$payload  = json_decode((string)$response->getBody(), true);

		if (!($payload['disabled'] ?? false)) {
			expect($payload['publicTools'])->toBeArray();
			foreach ($payload['publicTools'] as $name) {
				expect($name)->toBeString();
			}
		}
	});
});
