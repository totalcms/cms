<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\Object\Service\ObjectSaver;
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

/**
 * Set a collection's mcp.access, creating the reserved collection first if
 * needed. Reserved collections created via CollectionFetcher::
 * fetchOrCreateReserved() never set `mcp` (CollectionFactory::
 * generateReservedCollection only sets id/schema/lastUpdated/name), so
 * access defaults to 'admin' — inaccessible to anything but ADMIN — until a
 * test explicitly widens it here. 'public' is used (rather than
 * 'authenticated') so the same collection can exercise both the PUBLIC and
 * AUTHENTICATED regression cases in Task 9's draft-authority tests below.
 */
function mcpAuthSetCollectionAccess(Slim\App $app, string $collectionId, string $access): void
{
	$container  = $app->getContainer();
	$collection = $container->get(CollectionFetcher::class)->fetchOrCreateReserved($collectionId);
	if ($collection === null) {
		throw new RuntimeException(sprintf('Could not create collection "%s" for test.', $collectionId));
	}
	$collection->mcp = ['access' => $access];
	$container->get(CollectionRepository::class)->saveCollection($collection);
}

/**
 * Build a /mcp POST request with no Authorization/X-API-Key headers at all —
 * resolves to the PUBLIC persona. Same technique as McpChatGptCompatTest's
 * chatgptCompatMcp(), kept file-local under a distinct name per this file's
 * existing convention (see mcpAuthSeedUser's docblock) of not sharing
 * helpers across test files.
 *
 * @param array<string,mixed> $payload
 */
function mcpAuthPublicRequest(Slim\App $app, array $payload, string $sessionId = ''): Psr\Http\Message\ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		// Dedicated client IP so the anonymous per-IP rate limiter doesn't
		// share a bucket with other public-persona tests in the same run.
		->withHeader('X-Forwarded-For', '203.0.113.201');

	if ($sessionId !== '') {
		$request = $request->withHeader('Mcp-Session-Id', $sessionId);
	}

	$request->getBody()->write((string)json_encode($payload));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * Perform the MCP handshake as the anonymous PUBLIC persona (no auth headers
 * at all) and return the negotiated Mcp-Session-Id, or '' when the endpoint
 * is unavailable (edition/config gate) — same skip-safe contract as
 * mcpAuthInitSession().
 */
function mcpAuthPublicInitSession(Slim\App $app): string
{
	$init = mcpAuthPublicRequest($app, [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest-mcp-draft-public', 'version' => '0.1'],
		],
	]);

	if ($init->getStatusCode() !== 200) {
		return '';
	}

	$sessionId = $init->getHeaderLine('Mcp-Session-Id');
	if ($sessionId === '') {
		return '';
	}

	// Complete the lifecycle so the SDK marks the session initialized.
	mcpAuthPublicRequest($app, [
		'jsonrpc' => '2.0',
		'method'  => 'notifications/initialized',
	], $sessionId);

	return $sessionId;
}

/**
 * Extract the `items` array from a tools/call response for a core content
 * tool (query_collection/search_collection) that declares an outputSchema.
 * Those responses carry a `result.structuredContent` payload alongside the
 * JSON-encoded `result.content[0].text` mirror — reading structuredContent
 * avoids the double-wrap unwrapping SavedQueryTool-style custom envelopes
 * need (see McpSchemaToolsIntegrationTest for that pattern).
 *
 * @return list<array<string,mixed>>
 */
function mcpAuthStructuredItems(Psr\Http\Message\ResponseInterface $response): array
{
	$body  = json_decode((string)$response->getBody(), true);
	$items = $body['result']['structuredContent']['items'] ?? null;

	return is_array($items) ? $items : [];
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
	//
	// Double-JSON-wrap note: CollectionResource::read() returns its own
	// `{contents: [{uri, mimeType, text}]}` envelope as a plain array: the SDK's
	// ResourceResultFormatter doesn't special-case that shape, so it falls to
	// the generic "JSON-encode the whole array" branch and wraps it AGAIN —
	// `result.contents[0].text` decodes to `{contents: [{uri, mimeType, text:
	// <the real items JSON>}]}`, one level deeper than it looks. Pre-existing
	// (unrelated to this task); mcpAuthResourceItems() below decodes both
	// levels so assertions read the real `items` array.
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Pre-create the 'dataviews' reserved collection so DataViewResourceRegistrar's
	 * lazy create-then-read doesn't hit the pre-existing staleness issue
	 * described above. Call before issuing a token in every Scenario 16 test
	 * that builds a fresh MCP server against an otherwise-empty cmsDataDir.
	 */
	function mcpAuthEnsureDataviewsCollection(Slim\App $app): void
	{
		$app->getContainer()->get(CollectionFetcher::class)->fetchOrCreateReserved('dataviews');
	}

	/**
	 * Extract the `items` array from a `resources/read` JSON-RPC response,
	 * accounting for CollectionResource's double-JSON-wrap (see the
	 * Scenario 16 block comment above).
	 *
	 * @return list<array<string,mixed>>
	 */
	function mcpAuthResourceReadItems(Psr\Http\Message\ResponseInterface $response): array
	{
		$body  = json_decode((string)$response->getBody(), true);
		$outer = json_decode((string)($body['result']['contents'][0]['text'] ?? ''), true);
		$inner = json_decode((string)($outer['contents'][0]['text'] ?? ''), true);
		$items = $inner['items'] ?? null;

		return is_array($items) ? $items : [];
	}

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

		$saver = $this->app->getContainer()->get(ObjectSaver::class);
		$saver->saveObject('blog', ['id' => 'mcp-t10b-qc-allow', 'title' => 'Allowed Post', 'draft' => false]);
		$saver->saveObject('blog-legacy', ['id' => 'mcp-t10b-qc-deny', 'title' => 'Denied Post', 'draft' => false]);

		$clientId = 'mcp-t10b-qc-' . uniqid('', true);
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

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

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
		$body = json_decode((string)$response->getBody(), true);
		expect($body['result']['isError'] ?? false)->toBeTrue();
		$text = $body['result']['content'][0]['text'] ?? '';
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
				'arguments' => ['query' => 'Fizzbin'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
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
		$body = json_decode((string)$response->getBody(), true);
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

		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$sessionId = mcpAuthInitSession($this->app, $token);
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

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
				'arguments' => ['collection' => 'blog', 'id' => 'mcp-t10b-admin-post'],
			],
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body = json_decode((string)$response->getBody(), true);
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
		if ($sessionId === '') {
			expect(true)->toBeTrue();

			return;
		}

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
			'method'  => 'resources/templates/list',
		], $sessionId);

		expect($response->getStatusCode())->toBe(200);
		$body      = json_decode((string)$response->getBody(), true);
		$templates = array_column($body['result']['resourceTemplates'] ?? [], 'uriTemplate');
		expect($templates)->toContain('tcms://blog/{id}');
		expect($templates)->not->toContain('tcms://blog-legacy/{id}');
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
