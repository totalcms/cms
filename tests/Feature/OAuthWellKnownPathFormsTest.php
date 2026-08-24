<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;

/**
 * RFC 8414 §3.1 / RFC 9728 §3 path-suffixed well-known forms.
 *
 * For an issuer/resource with a path component, strict clients insert the
 * well-known segment between host and path rather than querying the bare
 * `/.well-known/...` root. On installs whose root catch-all rewrite already
 * forwards these URLs to T3, Slim previously 404d them because no route
 * matched the extra path segment.
 */
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
 * The authorization-server form is routed but NOT a mirror of the bare
 * document: the path in the URL *is* the issuer the client is asking about,
 * and it verifies the returned `issuer` echoes it. A base path this install
 * doesn't answer at can't be echoed — every endpoint in the document is built
 * onto the issuer, so reflecting an arbitrary path would publish endpoints
 * pointing anywhere on the host. Unknown path therefore means 404.
 */
test('path-suffixed authorization-server metadata rejects a base path this install does not serve', function (): void {
	$response = get('/.well-known/oauth-authorization-server/some/sub/path');

	expect($response->getStatusCode())->toBe(404);
});

test('the bare authorization-server form still answers', function (): void {
	$bare = get('/.well-known/oauth-authorization-server');

	// non-Pro editions 404 the whole OAuth surface — acceptable
	expect($bare->getStatusCode())->toBeIn([200, 404]);
	if ($bare->getStatusCode() === 200) {
		$doc = json_decode((string)$bare->getBody(), true);
		expect($doc['issuer'] ?? '')->not->toBe('');
		expect($doc['authorization_endpoint'] ?? '')->toStartWith((string)$doc['issuer']);
	}
});

test('path-suffixed protected-resource metadata echoes the requested resource', function (): void {
	$response = get('/.well-known/oauth-protected-resource/some/sub/path/mcp');

	if ($response->getStatusCode() === 200) {
		$doc = json_decode((string)$response->getBody(), true);
		expect($doc['resource'] ?? '')->toEndWith('/some/sub/path/mcp');
	} else {
		// non-Pro editions 404 the whole OAuth surface — acceptable
		expect($response->getStatusCode())->toBeIn([404]);
	}
});
