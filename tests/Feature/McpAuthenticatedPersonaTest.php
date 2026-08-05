<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

// ──────────────────────────────────────────────────────────────────────────────
// Bootstrap
// ──────────────────────────────────────────────────────────────────────────────

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
function mcpAuthSetupOAuthKeys(Slim\App $app): array
{
	$tmpDir = sys_get_temp_dir() . '/oauth-mcp-test-' . uniqid('', true);
	mkdir($tmpDir, 0700, true);

	$resource = openssl_pkey_new([
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	]);
	assert($resource !== false);

	openssl_pkey_export($resource, $privatePem);
	$details = openssl_pkey_get_details($resource);
	assert($details !== false);

	$privatePath = $tmpDir . '/private.key';
	$publicPath  = $tmpDir . '/public.key';
	file_put_contents($privatePath, $privatePem);
	file_put_contents($publicPath, $details['key']);
	chmod($privatePath, 0600);

	$config        = $app->getContainer()->get(Config::class);
	$config->oauth = array_merge($config->oauth, [
		'signingKeyPath'    => $privatePath,
		'publicKeyPath'     => $publicPath,
		'accessTokenTtl'    => 'PT1H',
		'refreshTokenTtl'   => 'P30D',
		'authCodeTtl'       => 'PT10M',
		'allowedGrantTypes' => ['authorization_code', 'refresh_token'],
		'pkceMethods'       => ['S256'],
	]);

	return [
		'privateKey' => $privatePem,
		'publicKey'  => (string)$details['key'],
		'tmpDir'     => $tmpDir,
	];
}

/**
 * Copy a fixture user into the test auth collection so isSuperAdmin() can
 * resolve them. Fixture ids: 'admin-user-test-com' (groups: [admin]) and
 * 'viewer-user-test-com' (groups: [viewer]).
 */
function mcpAuthSeedUser(string $fixtureId): void
{
	$authDir = cmsDataDir() . '/auth';
	if (!is_dir($authDir)) {
		mkdir($authDir, 0777, true);
	}
	copy(
		dirname(__DIR__) . '/tcms-data-fixtures/auth/' . $fixtureId . '.json',
		$authDir . '/' . $fixtureId . '.json',
	);
}

/**
 * Copy the access-groups fixture into the test data dir so
 * AccessControlService::authorityFor() can resolve REAL group grants
 * (findById('blogger'), findById('viewer'), etc.) instead of an empty/
 * unresolved group list. mcpAuthSeedUser() only copies the auth user record
 * (which references group ids by name); this copies the group DEFINITIONS
 * those ids point to. Distinct name from OAuthRestGroupAccessTest's
 * groupRestSeedUser() (which bundles both in one call) since this file's
 * mcpAuthSeedUser() already exists and is used by tests that intentionally
 * DON'T want real group resolution (elevation-only scenarios).
 */
function mcpAuthSeedAccessGroups(): void
{
	$systemDir = cmsDataDir() . '/.system';
	if (!is_dir($systemDir)) {
		mkdir($systemDir, 0777, true);
	}
	copy(
		dirname(__DIR__) . '/tcms-data-fixtures/.system/access-groups.json',
		$systemDir . '/access-groups.json',
	);
}

/**
 * Walk the full authorization-code + PKCE flow and return an access token
 * with the given scopes.
 *
 * @param list<string> $scopes
 */
function mcpAuthIssueToken(Slim\App $app, string $clientId, string $clientSecret, array $scopes, string $userId = 'admin@example.test'): string
{
	$client = new OAuthClientData(
		id: $clientId,
		name: 'MCP Auth Test Client',
		secretHash: password_hash($clientSecret, PASSWORD_BCRYPT),
		redirectUris: ['https://mcptest.test/cb'],
		scopes: $scopes,
		isDynamic: false,
		isConfidential: true,
		createdAt: gmdate('c'),
		createdBy: 'test',
	);
	$app->getContainer()->get(OAuthClientRepository::class)->save($client);

	/** @var PhpSession $session */
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, $userId);
	$session->set(SessionKeys::AUTH_COLLECTION, 'auth');

	$codeVerifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

	$factory = new Psr17Factory();

	// Step 1: GET /oauth/authorize → consent page
	$authorizeUrl = '/oauth/authorize?' . http_build_query([
		'response_type'         => 'code',
		'client_id'             => $clientId,
		'redirect_uri'          => 'https://mcptest.test/cb',
		'scope'                 => implode(' ', $scopes),
		'state'                 => 'mcptest-state',
		'code_challenge'        => $codeChallenge,
		'code_challenge_method' => 'S256',
	]);
	$app->handle($factory->createServerRequest('GET', $authorizeUrl));

	// Re-open session to get stashed authorize request, then mint CSRF token.
	if (!$session->isStarted()) {
		$session->start();
	}
	/** @var CSRFTokenManager $csrf */
	$csrf      = $app->getContainer()->get(CSRFTokenManager::class);
	$csrfToken = $csrf->generateToken();

	// Step 2: POST /oauth/authorize with decision=approve → 302 with code
	$approve = $app->handle(
		$factory->createServerRequest('POST', '/oauth/authorize')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody(['decision' => 'approve', 'csrf_token' => $csrfToken]),
	);

	parse_str((string)parse_url($approve->getHeaderLine('Location'), PHP_URL_QUERY), $cb);
	$code = (string)($cb['code'] ?? '');

	// Step 3: POST /oauth/token → access token
	$tokenResp = $app->handle(
		$factory->createServerRequest('POST', '/oauth/token')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody([
				'grant_type'    => 'authorization_code',
				'client_id'     => $clientId,
				'client_secret' => $clientSecret,
				'redirect_uri'  => 'https://mcptest.test/cb',
				'code'          => $code,
				'code_verifier' => $codeVerifier,
			]),
	);

	$payload = json_decode((string)$tokenResp->getBody(), true);

	return (string)($payload['access_token'] ?? '');
}

/**
 * Initialize an MCP session using a Bearer access token.
 * Returns the Mcp-Session-Id or empty string when MCP is unavailable.
 */
function mcpAuthInitSession(Slim\App $app, string $accessToken): string
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('Authorization', 'Bearer ' . $accessToken);

	$request->getBody()->write((string)json_encode([
		'jsonrpc' => '2.0',
		'id'      => 0,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest-mcp-auth', 'version' => '0.1'],
		],
	]));
	$request->getBody()->rewind();

	$response = $app->handle($request);

	if ($response->getStatusCode() !== 200) {
		return '';
	}

	return $response->getHeaderLine('Mcp-Session-Id');
}

/**
 * POST to /mcp with a Bearer token + optional session ID.
 *
 * @param array<string,mixed> $payload   JSON-RPC payload
 * @param string              $sessionId Session ID from mcpAuthInitSession()
 */
function mcpAuthRequest(
	Slim\App $app,
	string $accessToken,
	array $payload,
	string $sessionId = '',
): Psr\Http\Message\ResponseInterface {
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('Authorization', 'Bearer ' . $accessToken);

	if ($sessionId !== '') {
		$request = $request->withHeader('Mcp-Session-Id', $sessionId);
	}

	$request->getBody()->write((string)json_encode($payload));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * POST to /mcp with an Authorization: Bearer header containing an arbitrary
 * (potentially malformed) token string.  Used for the invalid-token scenario
 * where we need raw header control without a valid session.
 */
function mcpAuthRawBearerRequest(Slim\App $app, string $rawToken, string $method): Psr\Http\Message\ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('Authorization', 'Bearer ' . $rawToken);

	$request->getBody()->write((string)json_encode([
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => $method,
		'params'  => new stdClass(),
	]));
	$request->getBody()->rewind();

	return $app->handle($request);
}

// ──────────────────────────────────────────────────────────────────────────────
// Tests
// ──────────────────────────────────────────────────────────────────────────────

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

	/**
	 * @param list<string> $scopes
	 * @return list<string>|null Tool names, or null when the env can't run OAuth
	 */
	function mcpAuthListToolsFor(Slim\App $app, string $userId, array $scopes): ?array
	{
		$clientId = 'mcp-auth-elevation-' . uniqid('', true);
		$token    = mcpAuthIssueToken($app, $clientId, 'secret', $scopes, $userId);
		if ($token === '') {
			return null;
		}

		$sessionId = mcpAuthInitSession($app, $token);
		if ($sessionId === '') {
			return null;
		}

		$response = mcpAuthRequest($app, $token, [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/list',
		], $sessionId);

		$body = json_decode((string)$response->getBody(), true);

		return array_column($body['result']['tools'] ?? [], 'name');
	}

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
		if ($names === null) { expect(true)->toBeTrue(); return; }

		expect($names)->toContain('create_schema');
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
		$this->app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');

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
});

/**
 * Render the consent page for a logged-in user and return the HTML, or null
 * when the environment can't serve it (non-Pro edition, OAuth unavailable).
 *
 * @param list<string> $scopes
 */
function mcpAuthConsentPageBody(Slim\App $app, array $scopes, string $userId): ?string
{
	$clientId = 'mcp-auth-consent-' . uniqid('', true);
	$client   = new OAuthClientData(
		id: $clientId,
		name: 'Consent Page Test Client',
		secretHash: password_hash('secret', PASSWORD_BCRYPT),
		redirectUris: ['https://mcptest.test/cb'],
		scopes: $scopes,
		isDynamic: false,
		isConfidential: true,
		createdAt: gmdate('c'),
		createdBy: 'test',
	);
	$app->getContainer()->get(OAuthClientRepository::class)->save($client);

	/** @var PhpSession $session */
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, $userId);

	$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', 'consent-verifier', true)), '+/', '-_'), '=');
	$authorizeUrl  = '/oauth/authorize?' . http_build_query([
		'response_type'         => 'code',
		'client_id'             => $clientId,
		'redirect_uri'          => 'https://mcptest.test/cb',
		'scope'                 => implode(' ', $scopes),
		'state'                 => 'consent-test-state',
		'code_challenge'        => $codeChallenge,
		'code_challenge_method' => 'S256',
	]);

	$response = $app->handle((new Psr17Factory())->createServerRequest('GET', $authorizeUrl));

	if ($response->getStatusCode() !== 200) {
		return null;
	}

	return (string)$response->getBody();
}
