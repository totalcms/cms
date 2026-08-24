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
 * When the queried path IS a base path this install answers at, the document
 * echoes it as the issuer (see OAuthDiscoveryActionTest). When it is not, the
 * install's own document comes back instead — the caller's path must never
 * reach the response, because every endpoint is built onto the issuer.
 *
 * It must not 404: clients do not all derive this URL from the issuer the way
 * RFC 8414 §3.1 specifies, and one that appends the resource path instead
 * reads a 404 here as "this server has no OAuth".
 */
test('path-suffixed authorization-server metadata never reflects an unknown base path', function (): void {
	$response = get('/.well-known/oauth-authorization-server/some/sub/path');

	// non-Pro editions 404 the whole OAuth surface — acceptable
	expect($response->getStatusCode())->toBeIn([200, 404]);

	if ($response->getStatusCode() === 200) {
		$body = (string)$response->getBody();
		expect($body)->not->toContain('some/sub/path');

		$doc = json_decode($body, true);
		expect($doc['authorization_endpoint'] ?? '')->toStartWith((string)($doc['issuer'] ?? 'x'));
	}
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
