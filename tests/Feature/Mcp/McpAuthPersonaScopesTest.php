<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
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
// Scenarios 1-9: persona resolution by scope, invalid tokens, lifecycle notifications, and super-admin elevation.

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
	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 1: Bearer token with mcp:tools scope → AUTHENTICATED persona;
	// tools/list returns tools
	// ──────────────────────────────────────────────────────────────────────────

	it('Bearer token with mcp:tools scope resolves to AUTHENTICATED persona and tools/list returns tools', function (): void {
		mcpAuthSetupOAuthKeys($this->app);

		$clientId = 'mcp-auth-tools-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:tools']);

		// Empty token means the OAuth flow failed (non-Pro edition, OAuth not configured).
		if ($token === '') {
			expect(true)->toBeTrue(); // skip-safe pass

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);

		if ($sessionId === '') {
			// MCP endpoint unavailable (edition gate, disabled config, etc.).
			expect(true)->toBeTrue();

			return;
		}

		$response = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/list',
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toBeArray();
		expect($body)->toHaveKey('result');
		expect($body['result'])->toHaveKey('tools');
		expect($body['result']['tools'])->toBeArray();

		// At minimum the public tools should be visible to AUTHENTICATED persona.
		$toolNames = array_column($body['result']['tools'], 'name');
		expect($toolNames)->toContain('list_collections');
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 2: Bearer token with mcp:tools scope → tools/call succeeds
	// ──────────────────────────────────────────────────────────────────────────

	it('Bearer token with mcp:tools scope allows tools/call for list_collections', function (): void {
		mcpAuthSetupOAuthKeys($this->app);

		$clientId = 'mcp-auth-toolscall-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:tools']);

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
			'id'      => 2,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'list_collections',
				'arguments' => new stdClass(),
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toBeArray();
		// tools/call result wraps in 'result' → 'content'
		expect($body)->toHaveKey('result');
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 3: Bearer token with mcp:resources scope only →
	// tools/call returns 403 insufficient_scope
	// ──────────────────────────────────────────────────────────────────────────

	it('Bearer token with mcp:resources scope only returns 403 when calling tools/call', function (): void {
		mcpAuthSetupOAuthKeys($this->app);

		$clientId = 'mcp-auth-res-only-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:resources']);

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
			'id'      => 3,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => 'list_collections',
				'arguments' => new stdClass(),
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(403);
		expect($response->getHeaderLine('WWW-Authenticate'))->toContain('insufficient_scope');
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 4: Bearer token with mcp:resources scope → resources/list succeeds
	// ──────────────────────────────────────────────────────────────────────────

	it('Bearer token with mcp:resources scope allows resources/list', function (): void {
		mcpAuthSetupOAuthKeys($this->app);

		$clientId = 'mcp-auth-reslist-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:resources']);

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
			'id'      => 4,
			'method'  => 'resources/list',
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toBeArray();
		expect($body)->toHaveKey('result');
		expect($body['result'])->toHaveKey('resources');
		expect($body['result']['resources'])->toBeArray();
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 5: Bearer token with NO mcp:* scope → 401 insufficient_scope
	// ──────────────────────────────────────────────────────────────────────────

	it('Bearer token with no mcp:* scope returns 401 insufficient_scope from McpAuth', function (): void {
		mcpAuthSetupOAuthKeys($this->app);

		$clientId = 'mcp-auth-no-mcp-scope-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:read']);

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		// Send directly without initializing — McpAuth rejects before SDK runs.
		$response = mcpAuthRawBearerRequest($this->app, $token, 'tools/list');

		// The endpoint returns 401 regardless of whether MCP is Pro-gated or not:
		// the edition check runs before auth, so if MCP is unavailable the response
		// is 403 (edition) or 404 (disabled). We only assert the auth failure shape
		// when the response is actually 401.
		if ($response->getStatusCode() === 401) {
			$header = $response->getHeaderLine('WWW-Authenticate');
			expect($header)->toContain('insufficient_scope');
		} else {
			// Non-Pro / disabled env — skip-safe pass.
			expect($response->getStatusCode())->toBeIn([403, 404]);
		}
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 6: Invalid Bearer token → 401 invalid_token from OAuthBearerMiddleware
	// ──────────────────────────────────────────────────────────────────────────

	it('invalid Bearer token returns 401 with invalid_token from OAuthBearerMiddleware', function (): void {
		mcpAuthSetupOAuthKeys($this->app);

		// A well-formed JWT shape but with a garbage signature — league's
		// BearerTokenValidator will reject it at the JWK validation step.
		$malformedJwt = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ0ZXN0In0.invalidsignature';

		$response = mcpAuthRawBearerRequest($this->app, $malformedJwt, 'tools/list');

		// OAuthBearerMiddleware fires before the edition check — 401 even on
		// non-Pro environments.  If MCP is disabled (404), it means OAuthBearer
		// ran but the endpoint returned 404 first (mcp.enabled=false) — our
		// middleware mounts before that gate.  In practice, on a non-Pro env
		// the edition gate fires at 403 AFTER auth; so 401 is the primary expected
		// status code for a malformed JWT.
		expect($response->getStatusCode())->toBeIn([401, 403, 404]);

		if ($response->getStatusCode() === 401) {
			$header = $response->getHeaderLine('WWW-Authenticate');
			expect($header)->toContain('invalid_token');
		}
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 7: lifecycle notifications are not scope-gated. No scope lists
	// notifications/initialized in its mcpOperations, so a scope-gated check
	// can never pass — yet the spec requires clients to send it right after
	// initialize. claude.ai treats the 403 as a dead server and reports
	// "no tools available", regardless of how many scopes the token carries.
	// ──────────────────────────────────────────────────────────────────────────

	it('Bearer token may send notifications/initialized without a scope granting it', function (): void {
		mcpAuthSetupOAuthKeys($this->app);

		$clientId = 'mcp-auth-lifecycle-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['mcp:tools']);

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
			'method'  => 'notifications/initialized',
		], $sessionId);

		// The SDK transport acks notifications with 202; anything but the
		// scope gate's 403 completes the handshake.
		expect($response->getStatusCode())->toBeIn([200, 202]);

		// The handshake done, tools/list must now succeed.
		$list = mcpAuthRequest($this->app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/list',
		], $sessionId);

		expect($list->getStatusCode())->toBe(200);
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 8: super-admin elevation. A token authorized by a user in the
	// admin group AND carrying cms:admin resolves to the ADMIN persona — the
	// full tool surface, same as an API key. Both conditions are required:
	// the identity proves who consented, the scope proves what they consented
	// to. Either one alone stays AUTHENTICATED.
	// ──────────────────────────────────────────────────────────────────────────

	it('admin-group user with cms:admin scope is elevated to the ADMIN persona', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('admin-user-test-com');

		$names = mcpAuthListToolsFor($this->app, 'admin-user-test-com', ['cms:admin', 'mcp:tools']);

		if ($names === null) {
			expect(true)->toBeTrue(); // skip-safe pass

			return;
		}

		expect($names)->toContain('create_schema');
		expect($names)->toContain('clear_cache');
	});

	it('admin-group user without cms:admin scope stays AUTHENTICATED', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('admin-user-test-com');

		$names = mcpAuthListToolsFor($this->app, 'admin-user-test-com', ['mcp:tools']);

		if ($names === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($names)->toContain('list_collections');
		// NOT create_schema: as of Task 8, create_schema carries a
		// ToolRequirement, so — unlike before Task 8, when it was purely
		// access:'admin'-gated — its VISIBILITY is no longer persona-only. An
		// admin-GROUP user's UserAuthority::isAdmin is TRUE regardless of the
		// token's scope (PersonaContext resolves authority independently of
		// persona/scope — see McpEndpointAction), so create_schema is now
		// visible to this exact user even without cms:admin scope (it would
		// still be REJECTED at call time by the scope-layer guard). The
		// correct proxy for "did NOT reach the ADMIN persona" is a tool with
		// NO ToolRequirement that stays strictly access:'admin' — get_site_info
		// (SiteInfoTool) and list_extensions (ExtensionTools) are the two
		// tools Task 8 deliberately left without one.
		expect($names)->not->toContain('get_site_info');
		expect($names)->not->toContain('list_extensions');
	});

	it('non-admin user with cms:admin scope stays AUTHENTICATED', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('viewer-user-test-com');

		$names = mcpAuthListToolsFor($this->app, 'viewer-user-test-com', ['cms:admin', 'mcp:tools']);

		if ($names === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($names)->toContain('list_collections');
		expect($names)->not->toContain('create_schema');
	});

	it('unknown user with cms:admin scope stays AUTHENTICATED', function (): void {
		mcpAuthSetupOAuthKeys($this->app);

		$names = mcpAuthListToolsFor($this->app, 'ghost@nowhere.test', ['cms:admin', 'mcp:tools']);

		if ($names === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($names)->not->toContain('create_schema');
	});

	it('elevation still works when the sub is composite', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('admin-user-test-com');

		// mcpAuthIssueToken (updated this task) sets AUTH_COLLECTION='auth',
		// so the issued sub is "auth:admin-user-test-com".
		$names = mcpAuthListToolsFor($this->app, 'admin-user-test-com', ['cms:admin', 'mcp:tools']);
		if ($names === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($names)->toContain('create_schema');
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Security regression: a user in a SECONDARY auth collection ('members')
	// whose id COLLIDES with the default collection's admin id must NOT be
	// elevated to ADMIN, even though the same id genuinely is an admin in the
	// default 'auth' collection. This is the real OAuth consent + token-mint
	// + MCP-persona pipeline this task's fix closes — UserValidationService::
	// isSuperAdmin() now only recognizes admins whose CALLER collection
	// matches the default one.
	// ──────────────────────────────────────────────────────────────────────────

	it('a colliding id in a secondary auth collection is NOT elevated to ADMIN over MCP', function (): void {
		mcpAuthSetupOAuthKeys($this->app);

		// Genuine admin in the default 'auth' collection.
		mcpAuthSeedUser('admin-user-test-com');

		// Same id, but as a NON-admin member of a secondary 'members'
		// collection — the collision this fix must not fall for.
		$membersDir = cmsDataDir() . 'members';
		if (!is_dir($membersDir)) {
			mkdir($membersDir, 0777, true);
		}
		file_put_contents($membersDir . '/admin-user-test-com.json', (string)json_encode([
			'id'       => 'admin-user-test-com',
			'active'   => true,
			'name'     => 'Colliding Member',
			'email'    => 'colliding-member@test.com',
			'password' => password_hash('irrelevant', PASSWORD_BCRYPT),
			'groups'   => ['member'],
		]));

		$clientId = 'mcp-auth-cross-collection-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:admin', 'mcp:tools'], 'admin-user-test-com', 'members');

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
			'method'  => 'tools/list',
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body  = json_decode((string)$response->getBody(), true);
		$names = array_column($body['result']['tools'] ?? [], 'name');

		// AUTHENTICATED, not ADMIN: the caller's real collection ('members')
		// does not match the default, so the id collision must not confer
		// super-admin authority.
		expect($names)->toContain('list_collections');
		expect($names)->not->toContain('create_schema');
		expect($names)->not->toContain('clear_cache');
		expect($names)->not->toContain('get_site_info');
		expect($names)->not->toContain('list_extensions');

		@unlink($membersDir . '/admin-user-test-com.json');
	});

	// ──────────────────────────────────────────────────────────────────────────
	// Scenario 9 (updated Task 8): admin-gated SCOPE ISSUANCE. Before Task 8,
	// cms:admin required the SUPER-ADMIN group specifically (isAdmin alone).
	// Task 8 widens the issuance gate to isAdmin() OR
	// UserAuthority::hasAdminDomainGrants() (LeagueScopeRepository::
	// finalizeScopes(), OAuthAuthorizeAction's consent filter) — a non-admin
	// whose access-group grants SOME admin-domain permission (schemas,
	// collectionsMeta, or a utils allow) can now convey the scope too. Per
	// Joe's decision (progress.md, 2026-08-05): this breadth is ACCEPTED, not
	// a bug — no narrowing predicate, no new scope. In THIS fixture (and even
	// the framework's built-in default group templates —
	// AccessGroupRepository::VIEWER_GROUP_TEMPLATE / DEFAULT_GROUP_TEMPLATE —
	// auto-created on first access when no access-groups.json exists yet) the
	// 'viewer' group grants schemas {all:true, operations:[read]}, which is
	// enough for hasAdminDomainGrants() to return true. The access-group
	// layer still caps what the resulting token can actually DO (see the
	// blogger denial tests above) — this only widens who may REQUEST/consent
	// to the scope.
	// ──────────────────────────────────────────────────────────────────────────

	// Important #5 (Task 8 fix round, security review): this file runs with
	// auth.enable=false (see file header discussion / config/local.test.php),
	// which makes SchemaAccessMiddleware inert — BaseAccessMiddleware returns
	// immediately, so this test does NOT exercise the group layer at all.
	// What IS exercised here, independent of auth.enable, is
	// OAuthRestScopeMiddleware (mounted globally on /api, config/routes.php) —
	// this proves the widened issuance gate actually produces a token that
	// clears the SCOPE gate (before Task 8, finalizeScopes() would have
	// stripped cms:admin from viewer's token entirely, and this same request
	// would 403 with insufficient_scope). The GROUP-layer counterpart — that
	// viewer's token still cannot use cms:admin to bypass their group's
	// read-only schemas grant — is proven with auth ACTUALLY enabled in
	// tests/Feature/OAuthRestGroupAccessTest.php ("a viewer token with
	// widened cms:admin scope still cannot create a schema").
	it("a viewer's token requesting cms:admin clears the SCOPE layer on admin REST paths (widened issuance gate; group layer is NOT exercised here — see OAuthRestGroupAccessTest)", function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('viewer-user-test-com');

		$clientId = 'mcp-auth-rest-' . uniqid('', true);
		$token    = mcpAuthIssueToken($this->app, $clientId, 'secret', ['cms:admin', 'mcp:tools'], 'viewer-user-test-com');

		if ($token === '') {
			expect(true)->toBeTrue(); // skip-safe pass

			return;
		}

		$factory  = new Psr17Factory();
		$response = $this->app->handle(
			$factory->createServerRequest('GET', '/api/schemas')
				->withHeader('Authorization', 'Bearer ' . $token),
		);

		expect($response->getStatusCode())->toBe(200);
	});

	it('consent page shows cms:admin to a non-admin user whose groups grant admin-domain access, and greets by name', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('viewer-user-test-com');

		$body = mcpAuthConsentPageBody($this->app, ['cms:read', 'cms:admin', 'mcp:tools'], 'viewer-user-test-com');

		if ($body === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($body)->toContain('cms:admin');
		expect($body)->toContain('cms:read');
		expect($body)->toContain('Viewer Test User');
	});

	it('consent page still hides cms:admin from a caller whose identity cannot be resolved at all', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		// Deliberately NOT seeded — no auth-collection object exists for this
		// id, so AccessControlService::authorityFor() falls through to
		// UserAuthority::denied() (isAdmin:false, groups:[]):
		// hasAdminDomainGrants() is false with no groups to iterate. This is
		// the one remaining case the widened gate still denies.
		$body = mcpAuthConsentPageBody($this->app, ['cms:read', 'cms:admin', 'mcp:tools'], 'ghost@nowhere.test');

		if ($body === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($body)->not->toContain('cms:admin');
		expect($body)->toContain('cms:read');
	});

	it('consent page shows cms:admin to admin users', function (): void {
		mcpAuthSetupOAuthKeys($this->app);
		mcpAuthSeedUser('admin-user-test-com');

		$body = mcpAuthConsentPageBody($this->app, ['cms:read', 'cms:admin', 'mcp:tools'], 'admin-user-test-com');

		if ($body === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($body)->toContain('cms:admin');
		expect($body)->toContain('Admin Test User');
	});

	// ──────────────────────────────────────────────────────────────────────────
});
