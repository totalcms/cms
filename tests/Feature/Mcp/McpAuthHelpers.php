<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

/**
 * Shared setup for the McpAuthenticatedPersona suites.
 *
 * These live here rather than inside a test file because four suites need
 * them, and a global function declared in one *Test.php is only visible to
 * another when both happen to load into the same process — true for a serial
 * run, false under `pest --parallel`, where the files land in different
 * workers.
 *
 * The names are deliberately distinct from the helpers in the other MCP test
 * files, which are file-local by design.
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
		dirname(__DIR__, 2) . '/tcms-data-fixtures/auth/' . $fixtureId . '.json',
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
		dirname(__DIR__, 2) . '/tcms-data-fixtures/.system/access-groups.json',
		$systemDir . '/access-groups.json',
	);
}

/**
 * Walk the full authorization-code + PKCE flow and return an access token
 * with the given scopes.
 *
 * @param list<string> $scopes
 */
function mcpAuthIssueToken(Slim\App $app, string $clientId, string $clientSecret, array $scopes, string $userId = 'admin@example.test', string $collection = 'auth'): string
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
	$session->set(SessionKeys::AUTH_COLLECTION, $collection);

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
	// `resource` must be opted into explicitly — an unset value means off, so a
	// collection built for a resources/* test has to ask for the exposure the
	// way a real one does.
	$collection->mcp = ['access' => $access, 'resource' => true];
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
 * is the direct route to the data (same shape saved-query tools now return
 * too — see McpSchemaToolsIntegrationTest).
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

/**
 * @param list<string> $scopes
 *
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
 * Extract the `items` array from a `resources/read` JSON-RPC response.
 *
 * One decode, not two. This used to unwrap a second envelope because the
 * resource handlers returned a pre-built `{contents: [...]}` result that the
 * SDK then wrapped again — the payload sat two levels deep and this helper
 * absorbed the difference, which is how the bug survived a suite that
 * exercised it. The handlers now return flat content, so a single decode is
 * both correct and the assertion that keeps it that way: reintroduce the
 * envelope and these tests fail.
 *
 * @return list<array<string,mixed>>
 */
function mcpAuthResourceReadItems(Psr\Http\Message\ResponseInterface $response): array
{
	$body    = json_decode((string)$response->getBody(), true);
	$payload = json_decode((string)($body['result']['contents'][0]['text'] ?? ''), true);
	$items   = $payload['items'] ?? null;

	return is_array($items) ? $items : [];
}
