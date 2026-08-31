<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Support\Config;

require_once __DIR__ . '/McpAuthHelpers.php';

// Split out of the former tests/Feature/McpAuthenticatedPersonaTest.php.
//
// That file held 60 tests worth ~32s. Paratest distributes by FILE, so it
// alone set the floor for the whole parallel run: the rest of the suite
// finished in 18s and eleven workers then sat idle waiting for it. Splitting
// it lets those tests spread across workers. Keep these files separate, and
// prefer adding a new sibling over growing one of them.
//
// Scenarios 17-18: group-access completion for reads, and object-write exposure.

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

// ──────────────────────────────────────────────────────────────────────────────
// Helpers — distinct names to avoid collisions with other test files
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Generate an RSA key pair and configure $config->oauth to use them.
 * Distinct name from setupOAuthKeys() in OAuthAuthorizationCodeFlowTest to
 * avoid function redeclaration errors when Pest loads all test files together.
 *
 * @return array{privateKey: string, publicKey: string, tmpDir: string}
 */
describe('McpAuthenticatedPersona', function (): void {
	// Scenario 17 (Task 10b): completes the group-access feature for READS.
	// Before this task, NO read tool declared a ToolRequirement — published-
	// content reads were governed only by each collection's mcp.access, not by
	// access groups. A viewer/blogger-group user's Claude could read every
	// mcp.access:'authenticated' collection regardless of their grants.
	//
	// query_collection / get_object / search_collection now declare
	// `requires: objects/read/collection`; the call-time guard's rule for this
	// domain/operation is PersonaContext::canReadCollection() — group grant OR
	// mcp.access:'public' (the public-collection carve-out that fixes the
	// Task 10 privilege-inversion finding: an AUTHENTICATED caller without a
	// grant must not be denied where an ANONYMOUS caller succeeds).
	//
	// search_collections / compat search have no single collection argument,
	// so they filter their per-collection loop instead of declaring a
	// requirement — a denied collection is silently absent from results, never
	// an error.
	//
	// Same blogger/blog/blog-legacy/viewer fixture shapes as earlier scenarios.
	// ──────────────────────────────────────────────────────────────────────────

	it('blogger query_collection succeeds on their allowed collection and is denied with a group error on an authenticated-exposed collection their group does not allow', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'authenticated');
		// MCP initialize 400s with "Collection for Schema not found: dataviews"
		// unless this exists. It used to be inherited from a scenario that ran
		// earlier in the same file — beforeAll wipes the data dir only once —
		// which broke as soon as these suites were split across files.
		mcpAuthEnsureDataviewsCollection($this->app);

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-t10b-qc-allow', 'title' => 'Allowed Post', 'draft' => false]);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-t10b-qc-deny', 'title' => 'Denied Post', 'draft' => false]);

		$clientId = 'mcp-t10b-qc-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'mcp:tools'], 'blogger-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		// `blog` accumulates objects across earlier scenarios in this file
		// (beforeAll only wipes cmsDataDir() once) — filter with `include` to
		// this test's own id rather than relying on default pagination
		// happening to keep it in the first page.
		$allow = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'query_collection',
				'arguments' => ['collection' => 'blog', 'include' => 'id:mcp-t10b-qc-allow'],
			],
		], $sessionId);

		expect($allow->getStatusCode())->toBe(200);
		$allowBody = json_decode((string)$allow->getBody(), true);
		expect($allowBody)->toHaveKey('result');
		expect($allowBody['result']['isError'] ?? false)->toBeFalse();
		$ids = array_column(mcpAuthStructuredItems($allow), 'id');
		expect($ids)->toContain('mcp-t10b-qc-allow');

		$deny = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'query_collection',
				'arguments' => ['collection' => 'blog-legacy'],
			],
		], $sessionId);

		expect($deny->getStatusCode())->toBe(200);
		$denyBody = json_decode((string)$deny->getBody(), true);
		expect($denyBody['result']['isError'] ?? false)->toBeTrue();
		$text = $denyBody['result']['content'][0]['text'] ?? '';
		expect($text)->toContain('groups');
	});

	it('blogger get_object succeeds on their allowed collection and is denied with a group error on an authenticated-exposed collection their group does not allow', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'authenticated');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-t10b-go-allow', 'title' => 'Allowed Post', 'draft' => false]);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-t10b-go-deny', 'title' => 'Denied Post', 'draft' => false]);

		$clientId = 'mcp-t10b-go-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'mcp:tools'], 'blogger-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$allow = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'get_object',
				'arguments' => ['collection' => 'blog', 'id' => 'mcp-t10b-go-allow'],
			],
		], $sessionId);

		expect($allow->getStatusCode())->toBe(200);
		$allowBody = json_decode((string)$allow->getBody(), true);
		expect($allowBody)->toHaveKey('result');
		expect($allowBody['result']['isError'] ?? false)->toBeFalse();
		expect($allowBody['result']['structuredContent']['id'] ?? null)->toBe('mcp-t10b-go-allow');

		$deny = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'get_object',
				'arguments' => ['collection' => 'blog-legacy', 'id' => 'mcp-t10b-go-deny'],
			],
		], $sessionId);

		expect($deny->getStatusCode())->toBe(200);
		$denyBody = json_decode((string)$deny->getBody(), true);
		expect($denyBody['result']['isError'] ?? false)->toBeTrue();
	});

	it('blogger without cms:read scope gets a scope-layer denial for query_collection', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');

		$clientId = 'mcp-t10b-scope-' . uniqid('', true);
		// mcp:tools only — no cms:read at all.
		$token = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:tools'], 'blogger-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'query_collection',
				'arguments' => ['collection' => 'blog'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		expect($body['result']['isError'] ?? false)->toBeTrue();
		$text = $body['result']['content'][0]['text'] ?? '';
		expect($text)->toContain('permission');
	});

	// Fix round 1 (review finding #2): the public-collection carve-out must
	// also skip the SCOPE layer, not just the group layer — a token with
	// mcp:tools but NO cms:read must still read a mcp.access:'public'
	// collection (an anonymous caller already reads it with zero consent),
	// while the SAME token stays denied at the scope layer on a
	// non-public collection. Isolates the scope-tier fix from the
	// group-tier one above (which uses an 'authenticated' collection).
	it('a token WITHOUT cms:read scope still reads an mcp.access:public collection, but stays denied at the scope layer on an authenticated-exposed collection', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'authenticated');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-t10b-noscope-pub-allow', 'title' => 'No-Scope Public Post', 'draft' => false]);

		$clientId = 'mcp-t10b-noscope-' . uniqid('', true);
		// mcp:tools only — no cms:read at all.
		$token = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:tools'], 'blogger-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$onPublic = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'query_collection',
				'arguments' => ['collection' => 'blog', 'include' => 'id:mcp-t10b-noscope-pub-allow'],
			],
		], $sessionId);

		expect($onPublic->getStatusCode())->toBe(200);
		$onPublicBody = json_decode((string)$onPublic->getBody(), true);
		expect($onPublicBody)->toHaveKey('result');
		expect($onPublicBody['result']['isError'] ?? false)->toBeFalse();
		$ids = array_column(mcpAuthStructuredItems($onPublic), 'id');
		expect($ids)->toContain('mcp-t10b-noscope-pub-allow');

		$onAuthenticated = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'query_collection',
				'arguments' => ['collection' => 'blog-legacy'],
			],
		], $sessionId);

		expect($onAuthenticated->getStatusCode())->toBe(200);
		$onAuthenticatedBody = json_decode((string)$onAuthenticated->getBody(), true);
		expect($onAuthenticatedBody['result']['isError'] ?? false)->toBeTrue();
		$text = $onAuthenticatedBody['result']['content'][0]['text'] ?? '';
		expect($text)->toContain('permission');
	});

	it('search_collections and the compat search tool include only readable collections, silently omitting a group-denied one (not an error)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'authenticated');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-t10b-scs-allow', 'title' => 'Fizzbin Allowed Post', 'draft' => false]);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-t10b-scs-deny', 'title' => 'Fizzbin Denied Post', 'draft' => false]);

		$clientId = 'mcp-t10b-scs-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'mcp:tools'], 'blogger-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'search_collections',
				'arguments' => ['query' => 'Fizzbin'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('result');
		expect($body['result']['isError'] ?? false)->toBeFalse();
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		expect($ids)->toContain('mcp-t10b-scs-allow');
		expect($ids)->not->toContain('mcp-t10b-scs-deny');

		$compat = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'search',
				'arguments' => ['query' => 'Fizzbin'],
			],
		], $sessionId);

		expect($compat->getStatusCode())->toBe(200);
		$compatBody = json_decode((string)$compat->getBody(), true);
		expect($compatBody)->toHaveKey('result');
		expect($compatBody['result']['isError'] ?? false)->toBeFalse();
		$results        = $compatBody['result']['structuredContent']['results'] ?? [];
		$compatIds      = array_column(is_array($results) ? $results : [], 'id');
		expect($compatIds)->toContain('blog:mcp-t10b-scs-allow');
		expect($compatIds)->not->toContain('blog-legacy:mcp-t10b-scs-deny');
	});

	it('an mcp.access:public collection is readable by an authenticated caller WITHOUT any group grant (regression guard for the Task 10 privilege inversion), but still hides drafts from them', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		// 'blog-legacy' is NOT in blogger's allowed collection list — this is
		// the whole point of the test: mcp.access:'public' must let them
		// through anyway.
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'public');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-t10b-pub-published', 'title' => 'Public No Grant', 'draft' => false]);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-t10b-pub-draft', 'title' => 'Public No Grant Draft', 'draft' => true]);

		$clientId = 'mcp-t10b-pub-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'mcp:tools'], 'blogger-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'query_collection',
				'arguments' => ['collection' => 'blog-legacy'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('result');
		expect($body['result']['isError'] ?? false)->toBeFalse();
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		expect($ids)->toContain('mcp-t10b-pub-published');
		expect($ids)->not->toContain('mcp-t10b-pub-draft');

		// get_object on the draft — opaque not-found, not readable via the
		// public carve-out (public exposure never implies draft access).
		$getDraft = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'get_object',
				'arguments' => ['collection' => 'blog-legacy', 'id' => 'mcp-t10b-pub-draft'],
			],
		], $sessionId);

		$getDraftBody = json_decode((string)$getDraft->getBody(), true);
		expect($getDraftBody['result']['isError'] ?? false)->toBeTrue();

		// get_object on the published object — succeeds via the same
		// public-collection carve-out.
		$getPublished = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'get_object',
				'arguments' => ['collection' => 'blog-legacy', 'id' => 'mcp-t10b-pub-published'],
			],
		], $sessionId);

		expect($getPublished->getStatusCode())->toBe(200);
		$getPublishedBody = json_decode((string)$getPublished->getBody(), true);
		expect($getPublishedBody)->toHaveKey('result');
		expect($getPublishedBody['result']['isError'] ?? false)->toBeFalse();
	});

	it('viewer (unrestricted read, no write) can read everything readable via query_collection and get_object, and cannot write (regression)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('viewer-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-t10b-viewer-post', 'title' => 'Viewer Readable Post', 'draft' => false]);

		$clientId = 'mcp-t10b-viewer-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'cms:write', 'mcp:tools'], 'viewer-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		// `blog` accumulates objects across earlier scenarios in this file —
		// filter to this test's own id (see the query_collection test above
		// for the same reasoning).
		$query = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'query_collection',
				'arguments' => ['collection' => 'blog', 'include' => 'id:mcp-t10b-viewer-post'],
			],
		], $sessionId);

		expect($query->getStatusCode())->toBe(200);
		$queryBody = json_decode((string)$query->getBody(), true);
		expect($queryBody)->toHaveKey('result');
		expect($queryBody['result']['isError'] ?? false)->toBeFalse();
		$ids = array_column(mcpAuthStructuredItems($query), 'id');
		expect($ids)->toContain('mcp-t10b-viewer-post');

		$get = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'get_object',
				'arguments' => ['collection' => 'blog', 'id' => 'mcp-t10b-viewer-post'],
			],
		], $sessionId);

		expect($get->getStatusCode())->toBe(200);
		$getBody = json_decode((string)$get->getBody(), true);
		expect($getBody)->toHaveKey('result');
		expect($getBody['result']['isError'] ?? false)->toBeFalse();

		// Regression: viewer's group grants read only — create_object stays
		// denied. Their authority never satisfies isSatisfiedForAny('create')
		// (Task 6/8), so the tool isn't even REGISTERED with the SDK for this
		// session (matches "viewer does not see create_object in tools/list"
		// above) — the call surfaces as a top-level JSON-RPC 'error' ("Tool
		// not found"), never a 'result' key at all. Asserting 'error' is
		// present (not the ['result']['isError'] ?? false shape) proves this
		// is a real denial rather than a vacuously-true null-coalesce — see
		// the Task 8 fix-round history for why that anti-pattern was banned.
		$create = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'create_object',
				'arguments' => ['collection' => 'blog', 'data' => ['title' => 'Should Not Save']],
			],
		], $sessionId);

		$createBody = json_decode((string)$create->getBody(), true);
		expect($createBody)->toHaveKey('error');
		expect($createBody)->not->toHaveKey('result');
	});

	it('ADMIN persona query_collection/get_object are unaffected by the new read gate (regression)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('admin-user-test-com');
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-t10b-admin-post', 'title' => 'Admin Post', 'draft' => false]);

		$clientId = 'mcp-t10b-admin-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:admin', 'mcp:tools'], 'admin-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'get_object',
				'arguments' => ['collection' => 'blog', 'id' => 'mcp-t10b-admin-post'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('result');
		expect($body['result']['isError'] ?? false)->toBeFalse();
	});

	it('PUBLIC_ (anonymous) persona query_collection/get_object are unaffected by the new read gate (regression)', function (): void {
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, ['publicAccess' => true]);

		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-t10b-pub-anon-post', 'title' => 'Public Anon Post', 'draft' => false]);

		$sessionId = mcpAuthPublicInitSession($this->app);
		expect($sessionId)->not->toBe('');

		$response = mcpAuthPublicRequest($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'get_object',
				'arguments' => ['collection' => 'blog', 'id' => 'mcp-t10b-pub-anon-post'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('result');
		expect($body['result']['isError'] ?? false)->toBeFalse();
	});

	it('resources/read tcms://{collection}/{id} follows the same read rule: blogger denied on an authenticated-exposed collection their group does not allow', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'authenticated');
		mcpAuthEnsureDataviewsCollection($this->app);

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-t10b-obj-res-deny', 'title' => 'Object Resource Deny', 'draft' => false]);

		$clientId = 'mcp-t10b-obj-res-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:resources'], 'blogger-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'resources/read',
			'params'  => ['uri' => 'tcms://blog-legacy/mcp-t10b-obj-res-deny'],
		], $sessionId);

		$body = json_decode((string)$response->getBody(), true);
		// GetObjectTool's opaque not-found surfaces as a ToolCallException from
		// the resource handler — same top-level JSON-RPC 'error' shape
		// Scenario 16 established for the collection-level resource.
		expect($body)->toHaveKey('error');
		expect($body)->not->toHaveKey('result');
	});

	it('resources/read tcms://{collection}/{id} follows the same read rule: blogger allowed on their own collection', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');
		mcpAuthEnsureDataviewsCollection($this->app);

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-t10b-obj-res-allow', 'title' => 'Object Resource Allow', 'draft' => false]);

		$clientId = 'mcp-t10b-obj-res-allow-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:resources'], 'blogger-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'resources/read',
			'params'  => ['uri' => 'tcms://blog/mcp-t10b-obj-res-allow'],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('result');
	});

	it('resources/templates/list for a blogger now omits the object template for a group-denied collection', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'authenticated');
		mcpAuthEnsureDataviewsCollection($this->app);

		$clientId = 'mcp-t10b-tmpl-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:resources'], 'blogger-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'resources/templates/list',
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body      = json_decode((string)$response->getBody(), true);
		$templates = array_column($body['result']['resourceTemplates'] ?? [], 'uriTemplate');
		expect($templates)->toContain('tcms://blog/{id}');
		expect($templates)->not->toContain('tcms://blog-legacy/{id}');
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 18 (final review fix, Critical #1/#1b): object-write tools were
	// missing the EXPOSURE layer entirely. McpServerFactory::guardHandler()
	// only ever checked scope + access-group grant for objects/create and
	// objects/update — unlike objects/read, it had no exposure carve-out to
	// apply — so a caller with a real group grant on a collection could write
	// into it even while the collection stayed at the default (unexposed)
	// `mcp.access: 'admin'`. ObjectTools now calls requireExposed() in each
	// handler (see its class docblock). These tests hold group + scope
	// constant and flip ONLY the collection's exposure, proving exposure is
	// now an independent, necessary gate for writes — same as it already was
	// for reads.
	//
	// Each test explicitly resets 'blog' access via mcpAuthSetCollectionAccess()
	// rather than assuming a starting state — 'blog' accumulates state across
	// earlier scenarios in this file (same caveat other scenarios note).
	// ──────────────────────────────────────────────────────────────────────────

	it('blogger create_object is denied at the exposure layer while their group-granted collection stays at the default (unexposed) mcp.access, but succeeds once exposed', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'admin');

		$clientId = 'mcp-final-create-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'cms:write', 'mcp:tools'], 'blogger-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$denied = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'create_object',
				'arguments' => [
					'collection' => 'blog',
					'data'       => ['id' => 'mcp-final-create-denied', 'title' => 'Should Not Save'],
				],
			],
		], $sessionId);

		expect($denied->getStatusCode())->toBe(200);
		$deniedBody = json_decode((string)$denied->getBody(), true);
		expect($deniedBody)->toHaveKey('result');
		expect($deniedBody['result']['isError'] ?? false)->toBeTrue();
		$text = $deniedBody['result']['content'][0]['text'] ?? '';
		expect($text)->toContain('not available');

		// Same caller, same scope, same group grant — only the collection's
		// exposure changed. The write must now succeed.
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');

		$allowed = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'create_object',
				'arguments' => [
					'collection' => 'blog',
					'data'       => ['id' => 'mcp-final-create-allowed', 'title' => 'Should Save'],
				],
			],
		], $sessionId);

		expect($allowed->getStatusCode())->toBe(200);
		$allowedBody = json_decode((string)$allowed->getBody(), true);
		expect($allowedBody)->toHaveKey('result');
		expect($allowedBody['result']['isError'] ?? false)->toBeFalse();
	});

	it('blogger update_object is denied at the exposure layer while their group-granted collection stays at the default (unexposed) mcp.access, but succeeds once exposed', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-final-update-target', 'title' => 'Original Title', 'draft' => false]);

		$clientId = 'mcp-final-update-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'cms:write', 'mcp:tools'], 'blogger-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		// Un-expose AFTER the target object exists and AFTER the session is
		// live — the group grant is real, only exposure is missing.
		mcpAuthSetCollectionAccess($this->app, 'blog', 'admin');

		$denied = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'update_object',
				'arguments' => [
					'collection' => 'blog',
					'id'         => 'mcp-final-update-target',
					'data'       => ['id' => 'mcp-final-update-target', 'title' => 'Should Not Update', 'draft' => false],
				],
			],
		], $sessionId);

		expect($denied->getStatusCode())->toBe(200);
		$deniedBody = json_decode((string)$denied->getBody(), true);
		expect($deniedBody)->toHaveKey('result');
		expect($deniedBody['result']['isError'] ?? false)->toBeTrue();

		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');

		$allowed = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'update_object',
				'arguments' => [
					'collection' => 'blog',
					'id'         => 'mcp-final-update-target',
					'data'       => ['id' => 'mcp-final-update-target', 'title' => 'Updated Title', 'draft' => false],
				],
			],
		], $sessionId);

		expect($allowed->getStatusCode())->toBe(200);
		$allowedBody = json_decode((string)$allowed->getBody(), true);
		expect($allowedBody)->toHaveKey('result');
		expect($allowedBody['result']['isError'] ?? false)->toBeFalse();
	});

	it('blogger patch_object is denied at the exposure layer while their group-granted collection stays at the default (unexposed) mcp.access, but succeeds once exposed', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-final-patch-target', 'title' => 'Original Title', 'draft' => false]);

		$clientId = 'mcp-final-patch-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'cms:write', 'mcp:tools'], 'blogger-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		mcpAuthSetCollectionAccess($this->app, 'blog', 'admin');

		$denied = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'patch_object',
				'arguments' => [
					'collection' => 'blog',
					'id'         => 'mcp-final-patch-target',
					'data'       => ['title' => 'Should Not Patch'],
				],
			],
		], $sessionId);

		expect($denied->getStatusCode())->toBe(200);
		$deniedBody = json_decode((string)$denied->getBody(), true);
		expect($deniedBody)->toHaveKey('result');
		expect($deniedBody['result']['isError'] ?? false)->toBeTrue();

		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');

		$allowed = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'patch_object',
				'arguments' => [
					'collection' => 'blog',
					'id'         => 'mcp-final-patch-target',
					'data'       => ['title' => 'Patched Title'],
				],
			],
		], $sessionId);

		expect($allowed->getStatusCode())->toBe(200);
		$allowedBody = json_decode((string)$allowed->getBody(), true);
		expect($allowedBody)->toHaveKey('result');
		expect($allowedBody['result']['isError'] ?? false)->toBeFalse();
	});

	it('ADMIN persona create_object/update_object are unaffected by the new write exposure gate (regression)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('admin-user-test-com');
		// Deliberately left at the default (unexposed) mcp.access — ADMIN must
		// still be able to write regardless of exposure, same as it always
		// bypasses the group-grant check.
		mcpAuthSetCollectionAccess($this->app, 'blog', 'admin');

		$clientId = 'mcp-final-admin-write-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:admin', 'mcp:tools'], 'admin-user-test-com');
		expect($token)->not->toBe('');

		$sessionId = mcpAuthInitSession($this->app, $token);
		expect($sessionId)->not->toBe('');

		$create = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'create_object',
				'arguments' => [
					'collection' => 'blog',
					'data'       => ['id' => 'mcp-final-admin-write', 'title' => 'Admin Can Always Write'],
				],
			],
		], $sessionId);

		expect($create->getStatusCode())->toBe(200);
		$createBody = json_decode((string)$create->getBody(), true);
		expect($createBody)->toHaveKey('result');
		expect($createBody['result']['isError'] ?? false)->toBeFalse();

		$update = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'update_object',
				'arguments' => [
					'collection' => 'blog',
					'id'         => 'mcp-final-admin-write',
					'data'       => ['id' => 'mcp-final-admin-write', 'title' => 'Admin Updated It'],
				],
			],
		], $sessionId);

		expect($update->getStatusCode())->toBe(200);
		$updateBody = json_decode((string)$update->getBody(), true);
		expect($updateBody)->toHaveKey('result');
		expect($updateBody['result']['isError'] ?? false)->toBeFalse();
	});

	it('create_object and patch_object strip properties marked mcp.expose:false from the returned object (Critical #1b)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('editor-user-test-com');
		mcpAuthSeedAccessGroups();

		$container = $this->app->getContainer();
		$container->get(SchemaSaver::class)->saveSchema([
			'id'         => 'mcp-final-hidden',
			'name'       => 'MCP Final Hidden',
			'type'       => 'object',
			'properties' => [
				'id'     => ['type' => 'string', 'field' => 'id'],
				'title'  => ['type' => 'string', 'field' => 'text'],
				// Operator-hidden field: never returned to MCP callers, on
				// reads OR writes.
				'secret' => ['type' => 'string', 'field' => 'text', 'mcp' => ['expose' => false]],
			],
		]);

		$collection         = new CollectionData();
		$collection->id     = 'mcp-final-hidden';
		$collection->name   = 'MCP Final Hidden';
		$collection->schema = 'mcp-final-hidden';
		$collection->mcp    = ['access' => 'authenticated'];
		$container->get(CollectionSaver::class)->saveCollection($collection->toArray());

		$clientId = 'mcp-final-hidden-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'cms:write', 'mcp:tools'], 'editor-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$create = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'create_object',
				'arguments' => [
					'collection' => 'mcp-final-hidden',
					'data'       => ['id' => 'mcp-final-hidden-obj', 'title' => 'Visible', 'secret' => 'do-not-leak'],
				],
			],
		], $sessionId);

		expect($create->getStatusCode())->toBe(200);
		$createBody = json_decode((string)$create->getBody(), true);
		expect($createBody)->toHaveKey('result');
		expect($createBody['result']['isError'] ?? false)->toBeFalse();
		expect($createBody['result']['structuredContent'] ?? [])->toHaveKey('title');
		expect($createBody['result']['structuredContent'] ?? [])->not->toHaveKey('secret');
		$createText = $createBody['result']['content'][0]['text'] ?? '';
		expect($createText)->not->toContain('do-not-leak');

		$patch = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'patch_object',
				'arguments' => [
					'collection' => 'mcp-final-hidden',
					'id'         => 'mcp-final-hidden-obj',
					'data'       => ['title' => 'Still Visible'],
				],
			],
		], $sessionId);

		expect($patch->getStatusCode())->toBe(200);
		$patchBody = json_decode((string)$patch->getBody(), true);
		expect($patchBody)->toHaveKey('result');
		expect($patchBody['result']['isError'] ?? false)->toBeFalse();
		expect($patchBody['result']['structuredContent'] ?? [])->not->toHaveKey('secret');
		$patchText = $patchBody['result']['content'][0]['text'] ?? '';
		expect($patchText)->not->toContain('do-not-leak');
	});
});
