<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
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
});

// ---------------------------------------------------------------------------
// Helper functions
// ---------------------------------------------------------------------------

/**
 * Generate an RSA key pair, write both PEM files to a temp directory, and
 * update $config->oauth so OAuthServerFactory picks them up.
 *
 * @return array{privateKey: string, publicKey: string, tmpDir: string}
 */
function setupOAuthKeys(Slim\App $app): array
{
	$tmpDir = sys_get_temp_dir() . '/oauth-test-' . uniqid('', true);
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
 * Create and persist a test OAuth client via OAuthClientRepository.
 *
 * @param list<string> $redirectUris
 * @param list<string> $scopes
 */
function createTestClient(
	Slim\App $app,
	string $clientId,
	string $secret,
	array $redirectUris,
	array $scopes,
): OAuthClientData {
	$client = new OAuthClientData(
		id:             $clientId,
		name:           'Test Client',
		secretHash:     password_hash($secret, PASSWORD_BCRYPT),
		redirectUris:   $redirectUris,
		scopes:         $scopes,
		isDynamic:      false,
		isConfidential: true,
		createdAt:      gmdate('c'),
		createdBy:      'test',
	);
	$app->getContainer()->get(OAuthClientRepository::class)->save($client);
	return $client;
}

/**
 * Ensure the PhpSession has been started and seed it with the AUTH_USER key.
 * After calling handle(), SessionStartMiddleware calls session_write_close().
 * Call this again before each subsequent handle() to reopen the session so
 * the stored oauth_authorize_request stash survives from GET → POST.
 */
function seedSessionUser(Slim\App $app, string $userId): PhpSession
{
	/** @var PhpSession $session */
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, $userId);
	return $session;
}

/**
 * Re-open the session after SessionStartMiddleware has closed it.
 * All previously stored session data (oauth_authorize_request stash) will
 * be accessible again once the session is restarted with the same ID.
 */
function reopenSession(Slim\App $app): PhpSession
{
	/** @var PhpSession $session */
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	return $session;
}

// ---------------------------------------------------------------------------
// Happy-path: full authorization-code flow with PKCE
// ---------------------------------------------------------------------------

describe('OAuthAuthorizationCodeFlow', function (): void {

	it('completes the full authorization-code flow with PKCE', function (): void {
		setupOAuthKeys($this->app);

		$clientId     = 'test-client-' . uniqid('', true);
		$clientSecret = 'test-secret-value';
		$redirectUri  = 'https://app.test/callback';

		createTestClient($this->app, $clientId, $clientSecret, [$redirectUri], ['cms:read']);

		// Seed AUTH_USER into the session before the first handle() call.
		// SessionStartMiddleware checks isStarted() — since we started it here,
		// it won't call start() again, so $this->storage stays pointing at $_SESSION.
		$session = seedSessionUser($this->app, 'admin@example.test');

		// PKCE pair
		$codeVerifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

		// ------------------------------------------------------------------
		// Step 1: GET /oauth/authorize → consent screen
		// ------------------------------------------------------------------
		$authorizeUrl = '/oauth/authorize?' . http_build_query([
			'response_type'         => 'code',
			'client_id'             => $clientId,
			'redirect_uri'          => $redirectUri,
			'scope'                 => 'cms:read',
			'state'                 => 'state-abc',
			'code_challenge'        => $codeChallenge,
			'code_challenge_method' => 'S256',
		]);

		$factory         = new Psr17Factory();
		$consentResponse = $this->app->handle(
			$factory->createServerRequest('GET', $authorizeUrl),
		);

		expect($consentResponse->getStatusCode())->toBe(200);
		expect((string)$consentResponse->getBody())->toContain('Test Client');

		// SessionStartMiddleware called session_write_close() at end of request.
		// Re-open the session so the oauth_authorize_request stash is accessible
		// for the POST handler, and keep AUTH_USER alive.
		$session = reopenSession($this->app);

		// ------------------------------------------------------------------
		// Step 2: POST /oauth/authorize with decision=approve
		// → 302 redirect with code + state
		// ------------------------------------------------------------------
		$approveResponse = $this->app->handle(
			$factory->createServerRequest('POST', '/oauth/authorize')
				->withHeader('Content-Type', 'application/x-www-form-urlencoded')
				->withParsedBody(['decision' => 'approve']),
		);

		expect($approveResponse->getStatusCode())->toBe(302);

		$location = $approveResponse->getHeaderLine('Location');
		expect($location)->toStartWith($redirectUri);

		parse_str((string)parse_url($location, PHP_URL_QUERY), $callbackParams);
		expect($callbackParams)->toHaveKey('code');
		expect($callbackParams['state'] ?? '')->toBe('state-abc');

		$code = (string)$callbackParams['code'];

		// ------------------------------------------------------------------
		// Step 3: POST /oauth/token — exchange code for tokens
		// Token endpoint does not require session auth, just client credentials.
		// ------------------------------------------------------------------
		$tokenResponse = $this->app->handle(
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

		expect($tokenResponse->getStatusCode())->toBe(200);

		$tokenPayload = json_decode((string)$tokenResponse->getBody(), true);
		expect($tokenPayload)->toBeArray();
		expect($tokenPayload)->toHaveKeys(['access_token', 'refresh_token', 'token_type', 'expires_in']);
		expect($tokenPayload['token_type'])->toBe('Bearer');
		expect($tokenPayload['access_token'])->toBeString()->not()->toBeEmpty();
		expect($tokenPayload['refresh_token'])->toBeString()->not()->toBeEmpty();
	});

	// -----------------------------------------------------------------------
	// PKCE rejection: wrong code_verifier
	// -----------------------------------------------------------------------

	it('rejects token exchange with wrong code_verifier', function (): void {
		setupOAuthKeys($this->app);

		$clientId     = 'test-client-pkce-' . uniqid('', true);
		$clientSecret = 'test-secret-pkce';
		$redirectUri  = 'https://app.test/callback';

		createTestClient($this->app, $clientId, $clientSecret, [$redirectUri], ['cms:read']);

		seedSessionUser($this->app, 'admin@example.test');

		$codeVerifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

		$factory = new Psr17Factory();

		// Step 1 — GET consent
		$consentResponse = $this->app->handle(
			$factory->createServerRequest('GET', '/oauth/authorize?' . http_build_query([
				'response_type'         => 'code',
				'client_id'             => $clientId,
				'redirect_uri'          => $redirectUri,
				'scope'                 => 'cms:read',
				'state'                 => 'state-xyz',
				'code_challenge'        => $codeChallenge,
				'code_challenge_method' => 'S256',
			])),
		);
		expect($consentResponse->getStatusCode())->toBe(200);

		// Re-open session for step 2.
		reopenSession($this->app);

		// Step 2 — approve
		$approveResponse = $this->app->handle(
			$factory->createServerRequest('POST', '/oauth/authorize')
				->withHeader('Content-Type', 'application/x-www-form-urlencoded')
				->withParsedBody(['decision' => 'approve']),
		);
		expect($approveResponse->getStatusCode())->toBe(302);

		parse_str((string)parse_url($approveResponse->getHeaderLine('Location'), PHP_URL_QUERY), $cb);
		$code = (string)($cb['code'] ?? '');
		expect($code)->not()->toBeEmpty();

		// Step 3 — token with WRONG verifier
		$tokenResponse = $this->app->handle(
			$factory->createServerRequest('POST', '/oauth/token')
				->withHeader('Content-Type', 'application/x-www-form-urlencoded')
				->withParsedBody([
					'grant_type'    => 'authorization_code',
					'client_id'     => $clientId,
					'client_secret' => $clientSecret,
					'redirect_uri'  => $redirectUri,
					'code'          => $code,
					'code_verifier' => 'this-is-the-wrong-verifier-value',
				]),
		);

		expect($tokenResponse->getStatusCode())->toBe(400);
		$payload = json_decode((string)$tokenResponse->getBody(), true);
		expect($payload)->toBeArray();
		expect($payload)->toHaveKey('error');
	});

	// -----------------------------------------------------------------------
	// PKCE enforcement: public clients require code_challenge
	//
	// League's AuthCodeGrant only requires PKCE for PUBLIC clients
	// (isConfidential = false). Confidential clients may omit it.
	// -----------------------------------------------------------------------

	it('rejects authorize request without code_challenge for a public client', function (): void {
		setupOAuthKeys($this->app);

		$clientId    = 'test-public-client-' . uniqid('', true);
		$redirectUri = 'https://app.test/callback';

		// Public client: isConfidential = false
		$client = new OAuthClientData(
			id:             $clientId,
			name:           'Public Test Client',
			secretHash:     '',
			redirectUris:   [$redirectUri],
			scopes:         ['cms:read'],
			isDynamic:      false,
			isConfidential: false,
			createdAt:      gmdate('c'),
			createdBy:      'test',
		);
		$this->app->getContainer()->get(OAuthClientRepository::class)->save($client);

		seedSessionUser($this->app, 'admin@example.test');

		$factory = new Psr17Factory();

		// No code_challenge → league rejects because this is a public client.
		$response = $this->app->handle(
			$factory->createServerRequest('GET', '/oauth/authorize?' . http_build_query([
				'response_type' => 'code',
				'client_id'     => $clientId,
				'redirect_uri'  => $redirectUri,
				'scope'         => 'cms:read',
				'state'         => 'state-nochallenge',
				// Intentionally omitting code_challenge + code_challenge_method
			])),
		);

		// The authorize action catches OAuthServerException and renders an error page
		// (it does NOT redirect — the redirect_uri could be attacker-controlled).
		// Expect either a 4xx status or a redirect with `error=` (not `code=`).
		if ($response->getStatusCode() === 302) {
			$loc = $response->getHeaderLine('Location');
			parse_str((string)parse_url($loc, PHP_URL_QUERY), $params);
			// If redirected, must carry `error=` not `code=`
			expect($params)->toHaveKey('error');
			expect($params)->not()->toHaveKey('code');
		} else {
			expect($response->getStatusCode())->toBeIn([400, 401, 403]);
		}
	});

	// -----------------------------------------------------------------------
	// Discovery endpoints are reachable
	// -----------------------------------------------------------------------

	it('serves OAuth discovery metadata at /.well-known/oauth-authorization-server', function (): void {
		setupOAuthKeys($this->app);

		$factory  = new Psr17Factory();
		$response = $this->app->handle(
			$factory->createServerRequest('GET', '/.well-known/oauth-authorization-server'),
		);

		expect($response->getStatusCode())->toBe(200);
		$payload = json_decode((string)$response->getBody(), true);
		expect($payload)->toBeArray();
		expect($payload)->toHaveKeys(['issuer', 'authorization_endpoint', 'token_endpoint']);
	});

	it('serves JWKS at /.well-known/jwks.json', function (): void {
		setupOAuthKeys($this->app);

		$factory  = new Psr17Factory();
		$response = $this->app->handle(
			$factory->createServerRequest('GET', '/.well-known/jwks.json'),
		);

		expect($response->getStatusCode())->toBe(200);
		$payload = json_decode((string)$response->getBody(), true);
		expect($payload)->toBeArray();
		expect($payload)->toHaveKey('keys');
		expect($payload['keys'])->toBeArray();
	});
});
