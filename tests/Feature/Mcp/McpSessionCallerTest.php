<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

require_once __DIR__ . '/McpAuthHelpers.php';

const SESSION_ORIGIN = 'https://totalcms.test';

// Sibling MCP suites only wipe cmsDataDir() once per file (beforeAll), not
// per test — see McpAuthPersonaSearchResourcesTest's "beforeAll only wipes
// cmsDataDir() once for the whole file" note. This file's fixture (the
// 'notes' collection + the 'session-post' object) is identical across every
// test though, unlike those suites' per-test-unique ids, so the writes below
// are guarded to be idempotent instead of wiping per test.
beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

/**
 * A browser session on /mcp: the WebMCP surface. Same-origin + cookie is the
 * whole credential; the caller is read-only; everything else is the MCP
 * server's own rules (MCP Access, groups, exposure).
 */
beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
	mcpAuthSeedUser('admin-user-test-com');
	mcpAuthSeedUser('blogger-user-test-com');
	mcpAuthSeedAccessGroups();

	$container = $this->app->getContainer();

	// blog: admin-only over MCP, but blogger's group grants read → visible to
	// blogger. fetchOrCreateReserved() is already idempotent (fetch-or-create).
	mcpAuthSetCollectionAccess($this->app, 'blog', 'admin');

	// notes: admin-only, no grant → hidden from blogger. Plain saveCollection()
	// throws on a second call for the same id, so guard it explicitly.
	if (!$container->get(CollectionFetcher::class)->collectionExists('notes')) {
		$container->get(CollectionSaver::class)->saveCollection([
			'id' => 'notes', 'name' => 'Notes', 'schema' => 'blog', 'mcp' => ['access' => 'admin'],
		]);
	}

	if (!$container->get(ObjectFetcher::class)->existsObject('blog', 'session-post')) {
		$container->get(ObjectSaver::class)->saveObject('blog', ['id' => 'session-post', 'title' => 'Session Post', 'draft' => false]);
	}
});

/**
 * Full tool entries (name + annotations, etc.), not just names — lets a test
 * check every listed tool's readOnlyHint, not just a hand-picked few.
 */
function sessionTools(\Slim\App $app, string $origin = SESSION_ORIGIN, string $ip = '203.0.113.60'): array
{
	$response = mcpStatelessCall($app, 'tools/list', [], ['Origin' => $origin], $ip);
	expect($response->getStatusCode())->toBe(200);
	$body = json_decode((string)$response->getBody(), true);

	return $body['result']['tools'] ?? [];
}

function sessionToolsList(\Slim\App $app, string $origin = SESSION_ORIGIN, string $ip = '203.0.113.60'): array
{
	return array_column(sessionTools($app, $origin, $ip), 'name');
}

/**
 * Every tool a session caller's tools/list returns must be read-only — a
 * tool with no `annotations` (or no `readOnlyHint`) is read-only BY DEFAULT
 * (same convention as McpToolAnnotationsTest's
 * `$tool->annotations?->readOnlyHint ?? true`), so only an explicit `false`
 * fails this.
 */
function assertAllSessionToolsAreReadOnly(array $tools): void
{
	foreach ($tools as $tool) {
		$name         = (string)($tool['name'] ?? '?');
		$readOnlyHint = $tool['annotations']['readOnlyHint'] ?? true;
		expect($readOnlyHint)->toBeTrue("tool \"{$name}\" must declare readOnlyHint true (or omit it) for a session caller");
	}
}

describe('a super-admin session', function (): void {
	beforeEach(fn () => signInAs($this->app, 'admin-user-test-com', 'auth'));

	test('lists the read tools and none of the write tools', function (): void {
		$tools = sessionTools($this->app, SESSION_ORIGIN, '203.0.113.61');
		$names = array_column($tools, 'name');

		expect($names)->toContain('query_collection')->toContain('get_object')->toContain('list_collections')
			->and($names)->not->toContain('create_object')->not->toContain('patch_object')
			->not->toContain('delete_schema')->not->toContain('clear_cache');

		// Named absence checks above only pin a handful of known write tools —
		// this closes the general case: every tool actually returned must be
		// read-only, not just the ones we thought to name.
		assertAllSessionToolsAreReadOnly($tools);
	});

	test('reads an admin-only collection', function (): void {
		$response = mcpStatelessCall($this->app, 'tools/call', ['name' => 'query_collection', 'arguments' => ['collection' => 'blog']], ['Origin' => SESSION_ORIGIN], '203.0.113.62');

		expect($response->getStatusCode())->toBe(200)
			->and(array_column(mcpAuthStructuredItems($response), 'id'))->toContain('session-post');
	});

	test('calling a write tool by name fails as unknown and writes nothing', function (): void {
		$response = mcpStatelessCall($this->app, 'tools/call', ['name' => 'create_object', 'arguments' => ['collection' => 'blog', 'data' => ['id' => 'never-written', 'title' => 'x']]], ['Origin' => SESSION_ORIGIN], '203.0.113.63');
		$body     = json_decode((string)$response->getBody(), true);

		// The tool is not registered for a session caller, so the SDK answers
		// with a JSON-RPC error (unknown tool), not a tool result.
		expect($body)->toHaveKey('error')
			->and(file_exists(objectPath('blog', 'never-written')))->toBeFalse();
	});

	test('a schema-defined saved-query tool is listed for a session caller', function (): void {
		$container  = $this->app->getContainer();
		$collection = $container->get(CollectionFetcher::class)->fetchCollection('blog');
		$mcp        = is_array($collection->mcp) ? $collection->mcp : [];
		$collection->mcp = array_merge($mcp, ['tools' => ['recent_posts' => ['id' => 'recent_posts', 'description' => 'Newest posts', 'sort' => 'date:desc', 'limit' => 5]]]);
		$container->get(CollectionRepository::class)->saveCollection($collection);

		expect(sessionToolsList($this->app, SESSION_ORIGIN, '203.0.113.64'))->toContain('recent_posts');
	});

	test('gets no resources and no prompts: the session surface is tools-only', function (): void {
		// Observed shape (php-mcp/server 0.8.x, stateless mode): a capability
		// the server never advertised still answers 200 with an EMPTY list
		// rather than a JSON-RPC error — there is no registered resource/prompt
		// handler for the SDK's list dispatch to reject the method on. The
		// tools-only build (McpServerFactory, session branch) never registers
		// any resource or prompt, so the list is unconditionally empty: no
		// resource or prompt is ever reachable through this response, which is
		// the actual requirement ("nothing is exposed"), not a specific error
		// code. See task-7-report.md for the brief's alternate expectation
		// (a JSON-RPC error) and why this is the correct assertion instead.
		foreach ([
			'resources/list' => ['203.0.113.71', 'resources'],
			'prompts/list'   => ['203.0.113.72', 'prompts'],
		] as $method => [$ip, $listKey]) {
			$response = mcpStatelessCall($this->app, $method, [], ['Origin' => SESSION_ORIGIN], $ip);
			$body     = json_decode((string)$response->getBody(), true);

			expect($response->getStatusCode())->toBe(200)
				->and($body['result'][$listKey] ?? null)->toBe([]);
		}
	});
});

describe('a non-admin session', function (): void {
	beforeEach(fn () => signInAs($this->app, 'blogger-user-test-com', 'auth'));

	test('reads what its groups grant, and is refused by the group layer on a collection its groups do not grant', function (): void {
		// The outer beforeEach sets 'blog' and 'notes' to mcp.access:'admin'.
		// For the objects/read domain, McpServerFactory::guardHandler() special-
		// cases the group-authority check to PersonaContext::canReadCollection(),
		// which is satisfied by EITHER public exposure OR a real group grant —
		// exposure level plays no part in it. So with 'blog'/'notes' left at
		// 'admin', 'blog' would actually pass this gate already (blogger's group
		// grants it per the fixture) and only fail a few lines later inside the
		// handler's OWN, separate, persona-only exposure check — never reaching
		// hasReadGrant() at all for 'notes' either, since that gate runs first
		// and 'notes' would fail it regardless of any grant. Neither of those
		// proves the GROUP layer specifically. Raising both to 'authenticated'
		// removes exposure as a confound: isAccessibleTo('authenticated') admits
		// the persona for both collections, so the only thing left to decide
		// each call is `hasReadGrant()` — a real test of the group layer. If the
		// session branch ever resolved an over-broad authority (wrong user's
		// groups, or an admin-group authority) for a non-admin caller, 'notes'
		// would start passing incorrectly.
		mcpAuthSetCollectionAccess($this->app, 'blog', 'authenticated');
		mcpAuthSetCollectionAccess($this->app, 'notes', 'authenticated');

		$granted = mcpStatelessCall($this->app, 'tools/call', ['name' => 'query_collection', 'arguments' => ['collection' => 'blog']], ['Origin' => SESSION_ORIGIN], '203.0.113.65');
		$denied  = mcpStatelessCall($this->app, 'tools/call', ['name' => 'query_collection', 'arguments' => ['collection' => 'notes']], ['Origin' => SESSION_ORIGIN], '203.0.113.66');

		expect(array_column(mcpAuthStructuredItems($granted), 'id'))->toContain('session-post');

		// Observed shape: a registered tool's group-layer refusal is a
		// ToolCallException surfaced as `result.isError: true`, with the exact
		// message McpServerFactory::guardHandler() throws when
		// PersonaContext::canReadCollection() returns false for the ToolRequirement
		// group-grant check (objects/read/collection) — "Your account's groups do
		// not grant read on '<collection>'." — NOT a JSON-RPC error, and NOT the
		// exposure-layer wording the next test pins. Asserting the exact text
		// distinguishes which layer refused it.
		$deniedBody = json_decode((string)$denied->getBody(), true);
		expect($deniedBody['result']['isError'] ?? null)->toBeTrue()
			->and($deniedBody['result']['content'][0]['text'] ?? '')->toBe("Your account's groups do not grant read on 'notes'.");
	});

	test('is refused by the exposure layer even on a collection its groups grant, when mcp.access stays admin', function (): void {
		// Explicitly (re-)set 'blog' to mcp.access:'admin' — the outer
		// beforeEach already leaves it there, but this test's whole point is
		// that value, so it says so rather than relying on an outer default.
		// Blogger's group grants read on 'blog' (fixture), which alone
		// satisfies guardHandler()'s canReadCollection() carve-out — the SAME
		// gate the previous test proved is real. What still blocks the call is
		// a SECOND, independent, persona-only gate:
		// PersonaContext::exposedCollection(), which QueryCollectionTool::
		// handler() calls itself before doing anything else, and which
		// McpSchemaResolver::isAccessibleTo() refuses for ANY non-ADMIN persona
		// when mcp.access is 'admin' — group grant or not. This is the read-path
		// analogue of McpAuthPersonaGroupAccessTest's reviewed write-tool finding
		// ("blogger create_object is denied at the exposure layer while their
		// group-granted collection stays at the default (unexposed) mcp.access").
		mcpAuthSetCollectionAccess($this->app, 'blog', 'admin');

		$response = mcpStatelessCall($this->app, 'tools/call', ['name' => 'query_collection', 'arguments' => ['collection' => 'blog']], ['Origin' => SESSION_ORIGIN], '203.0.113.74');
		$body     = json_decode((string)$response->getBody(), true);

		expect($body['result']['isError'] ?? null)->toBeTrue()
			->and($body['result']['content'][0]['text'] ?? '')->toBe('Collection "blog" is not available to the current caller. Use list_collections to see what you can query.');
	});

	test('sees no write tools', function (): void {
		$tools = sessionTools($this->app, SESSION_ORIGIN, '203.0.113.67');

		expect(array_column($tools, 'name'))->not->toContain('create_object');
		assertAllSessionToolsAreReadOnly($tools);
	});
});

describe('a session that is not same-origin', function (): void {
	beforeEach(fn () => signInAs($this->app, 'admin-user-test-com', 'auth'));

	test('is anonymous: an admin-only collection is refused', function (): void {
		// 'blog' here is whatever the outer beforeEach set it to
		// (mcp.access:'admin') — this describe block never raises it, unlike
		// "a non-admin session" above. Cross-origin drops the session
		// entirely, so the caller resolves as anonymous/PUBLIC — refused by
		// the same exposure gate (PersonaContext::exposedCollection()) an
		// 'admin'-exposed collection refuses ANY non-ADMIN persona with,
		// worded identically to the 'gallery' exposure-layer case above.
		$response = mcpStatelessCall($this->app, 'tools/call', ['name' => 'query_collection', 'arguments' => ['collection' => 'blog']], ['Origin' => 'https://evil.example'], '203.0.113.68');
		$body     = json_decode((string)$response->getBody(), true);

		expect($body['result']['isError'] ?? null)->toBeTrue()
			->and($body['result']['content'][0]['text'] ?? '')->toBe('Collection "blog" is not available to the current caller. Use list_collections to see what you can query.');
	});

	test('is login_required when public access is off', function (): void {
		$config      = $this->app->getContainer()->get(Config::class);
		$config->mcp = array_merge($config->mcp, ['publicAccess' => false]);

		$response = mcpStatelessCall($this->app, 'tools/list', [], ['Origin' => 'https://evil.example'], '203.0.113.69');

		expect($response->getStatusCode())->toBe(401)
			->and($response->getHeaderLine('WWW-Authenticate'))->toContain('login_required');
	});
});

test('an API-key request with a session cookie keeps the API-key persona', function (): void {
	signInAs($this->app, 'blogger-user-test-com', 'auth');
	file_put_contents(cmsDataDir() . '.system/apikeys.json', (string)json_encode(['apikeys' => [[
		'id' => 'k', 'name' => 'k', 'key' => 'tcms_mcp_session_test_key_00000000000000', 'created' => '2026-01-01T00:00:00Z', 'lastUsed' => null,
		'scopes' => ['methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'paths' => ['*']],
	]]]));

	$names = array_column(
		json_decode((string)mcpStatelessCall($this->app, 'tools/list', [], ['Origin' => SESSION_ORIGIN, 'X-API-Key' => 'tcms_mcp_session_test_key_00000000000000'], '203.0.113.70')->getBody(), true)['result']['tools'],
		'name',
	);

	// This test's apikeys.json write must not become load-bearing for any
	// test that runs after it in the same worker (this file's fixture writes
	// are otherwise all idempotent/guarded — see the outer beforeEach).
	@unlink(cmsDataDir() . '.system/apikeys.json');

	expect($names)->toContain('create_object');
});
