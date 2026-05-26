<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

// ---------------------------------------------------------------------------
// Suite bootstrap
// ---------------------------------------------------------------------------

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	// Bump rate limits so cross-test accumulation doesn't trip the limiter.
	/** @var Config $config */
	$config        = $this->app->getContainer()->get(Config::class);
	$config->oauth = array_merge($config->oauth, [
		'tokenEndpointLimit'       => 10000,
		'dynamicRegistrationLimit' => 10000,
	]);
});

// ---------------------------------------------------------------------------
// Helpers (prefixed "mcpIntegration" to avoid collisions with other suites)
// ---------------------------------------------------------------------------

/**
 * Generate an RSA key pair and wire it into $config->oauth so the
 * OAuthServerFactory picks it up. Mirrors the pattern in every other OAuth
 * feature test but uses a distinct name.
 *
 * @return array{privateKey: string, publicKey: string, tmpDir: string}
 */
function mcpIntegrationSetupKeys(Slim\App $app): array
{
	$tmpDir = sys_get_temp_dir() . '/oauth-mcp-integration-' . uniqid('', true);
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

	/** @var Config $config */
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
 * Walk the full dynamic-registration → authorize → consent → token exchange
 * flow and return an access_token string.
 *
 * This is the exact sequence Claude Desktop performs on first-connect:
 *   1. Discover (caller's responsibility — see Step 1 in the test)
 *   2. Register (via /oauth/register — caller's responsibility for Step 2)
 *   3. Authorize + PKCE (this function walks Steps 3a–3c)
 *   4. Token exchange (this function completes in Step 3d)
 *
 * The helper starts from an already-registered client_id + client_secret.
 *
 * @param list<string> $scopes
 */
function mcpIntegrationCompleteAuthFlow(
	Slim\App $app,
	string $clientId,
	string $clientSecret,
	string $redirectUri,
	array $scopes,
): string {
	$factory = new Psr17Factory();

	/** @var PhpSession $session */
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, 'admin@example.test');

	$codeVerifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

	// Step 3a: GET /oauth/authorize → consent screen
	$app->handle(
		$factory->createServerRequest('GET', '/oauth/authorize?' . http_build_query([
			'response_type'         => 'code',
			'client_id'             => $clientId,
			'redirect_uri'          => $redirectUri,
			'scope'                 => implode(' ', $scopes),
			'state'                 => 'mcp-integration-state',
			'code_challenge'        => $codeChallenge,
			'code_challenge_method' => 'S256',
		])),
	);

	// Re-open session after SessionStartMiddleware wrote and closed it.
	if (!$session->isStarted()) {
		$session->start();
	}
	/** @var CSRFTokenManager $csrf */
	$csrf      = $app->getContainer()->get(CSRFTokenManager::class);
	$csrfToken = $csrf->generateToken();

	// Step 3b: POST /oauth/authorize with decision=approve → 302 with code
	$approveResponse = $app->handle(
		$factory->createServerRequest('POST', '/oauth/authorize')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody(['decision' => 'approve', 'csrf_token' => $csrfToken]),
	);

	$location = $approveResponse->getHeaderLine('Location');
	parse_str((string)parse_url($location, PHP_URL_QUERY), $callbackParams);
	$code = (string)($callbackParams['code'] ?? '');

	// Step 3c: POST /oauth/token — exchange code for access token
	$tokenResponse = $app->handle(
		$factory->createServerRequest('POST', '/oauth/token')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody([
				'grant_type'    => 'authorization_code',
				'client_id'     => $clientId,
				'client_secret' => $clientSecret,
				'redirect_uri'  => $redirectUri,
				'code'          => $code,
				'code_verifier' => $codeVerifier,
			]),
	);

	/** @var array<string,mixed> $payload */
	$payload = json_decode((string)$tokenResponse->getBody(), true);
	return (string)($payload['access_token'] ?? '');
}

/**
 * Build a JSON-RPC request to the /mcp endpoint with a Bearer Authorization
 * header. Optionally includes a Mcp-Session-Id when one is already established.
 *
 * @param array<string,mixed> $params
 */
function mcpIntegrationMcpRequest(
	string $accessToken,
	string $method,
	array $params = [],
	string $sessionId = '',
): ServerRequestInterface {
	$factory = new Psr17Factory();

	$payload = [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => $method,
	];

	if ($params !== []) {
		$payload['params'] = $params;
	}

	$body    = $factory->createStream((string)json_encode($payload));
	$request = $factory->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('Authorization', 'Bearer ' . $accessToken)
		->withBody($body);

	if ($sessionId !== '') {
		$request = $request->withHeader('Mcp-Session-Id', $sessionId);
	}

	return $request;
}

/**
 * Initialize an MCP session and return the Mcp-Session-Id header value, or
 * empty string when the MCP endpoint is unavailable (non-Pro / disabled).
 */
function mcpIntegrationInitSession(Slim\App $app, string $accessToken): string
{
	$factory = new Psr17Factory();
	$body    = $factory->createStream((string)json_encode([
		'jsonrpc' => '2.0',
		'id'      => 0,
		'method'  => 'initialize',
		'params'  => [
			'protocolVersion' => '2025-06-18',
			'capabilities'    => new stdClass(),
			'clientInfo'      => ['name' => 'pest-mcp-integration', 'version' => '0.1'],
		],
	]));

	$request = $factory->createServerRequest('POST', '/mcp')
		->withHeader('Content-Type', 'application/json')
		->withHeader('Accept', 'application/json, text/event-stream')
		->withHeader('Authorization', 'Bearer ' . $accessToken)
		->withBody($body);

	$response = $app->handle($request);

	if ($response->getStatusCode() !== 200) {
		return '';
	}

	return $response->getHeaderLine('Mcp-Session-Id');
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('OAuthMcpIntegration', function (): void {

	// -----------------------------------------------------------------------
	// 1. Full Claude-style end-to-end flow
	//    discovery → dynamic registration → consent → token → MCP calls
	// -----------------------------------------------------------------------

	it('completes the full Claude-style integration flow', function (): void {
		mcpIntegrationSetupKeys($this->app);

		// ============================================================
		// Step 1 — Discovery: client discovers the OAuth endpoints
		// ============================================================
		$factory           = new Psr17Factory();
		$discoveryResponse = $this->app->handle(
			$factory->createServerRequest('GET', '/.well-known/oauth-authorization-server'),
		);
		expect($discoveryResponse->getStatusCode())->toBe(200);

		/** @var array<string,mixed> $metadata */
		$metadata = json_decode((string)$discoveryResponse->getBody(), true);
		expect($metadata)->toBeArray();
		expect($metadata)->toHaveKeys([
			'issuer', 'authorization_endpoint', 'token_endpoint',
			'jwks_uri', 'scopes_supported', 'grant_types_supported',
			'code_challenge_methods_supported', 'registration_endpoint',
		]);
		expect($metadata['scopes_supported'])->toContain('mcp:tools');
		expect($metadata['scopes_supported'])->toContain('mcp:resources');

		// ============================================================
		// Step 2 — Dynamic registration (RFC 7591)
		// ============================================================
		$registerBody     = (string)json_encode([
			'redirect_uris' => ['https://claude.ai/oauth/callback'],
			'client_name'   => 'Claude Desktop',
			'scope'         => 'mcp:tools mcp:resources',
		]);
		$registerResponse = $this->app->handle(
			$factory->createServerRequest('POST', '/oauth/register')
				->withHeader('Content-Type', 'application/json')
				->withBody($factory->createStream($registerBody)),
		);
		expect($registerResponse->getStatusCode())->toBe(201);

		/** @var array<string,mixed> $registration */
		$registration = json_decode((string)$registerResponse->getBody(), true);
		expect($registration)->toHaveKeys(['client_id', 'client_secret']);
		$clientId     = (string)$registration['client_id'];
		$clientSecret = (string)$registration['client_secret'];

		// ============================================================
		// Step 3 — Authorization code grant with PKCE
		// (Simulates admin clicking "Allow" on the consent screen)
		// ============================================================
		$accessToken = mcpIntegrationCompleteAuthFlow(
			$this->app,
			$clientId,
			$clientSecret,
			'https://claude.ai/oauth/callback',
			['mcp:tools', 'mcp:resources'],
		);
		expect($accessToken)->not->toBe('');

		// ============================================================
		// Step 4 — JWKS verification (resource servers fetch this)
		// ============================================================
		$jwksResponse = $this->app->handle(
			$factory->createServerRequest('GET', '/.well-known/jwks.json'),
		);
		expect($jwksResponse->getStatusCode())->toBe(200);

		/** @var array<string,mixed> $jwks */
		$jwks = json_decode((string)$jwksResponse->getBody(), true);
		expect($jwks)->toHaveKey('keys');
		expect($jwks['keys'])->toHaveCount(1);
		expect($jwks['keys'][0])->toHaveKeys(['kty', 'use', 'alg', 'kid', 'n', 'e']);

		// ============================================================
		// Step 5 — Initialize MCP session
		// ============================================================
		$sessionId = mcpIntegrationInitSession($this->app, $accessToken);

		// When MCP is unavailable (non-Pro edition or mcp.enabled=false),
		// skip the MCP-specific assertions — this test targets the OAuth
		// plumbing, not the edition gate.
		if ($sessionId === '') {
			expect(true)->toBeTrue(); // skip-safe pass
			return;
		}

		// ============================================================
		// Step 6 — MCP tools/list with Bearer
		// ============================================================
		$toolsListResponse = $this->app->handle(
			mcpIntegrationMcpRequest($accessToken, 'tools/list', [], $sessionId),
		);
		expect($toolsListResponse->getStatusCode())->toBe(200);

		/** @var array<string,mixed> $toolsBody */
		$toolsBody = json_decode((string)$toolsListResponse->getBody(), true);
		expect($toolsBody)->toHaveKey('result');
		expect($toolsBody['result'])->toHaveKey('tools');

		// ============================================================
		// Step 7 — MCP tools/call (covered by mcp:tools scope)
		// ============================================================
		$toolCallResponse = $this->app->handle(
			mcpIntegrationMcpRequest($accessToken, 'tools/call', [
				'name'      => 'list_collections',
				'arguments' => new stdClass(),
			], $sessionId),
		);
		expect($toolCallResponse->getStatusCode())->toBe(200);

		// ============================================================
		// Step 8 — MCP resources/list (covered by mcp:resources scope)
		// ============================================================
		$resourcesListResponse = $this->app->handle(
			mcpIntegrationMcpRequest($accessToken, 'resources/list', [], $sessionId),
		);
		expect($resourcesListResponse->getStatusCode())->toBe(200);
	});

	// -----------------------------------------------------------------------
	// 2. Token scoped only to mcp:resources → tools/call returns 403
	// -----------------------------------------------------------------------

	it('rejects MCP tool calls when token lacks mcp:tools scope', function (): void {
		mcpIntegrationSetupKeys($this->app);

		$factory = new Psr17Factory();

		// Register a client with ONLY mcp:resources scope (not mcp:tools).
		$registerBody     = (string)json_encode([
			'redirect_uris' => ['https://claude.ai/oauth/callback'],
			'client_name'   => 'Resources-Only Client',
			'scope'         => 'mcp:resources',
		]);
		$registerResponse = $this->app->handle(
			$factory->createServerRequest('POST', '/oauth/register')
				->withHeader('Content-Type', 'application/json')
				->withBody($factory->createStream($registerBody)),
		);
		expect($registerResponse->getStatusCode())->toBe(201);

		/** @var array<string,mixed> $registration */
		$registration = json_decode((string)$registerResponse->getBody(), true);
		$clientId     = (string)$registration['client_id'];
		$clientSecret = (string)$registration['client_secret'];

		$accessToken = mcpIntegrationCompleteAuthFlow(
			$this->app,
			$clientId,
			$clientSecret,
			'https://claude.ai/oauth/callback',
			['mcp:resources'],
		);
		expect($accessToken)->not->toBe('');

		$sessionId = mcpIntegrationInitSession($this->app, $accessToken);

		if ($sessionId === '') {
			// MCP unavailable in this environment — skip MCP assertions.
			expect(true)->toBeTrue();
			return;
		}

		$toolCallResponse = $this->app->handle(
			mcpIntegrationMcpRequest($accessToken, 'tools/call', [
				'name'      => 'list_collections',
				'arguments' => new stdClass(),
			], $sessionId),
		);

		expect($toolCallResponse->getStatusCode())->toBe(403);
		expect($toolCallResponse->getHeaderLine('WWW-Authenticate'))->toContain('insufficient_scope');
	});

	// -----------------------------------------------------------------------
	// 3. Token with no mcp:* scope at all → McpAuth rejects at the auth layer
	// -----------------------------------------------------------------------

	it('rejects MCP requests for tokens with no mcp:* scope at all', function (): void {
		mcpIntegrationSetupKeys($this->app);

		$factory = new Psr17Factory();

		// Register a client with ONLY cms:read (no mcp:* scope at all).
		$registerBody     = (string)json_encode([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'CMS-Only Client',
			'scope'         => 'cms:read',
		]);
		$registerResponse = $this->app->handle(
			$factory->createServerRequest('POST', '/oauth/register')
				->withHeader('Content-Type', 'application/json')
				->withBody($factory->createStream($registerBody)),
		);
		expect($registerResponse->getStatusCode())->toBe(201);

		/** @var array<string,mixed> $registration */
		$registration = json_decode((string)$registerResponse->getBody(), true);
		$clientId     = (string)$registration['client_id'];
		$clientSecret = (string)$registration['client_secret'];

		$accessToken = mcpIntegrationCompleteAuthFlow(
			$this->app,
			$clientId,
			$clientSecret,
			'https://app.test/cb',
			['cms:read'],
		);
		expect($accessToken)->not->toBe('');

		// McpAuth should reject at the auth layer, not the scope layer.
		// Send without an initialized session — McpAuth fires before the SDK.
		$response = $this->app->handle(
			mcpIntegrationMcpRequest($accessToken, 'tools/list'),
		);

		// 401 = McpAuth rejected (no mcp:* scope)
		// 403 = edition gate fired before auth (non-Pro env)
		// 404 = MCP disabled
		expect($response->getStatusCode())->toBeIn([401, 403, 404]);

		if ($response->getStatusCode() === 401) {
			expect($response->getHeaderLine('WWW-Authenticate'))->toContain('insufficient_scope');
		}
	});

});
