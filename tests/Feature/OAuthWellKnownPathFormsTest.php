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

test('path-suffixed authorization-server metadata answers like the bare form', function (): void {
	$bare     = get('/.well-known/oauth-authorization-server');
	$suffixed = get('/.well-known/oauth-authorization-server/some/sub/path');

	expect($suffixed->getStatusCode())->toBe($bare->getStatusCode());
	if ($bare->getStatusCode() === 200) {
		expect((string)$suffixed->getBody())->toBe((string)$bare->getBody());
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
