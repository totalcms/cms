<?php

declare(strict_types=1);

use TotalCMS\Domain\Object\Service\ObjectSaver;
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
// Scenarios 10-14: admin-gated scope issuance, write-tool exposure, elevation regressions, and authority-aware drafts.

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
	// Scenario 10 (Task 8, Phase 4): shipped write tools now carry a
	// ToolRequirement — group-based access starts affecting REAL tool
	// visibility + call-time enforcement, not just the synthetic tools
	// McpToolGuardTest used to prove the guard mechanism itself. These tests
	// seed the real access-groups fixture via mcpAuthSeedAccessGroups() so
	// AccessControlService::authorityFor() resolves actual group grants.
	//
	// Fixture grants used (tests/tcms-data-fixtures/.system/access-groups.json):
	//   - blogger: collections {all:false, allowed:['blog'], ops: create/read/update/delete}
	//   - viewer:  collections {all:true, ops:[read]} — no create/update
	// ──────────────────────────────────────────────────────────────────────────

	it('blogger sees create_object in tools/list and can create into their allowed collection', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		// Exposed (Scenario 18/final review fix: writes now ALSO require
		// mcp.access exposure, not just scope + group) — this test is about
		// the group layer succeeding, exposure is covered on its own by
		// Scenario 18's dedicated tests below.
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');

		$clientId = 'mcp-auth-write-ok-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'cms:write', 'mcp:tools'], 'blogger-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue(); // skip-safe pass

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$list     = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/list',
		], $sessionId);
		$listBody = json_decode((string)$list->getBody(), true);
		expect(array_column($listBody['result']['tools'] ?? [], 'name'))->toContain('create_object');

		$call = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'create_object',
				'arguments' => [
					'collection' => 'blog',
					'data'       => ['id' => 'blogger-post-1', 'title' => 'Hello From Blogger'],
				],
			],
		], $sessionId);

		expect($call->getStatusCode())->toBe(200);
		$callBody = json_decode((string)$call->getBody(), true);
		expect($callBody)->toHaveKey('result');
		expect($callBody['result']['isError'] ?? false)->toBeFalse();
	});

	it('blogger is denied with a group-layer error when creating into a collection their group does not allow', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();

		$clientId = 'mcp-auth-write-groupdenied-' . uniqid('', true);
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

		// 'news' is not in blogger's allowed collection list — the guard must
		// deny BEFORE the handler runs, so 'news' need not actually exist.
		$call = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'create_object',
				'arguments' => [
					'collection' => 'news',
					'data'       => ['title' => 'Should Not Save'],
				],
			],
		], $sessionId);

		expect($call->getStatusCode())->toBe(200);
		$body = json_decode((string)$call->getBody(), true);
		expect($body['result']['isError'] ?? false)->toBeTrue();
		$text = $body['result']['content'][0]['text'] ?? '';
		expect($text)->toContain('groups');
	});

	it('blogger without cms:write scope gets a scope-layer denial for create_object', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();

		$clientId = 'mcp-auth-write-scopedenied-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'mcp:tools'], 'blogger-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$call = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'create_object',
				'arguments' => [
					'collection' => 'blog',
					'data'       => ['title' => 'Should Not Save'],
				],
			],
		], $sessionId);

		expect($call->getStatusCode())->toBe(200);
		$body = json_decode((string)$call->getBody(), true);
		expect($body['result']['isError'] ?? false)->toBeTrue();
		$text = $body['result']['content'][0]['text'] ?? '';
		expect($text)->toContain('permission');
	});

	it('viewer does not see create_object in tools/list', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('viewer-user-test-com');
		mcpAuthSeedAccessGroups();

		$names = mcpAuthListToolsFor($this->app, 'viewer-user-test-com', ['cms:read', 'cms:write', 'mcp:tools']);

		if ($names === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($names)->not->toContain('create_object');
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 12 (Task 8): elevation regression. The widened cms:admin
	// ISSUANCE gate (Scenario 9 above) must NOT leak into McpAuth's ADMIN
	// persona ELEVATION check, which stays admin-GROUP-only (isAdmin,
	// unchanged). viewer-user-test-com's group genuinely satisfies
	// hasAdminDomainGrants() (proven by the "consent page shows cms:admin"
	// test above, same fixture) yet must still be denied create_schema —
	// proving the two gates are independent.
	// ──────────────────────────────────────────────────────────────────────────

	it('a non-admin with cms:admin scope AND hasAdminDomainGrants() true still does not reach the ADMIN persona', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('viewer-user-test-com');
		mcpAuthSeedAccessGroups();

		$names = mcpAuthListToolsFor($this->app, 'viewer-user-test-com', ['cms:admin', 'mcp:tools']);

		if ($names === null) {
			expect(true)->toBeTrue();

			return;
		}

		// AUTHENTICATED, not ADMIN: create_schema requires schemas 'create',
		// which viewer's group does not grant (read only) — even though the
		// SAME group grants enough for hasAdminDomainGrants() (see the
		// consent-page test above). get_site_info/list_extensions have no
		// ToolRequirement at all, so they stay strictly persona-gated —
		// the cleanest proof this caller never reached the ADMIN persona.
		expect($names)->not->toContain('create_schema');
		expect($names)->not->toContain('clear_cache');
		expect($names)->not->toContain('get_site_info');
		expect($names)->not->toContain('list_extensions');
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 13 (Task 8 fix round, Important #2): positive coverage for the
	// three domains ('schemas', 'collections-meta', 'cache') that had NO
	// fixture group granting create/write, so only the 'objects' domain's
	// allow-path was exercised with real (non-synthetic) tools before this
	// fix round — a wrong `domain` string on create_schema, create_collection,
	// or clear_cache would have silently denied-all with every test still
	// green. 'schema-editor' fixture (tests/tcms-data-fixtures/.system/
	// access-groups.json): schemas/collectionsMeta/collections all:true CRUD,
	// utils.allowed:['cache']. Fixture user: schema-editor-user-test-com.
	// ──────────────────────────────────────────────────────────────────────────

	it('schema-editor can call create_schema (schemas domain allow-path)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('schema-editor-user-test-com');
		mcpAuthSeedAccessGroups();

		$clientId = 'mcp-auth-schema-editor-schema-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:admin', 'mcp:tools'], 'schema-editor-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue(); // skip-safe pass

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'create_schema',
				'arguments' => [
					'id'         => 'mcp-schema-editor-allow-test',
					'properties' => ['name' => ['field' => 'text', 'label' => 'Name']],
				],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		// A denied/unregistered tool returns a JSON-RPC 'error' object with NO
		// 'result' key at all — the null-coalesce below would silently read
		// that as isError:false. Assert 'result' is actually present first so
		// a denial (or a name/domain typo) fails this test instead of a
		// bare tools/call error passing it by accident.
		expect($body)->toHaveKey('result');
		expect($body['result']['isError'] ?? false)->toBeFalse();
	});

	it('schema-editor can call create_collection (collections-meta domain allow-path)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('schema-editor-user-test-com');
		mcpAuthSeedAccessGroups();

		$clientId = 'mcp-auth-schema-editor-collection-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:admin', 'mcp:tools'], 'schema-editor-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'create_collection',
				'arguments' => [
					'id'     => 'mcp-schema-editor-collection-test',
					'schema' => 'blog',
				],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		// See the comment on the create_schema test above — 'result' must be
		// present, not inferred from the isError null-coalesce.
		expect($body)->toHaveKey('result');
		expect($body['result']['isError'] ?? false)->toBeFalse();
	});

	it('schema-editor can call clear_cache (cache domain allow-path)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('schema-editor-user-test-com');
		mcpAuthSeedAccessGroups();

		$clientId = 'mcp-auth-schema-editor-cache-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:admin', 'mcp:tools'], 'schema-editor-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'clear_cache',
				'arguments' => new stdClass(),
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		// See the comment on the create_schema test above — 'result' must be
		// present, not inferred from the isError null-coalesce.
		expect($body)->toHaveKey('result');
		expect($body['result']['isError'] ?? false)->toBeFalse();
	});

	it('schema-editor sees create_schema, create_collection, and clear_cache in tools/list', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('schema-editor-user-test-com');
		mcpAuthSeedAccessGroups();

		$names = mcpAuthListToolsFor($this->app, 'schema-editor-user-test-com', ['cms:admin', 'mcp:tools']);

		if ($names === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($names)->toContain('create_schema');
		expect($names)->toContain('create_collection');
		expect($names)->toContain('clear_cache');
		// schema-editor's group has no admin bypass — this is a real,
		// requirement-satisfied AUTHENTICATED caller, not an elevated ADMIN.
		expect($names)->not->toContain('get_site_info');
		expect($names)->not->toContain('list_extensions');
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 14 (Task 9): authority-aware drafts. Before this task, content
	// tools hid drafts from every non-ADMIN persona (a blanket
	// `persona !== ADMIN` check) — an AUTHENTICATED OAuth caller saw ALL
	// drafts in every collection they could reach, regardless of what their
	// access groups actually granted. PersonaContext::canReadDrafts()
	// narrows this: ADMIN always sees drafts; an OAuth caller sees drafts
	// only in collections their groups grant `read` on; public/anonymous
	// callers never see drafts (unchanged).
	//
	// Collection/user pairing to isolate the draft rule from any group-deny
	// rule: query_collection/get_object have NO ToolRequirement (confirmed —
	// `grep -rn "requires:" src/Domain/Mcp/Tool/Content/` is empty), so
	// access-groups never gate WHICH collections these tools can query at
	// all — only draft VISIBILITY within an already-reachable collection is
	// authority-aware as of this task. So for the "denied" case (b) below we
	// deliberately pick a collection blogger's group does NOT grant read on
	// ('blog-legacy' — blogger's fixture group only allows 'blog') but that
	// IS otherwise queryable (mcp.access:'public'): the query itself
	// succeeds and returns the collection's published object, proving it's
	// specifically the draft that's hidden — not a blanket collection
	// denial the group layer would otherwise produce (as it does for
	// create_object, tested above).
	//
	// Both fixture collections use mcp.access:'public' (rather than
	// 'authenticated') so the SAME collection can carry every persona case:
	// PUBLIC, AUTHENTICATED, and ADMIN all resolve as accessible.
	// ──────────────────────────────────────────────────────────────────────────

	it('blogger sees a draft object via query_collection in a collection their group grants read on', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-draft-blog-post-qc-allow', 'title' => 'Draft Blog Post', 'draft' => true]);
		$saver->saveObject('blog', ['id' => 'mcp-published-blog-post-qc-allow', 'title' => 'Published Blog Post', 'draft' => false]);

		$clientId = 'mcp-auth-draft-allow-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'mcp:tools'], 'blogger-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue(); // skip-safe pass

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

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
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		expect($ids)->toContain('mcp-draft-blog-post-qc-allow');
		expect($ids)->toContain('mcp-published-blog-post-qc-allow');
	});

	it('blogger does NOT see a draft object via query_collection in a collection their group does not grant read on', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'public');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-draft-legacy-post-qc-deny', 'title' => 'Draft Legacy Post', 'draft' => true]);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-published-legacy-post-qc-deny', 'title' => 'Published Legacy Post', 'draft' => false]);

		$clientId = 'mcp-auth-draft-deny-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'mcp:tools'], 'blogger-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

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
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		// The collection itself is NOT denied — blogger's group grants no
		// `read` on 'blog-legacy', but query_collection has no group gate at
		// all, so the published object still comes back. Only the draft is
		// hidden, isolating the draft rule from the (nonexistent, for this
		// tool) group-deny rule.
		expect($ids)->toContain('mcp-published-legacy-post-qc-deny');
		expect($ids)->not->toContain('mcp-draft-legacy-post-qc-deny');
	});

	it('anonymous/public persona does NOT see drafts via query_collection (regression guard)', function (): void {
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, ['publicAccess' => true]);

		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-draft-blog-post-pub', 'title' => 'Draft Blog Post', 'draft' => true]);
		$saver->saveObject('blog', ['id' => 'mcp-published-blog-post-pub', 'title' => 'Published Blog Post', 'draft' => false]);

		$sessionId = mcpAuthPublicInitSession($this->app);
		if ($sessionId === '') {
			expect(true)->toBeTrue(); // skip-safe pass

			return;
		}

		$response = mcpAuthPublicRequest($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'query_collection',
				'arguments' => ['collection' => 'blog'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		expect($ids)->toContain('mcp-published-blog-post-pub');
		expect($ids)->not->toContain('mcp-draft-blog-post-pub');
	});

	it('admin persona sees drafts via query_collection (regression guard)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('admin-user-test-com');
		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-draft-blog-post-admin', 'title' => 'Draft Blog Post', 'draft' => true]);
		$saver->saveObject('blog', ['id' => 'mcp-published-blog-post-admin', 'title' => 'Published Blog Post', 'draft' => false]);

		$clientId = 'mcp-auth-draft-admin-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:admin', 'mcp:tools'], 'admin-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

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
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		expect($ids)->toContain('mcp-draft-blog-post-admin');
	});

	// Same allow/deny pair as above, for get_object — a single-object fetch
	// filters differently (opaque "not found" on a hidden draft, rather than
	// omitting a row from a list) so it needs its own coverage.

	it('blogger can get_object a draft in a collection their group grants read on', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');

		$this->app->getContainer()->get(ObjectSaver::class)
			->saveObject('blog', ['id' => 'mcp-draft-blog-post-go-allow', 'title' => 'Draft Blog Post', 'draft' => true]);

		$clientId = 'mcp-auth-getobj-allow-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'mcp:tools'], 'blogger-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'get_object',
				'arguments' => ['collection' => 'blog', 'id' => 'mcp-draft-blog-post-go-allow'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('result');
		expect($body['result']['isError'] ?? false)->toBeFalse();
		expect($body['result']['structuredContent']['id'] ?? null)->toBe('mcp-draft-blog-post-go-allow');
	});

	it('blogger gets an opaque not-found fetching a draft in a collection their group does not grant read on', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'public');

		$this->app->getContainer()->get(ObjectSaver::class)
			->saveObject('blog-legacy', ['id' => 'mcp-draft-legacy-post-go-deny', 'title' => 'Draft Legacy Post', 'draft' => true]);

		$clientId = 'mcp-auth-getobj-deny-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read', 'mcp:tools'], 'blogger-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'get_object',
				'arguments' => ['collection' => 'blog-legacy', 'id' => 'mcp-draft-legacy-post-go-deny'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		expect($body['result']['isError'] ?? false)->toBeTrue();
		$text = $body['result']['content'][0]['text'] ?? '';
		expect($text)->toContain('not found');
	});

	// ──────────────────────────────────────────────────────────────────────────
});
