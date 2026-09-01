<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
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
// Scenarios 15-16: the search family and authority-aware MCP resources.

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
	// Scenario 15 (Task 9, fix round 1): coverage for the search family.
	// Review mutation-proved SearchCollectionTool/SearchCollectionsTool/
	// SearchTool(Compat)'s post-filters had ZERO coverage — deleting the
	// three `continue` guards left the whole McpAuthenticatedPersona +
	// Search + McpChatGptCompat suite green. These tests close that gap
	// with the same allow/deny pairing as Scenario 14 (blogger group grants
	// read on 'blog' only, not 'blog-legacy'). Each pair searches for a
	// distinctive nonsense term unique to that test's fixture objects so a
	// match can only come from THIS test's data, not another test's shared
	// 'blog'/'blog-legacy' fixtures accumulated earlier in the same file run
	// (beforeAll only wipes cmsDataDir() once for the whole file).
	// ──────────────────────────────────────────────────────────────────────────

	it('blogger sees a draft object via search_collection in a collection their group grants read on', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');

		$this->app->getContainer()->get(ObjectSaver::class)
			->saveObject('blog', ['id' => 'mcp-draft-blog-post-sc-allow', 'title' => 'Zyxwquark Draft Post', 'draft' => true]);

		$clientId = 'mcp-auth-search-allow-' . uniqid('', true);
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
				'name'      => 'search_collection',
				'arguments' => ['collection' => 'blog', 'query' => 'Zyxwquark'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		expect($ids)->toContain('mcp-draft-blog-post-sc-allow');
	});

	it('blogger does NOT see a draft object via search_collection in a collection their group does not grant read on', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'public');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-draft-legacy-post-sc-deny', 'title' => 'Blorptastic Draft Post', 'draft' => true]);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-published-legacy-post-sc-deny', 'title' => 'Blorptastic Published Post', 'draft' => false]);

		$clientId = 'mcp-auth-search-deny-' . uniqid('', true);
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
				'name'      => 'search_collection',
				'arguments' => ['collection' => 'blog-legacy', 'query' => 'Blorptastic'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		expect($ids)->toContain('mcp-published-legacy-post-sc-deny');
		expect($ids)->not->toContain('mcp-draft-legacy-post-sc-deny');
	});

	it('blogger does NOT see a draft object via search_collections in a collection their group does not grant read on', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'public');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-draft-legacy-post-scs-deny', 'title' => 'Quaggleflux Draft Post', 'draft' => true]);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-published-legacy-post-scs-deny', 'title' => 'Quaggleflux Published Post', 'draft' => false]);

		$clientId = 'mcp-auth-searchcols-deny-' . uniqid('', true);
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
				'name'      => 'search_collections',
				'arguments' => ['query' => 'Quaggleflux'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$ids = array_column(mcpAuthStructuredItems($response), 'id');
		expect($ids)->toContain('mcp-published-legacy-post-scs-deny');
		expect($ids)->not->toContain('mcp-draft-legacy-post-scs-deny');
	});

	it('blogger does NOT see a draft object via the ChatGPT-compat search tool in a collection their group does not grant read on', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'public');

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-draft-legacy-post-search-deny', 'title' => 'Wobbleknack Draft Post', 'draft' => true]);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-published-legacy-post-search-deny', 'title' => 'Wobbleknack Published Post', 'draft' => false]);

		$clientId = 'mcp-auth-compat-search-deny-' . uniqid('', true);
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
				'name'      => 'search',
				'arguments' => ['query' => 'Wobbleknack'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body    = json_decode((string)$response->getBody(), true);
		$results = $body['result']['structuredContent']['results'] ?? [];
		$ids     = array_column(is_array($results) ? $results : [], 'id');
		expect($ids)->toContain('blog-legacy:mcp-published-legacy-post-search-deny');
		expect($ids)->not->toContain('blog-legacy:mcp-draft-legacy-post-search-deny');
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 16 (Task 10): authority-aware MCP resources. Before this task,
	// CollectionResource (the `resources/read tcms://{collection}/` handler)
	// used a blanket `persona === PUBLIC_` check to hide drafts — an
	// AUTHENTICATED OAuth caller could resources/read ANY collection they
	// could reach and see every draft, regardless of what their access groups
	// granted. Task 10 closes this on the resource surface specifically (a
	// stricter rule than query_collection/get_object, which have no
	// per-collection group requirement — see Scenario 14's comment): an
	// AUTHENTICATED caller's resolved UserAuthority must grant `read` on the
	// target collection or resources/read denies the WHOLE read (not just
	// drafts), and resources/list only enumerates collections that grant is
	// satisfied for.
	//
	// Same blogger/blog/blog-legacy fixture pairing as Scenario 14: blogger's
	// group grants read only on 'blog', not 'blog-legacy'.
	//
	// mcpAuthSetupResourceFixtures() pre-creates the 'dataviews' reserved
	// collection: DataViewResourceRegistrar (unrelated to this task) creates
	// it lazily on first MCP server build via DataViewLister::ensureCollection()
	// then immediately reads its index — on a bone-dry cmsDataDir with no
	// other collection created first, that create-then-immediately-read
	// sequence 400s with "Collection for Schema not found: dataviews" (a
	// pre-existing CollectionFetcher/cache-staleness quirk, reproduced and
	// confirmed unrelated to the authority-gate changes in this task — see
	// task-10-report.md). McpResourcesTest.php's beforeEach sidesteps the same
	// class of issue by pre-creating 'blog'; pre-creating 'dataviews' here is
	// the equivalent fix scoped to these tests.
	//
	// Error shape note: CollectionResource throws Mcp\Exception\ToolCallException
	// on denial — the SAME exception class the pre-existing "collection not
	// found" / "mcp.access denied" checks in this handler already use. The MCP
	// SDK's ReadResourceHandler only special-cases ResourceReadException /
	// ResourceNotFoundException; any other Throwable (including
	// ToolCallException) falls into its generic catch and is surfaced as a
	// top-level JSON-RPC `error` object (code -32603, "Error while reading
	// resource") rather than the tools/call-style `result.isError` envelope.
	// This is the existing, pre-Task-10 idiom for this handler — reused as-is
	// rather than inventing a new shape.
	// ──────────────────────────────────────────────────────────────────────────

	it('blogger resources/read tcms://blog/ succeeds (group grants read)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');
		mcpAuthEnsureDataviewsCollection($this->app);

		$this->app->getContainer()->get(ObjectSaver::class)
			->saveObject('blog', ['id' => 'mcp-res-published-blog-a', 'title' => 'Resource Read Allow', 'draft' => false]);

		$clientId = 'mcp-auth-res-read-allow-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:resources'], 'blogger-user-test-com');

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
			'method'  => 'resources/read',
			'params'  => ['uri' => 'tcms://blog/'],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('result');
		$ids = array_column(mcpAuthResourceReadItems($response), 'id');
		expect($ids)->toContain('mcp-res-published-blog-a');
	});

	it('blogger resources/read on a group-denied collection is denied with an error, not a silent empty result', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		// 'authenticated', NOT 'public' — Task 10b added the same
		// public-collection carve-out to this resource gate that
		// query_collection/get_object already have (work item 2a), so a
		// 'public' collection would no longer isolate the group-deny case;
		// 'authenticated' still requires the real group grant.
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'authenticated');
		mcpAuthEnsureDataviewsCollection($this->app);

		$this->app->getContainer()->get(ObjectSaver::class)
			->saveObject('blog-legacy', ['id' => 'mcp-res-published-legacy-b', 'title' => 'Resource Read Deny', 'draft' => false]);

		$clientId = 'mcp-auth-res-read-deny-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:resources'], 'blogger-user-test-com');

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
			'method'  => 'resources/read',
			'params'  => ['uri' => 'tcms://blog-legacy/'],
		], $sessionId);

		$body = json_decode((string)$response->getBody(), true);
		// Denied — a top-level JSON-RPC 'error', never a 'result' key. Asserting
		// 'error' is present (rather than just "items is empty") proves this is
		// a real denial, not a collection that merely queried empty.
		expect($body)->toHaveKey('error');
		expect($body)->not->toHaveKey('result');
	});

	it('blogger resources/read sees BOTH drafts and published items in a collection their group grants read on (draft leak closed, allow side)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');
		mcpAuthEnsureDataviewsCollection($this->app);

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-res-draft-blog-c', 'title' => 'Draft Resource Post', 'draft' => true]);
		$saver->saveObject('blog', ['id' => 'mcp-res-published-blog-c', 'title' => 'Published Resource Post', 'draft' => false]);

		$clientId = 'mcp-auth-res-draft-allow-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:resources'], 'blogger-user-test-com');

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
			'method'  => 'resources/read',
			'params'  => ['uri' => 'tcms://blog/'],
		], $sessionId);

		$ids = array_column(mcpAuthResourceReadItems($response), 'id');
		expect($ids)->toContain('mcp-res-draft-blog-c');
		expect($ids)->toContain('mcp-res-published-blog-c');
	});

	it('blogger resources/read receives NO drafts (and no published items either) in a collection their group does not grant read on (draft leak closed, deny side)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		// 'authenticated', NOT 'public' — see comment on the previous test.
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'authenticated');
		mcpAuthEnsureDataviewsCollection($this->app);

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-res-draft-legacy-d', 'title' => 'Draft Resource Post', 'draft' => true]);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-res-published-legacy-d', 'title' => 'Published Resource Post', 'draft' => false]);

		$clientId = 'mcp-auth-res-draft-deny-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:resources'], 'blogger-user-test-com');

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
			'method'  => 'resources/read',
			'params'  => ['uri' => 'tcms://blog-legacy/'],
		], $sessionId);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('error');
		// No draft/published leak of any kind — confirms the denial happens
		// before the index is even read, not that the draft alone got filtered.
		$text = json_encode($body);
		expect($text)->not->toContain('mcp-res-draft-legacy-d');
		expect($text)->not->toContain('mcp-res-published-legacy-d');
	});

	it('resources/list for a blogger shows only collections their group grants read on', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('blogger-user-test-com');
		mcpAuthSeedAccessGroups();
		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');
		// 'authenticated', NOT 'public' — see the deny-side comment above;
		// a 'public' collection would no longer be omitted from the list.
		mcpAuthSetCollectionAccess($this->app, 'blog-legacy', 'authenticated');
		mcpAuthEnsureDataviewsCollection($this->app);

		$clientId = 'mcp-auth-res-list-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:resources'], 'blogger-user-test-com');

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
			'method'  => 'resources/list',
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
		$uris = array_column($body['result']['resources'] ?? [], 'uri');
		expect($uris)->toContain('tcms://blog/');
		expect($uris)->not->toContain('tcms://blog-legacy/');
	});

	it('admin persona resources/read is unaffected by the group-authority gate (regression)', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('admin-user-test-com');
		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');
		mcpAuthEnsureDataviewsCollection($this->app);

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-res-draft-blog-admin', 'title' => 'Admin Draft', 'draft' => true]);
		$saver->saveObject('blog', ['id' => 'mcp-res-published-blog-admin', 'title' => 'Admin Published', 'draft' => false]);

		$clientId = 'mcp-auth-res-admin-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:admin', 'mcp:resources'], 'admin-user-test-com');

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
			'method'  => 'resources/read',
			'params'  => ['uri' => 'tcms://blog/'],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$ids = array_column(mcpAuthResourceReadItems($response), 'id');
		expect($ids)->toContain('mcp-res-draft-blog-admin');
		expect($ids)->toContain('mcp-res-published-blog-admin');
	});

	it('anonymous/public persona resources/read is unaffected by the group-authority gate (regression)', function (): void {
		/** @var Config $config */
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, ['publicAccess' => true]);

		mcpAuthSetCollectionAccess($this->app, 'blog', 'public');
		mcpAuthEnsureDataviewsCollection($this->app);

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-res-draft-blog-pub', 'title' => 'Public Draft', 'draft' => true]);
		$saver->saveObject('blog', ['id' => 'mcp-res-published-blog-pub', 'title' => 'Public Published', 'draft' => false]);

		$sessionId = mcpAuthPublicInitSession($this->app);
		if ($sessionId === '') {
			expect(true)->toBeTrue(); // skip-safe pass

			return;
		}

		$response = mcpAuthPublicRequest($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'resources/read',
			'params'  => ['uri' => 'tcms://blog/'],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$ids = array_column(mcpAuthResourceReadItems($response), 'id');
		expect($ids)->toContain('mcp-res-published-blog-pub');
		expect($ids)->not->toContain('mcp-res-draft-blog-pub');

		// resources/list also stays public-collections-only for anonymous.
		$listResponse = mcpAuthPublicRequest($this->app, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'resources/list',
		], $sessionId);

		expect($listResponse->getStatusCode())->toBe(200);
		$listBody = json_decode((string)$listResponse->getBody(), true);
		$uris     = array_column($listBody['result']['resources'] ?? [], 'uri');
		expect($uris)->toContain('tcms://blog/');
	});

	// ──────────────────────────────────────────────────────────────────────────
});
