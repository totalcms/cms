<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
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
	$config = $this->app->getContainer()->get(Config::class);
	$config->oauth = array_merge($config->oauth, [
		'tokenEndpointLimit'       => 10000,
		'dynamicRegistrationLimit' => 10000,
	]);
});

// ---------------------------------------------------------------------------
// Helpers (scoped with prefix to avoid collision with other Feature test files)
// ---------------------------------------------------------------------------

/**
 * Set up RSA keys and configure the app for OAuth.
 *
 * @return array{privateKey: string, publicKey: string, tmpDir: string}
 */
function refreshSetupKeys(Slim\App $app): array
{
	$tmpDir = sys_get_temp_dir() . '/oauth-refresh-test-' . uniqid('', true);
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
 * Create and persist a test OAuth client.
 *
 * @param list<string> $redirectUris
 * @param list<string> $scopes
 */
function refreshCreateClient(
	Slim\App $app,
	string $clientId,
	string $secret,
	array $redirectUris = ['https://app.test/callback'],
	array $scopes = ['cms:read'],
): OAuthClientData {
	$client = new OAuthClientData(
		id:             $clientId,
		name:           'Refresh Test Client',
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
 * Run the full auth-code flow and return access_token + refresh_token.
 *
 * @return array{access_token: string, refresh_token: string}
 */
function refreshIssueToken(
	Slim\App $app,
	string $clientId,
	string $clientSecret,
	string $redirectUri = 'https://app.test/callback',
): array {
	$factory = new Psr17Factory();

	/** @var PhpSession $session */
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, 'admin@example.test');

	$codeVerifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

	// Step 1: GET /oauth/authorize
	$consentResponse = $app->handle(
		$factory->createServerRequest('GET', '/oauth/authorize?' . http_build_query([
			'response_type'         => 'code',
			'client_id'             => $clientId,
			'redirect_uri'          => $redirectUri,
			'scope'                 => 'cms:read',
			'state'                 => 'state-refresh-test',
			'code_challenge'        => $codeChallenge,
			'code_challenge_method' => 'S256',
		])),
	);
	assert($consentResponse->getStatusCode() === 200, 'Consent screen must return 200');

	if (!$session->isStarted()) {
		$session->start();
	}
	$csrfToken = $app->getContainer()->get(CSRFTokenManager::class)->generateToken();

	// Step 2: POST /oauth/authorize
	$approveResponse = $app->handle(
		$factory->createServerRequest('POST', '/oauth/authorize')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody(['decision' => 'approve', 'csrf_token' => $csrfToken]),
	);
	assert($approveResponse->getStatusCode() === 302, 'Approve must redirect');

	$location = $approveResponse->getHeaderLine('Location');
	parse_str((string)parse_url($location, PHP_URL_QUERY), $callbackParams);
	$code = (string)($callbackParams['code'] ?? '');
	assert($code !== '', 'Auth code must be non-empty');

	// Step 3: POST /oauth/token — exchange code for tokens
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
	assert($tokenResponse->getStatusCode() === 200, 'Token endpoint must return 200');

	/** @var array<string,mixed> $payload */
	$payload = json_decode((string)$tokenResponse->getBody(), true);
	assert(isset($payload['access_token'], $payload['refresh_token']), 'Token response must contain tokens');

	return [
		'access_token'  => (string)$payload['access_token'],
		'refresh_token' => (string)$payload['refresh_token'],
	];
}

/**
 * Call /oauth/token with a refresh_token grant and return the full response.
 */
function doRefresh(
	Slim\App $app,
	string $clientId,
	string $clientSecret,
	string $refreshToken,
): \Psr\Http\Message\ResponseInterface {
	$factory = new Psr17Factory();
	return $app->handle(
		$factory->createServerRequest('POST', '/oauth/token')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody([
				'grant_type'    => 'refresh_token',
				'client_id'     => $clientId,
				'client_secret' => $clientSecret,
				'refresh_token' => $refreshToken,
			]),
	);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('OAuthTokenRefresh', function (): void {

	// -----------------------------------------------------------------------
	// 1. Normal rotation: refresh1 → access2 + refresh2 (rotation succeeds)
	// -----------------------------------------------------------------------

	it('rotates the refresh token on first use', function (): void {
		refreshSetupKeys($this->app);

		$clientId     = 'client-rotate-' . uniqid('', true);
		$clientSecret = 'secret-rotate';
		refreshCreateClient($this->app, $clientId, $clientSecret);

		$tokens1 = refreshIssueToken($this->app, $clientId, $clientSecret);

		// Use refresh1 to get a new pair.
		$response2 = doRefresh($this->app, $clientId, $clientSecret, $tokens1['refresh_token']);

		expect($response2->getStatusCode())->toBe(200);

		/** @var array<string,mixed> $payload2 */
		$payload2 = json_decode((string)$response2->getBody(), true);
		expect($payload2)->toHaveKeys(['access_token', 'refresh_token']);
		expect($payload2['refresh_token'])->not()->toBe($tokens1['refresh_token']);
	});

	// -----------------------------------------------------------------------
	// 2. Replay detection: using a rotated-out token returns invalid_grant
	//    AND the new grant (refresh2) is also revoked (chain cascade)
	// -----------------------------------------------------------------------

	it('detects refresh-token replay and revokes the entire grant chain', function (): void {
		refreshSetupKeys($this->app);

		$clientId     = 'client-replay-' . uniqid('', true);
		$clientSecret = 'secret-replay';
		refreshCreateClient($this->app, $clientId, $clientSecret);

		// Step 1: Issue initial tokens (access1 + refresh1).
		$tokens1 = refreshIssueToken($this->app, $clientId, $clientSecret);
		$refresh1 = $tokens1['refresh_token'];

		// Step 2: Use refresh1 legitimately → get access2 + refresh2.
		$response2 = doRefresh($this->app, $clientId, $clientSecret, $refresh1);
		expect($response2->getStatusCode())->toBe(200);

		/** @var array<string,mixed> $payload2 */
		$payload2 = json_decode((string)$response2->getBody(), true);
		expect($payload2)->toHaveKey('refresh_token');
		$refresh2 = (string)$payload2['refresh_token'];

		// Verify the new grant (refresh2) is active in the repository.
		/** @var OAuthGrantRepository $grantRepo */
		$grantRepo = $this->app->getContainer()->get(OAuthGrantRepository::class);
		$grantsBeforeReplay = $grantRepo->findByClientId($clientId);
		expect($grantsBeforeReplay)->not()->toBeEmpty();

		// Step 3: Attacker replays refresh1 (which was rotated out in step 2).
		//         Must return 400 invalid_grant.
		$replayResponse = doRefresh($this->app, $clientId, $clientSecret, $refresh1);
		expect($replayResponse->getStatusCode())->toBe(400);

		$replayBody = json_decode((string)$replayResponse->getBody(), true);
		expect($replayBody)->toBeArray();
		expect($replayBody)->toHaveKey('error');

		// Step 4: Verify that the cascade also revoked refresh2 (the legitimate
		//         token that was active when the replay was detected). This is the
		//         proof-of-correctness: all grants for this client+user are gone.
		$grantsAfterReplay = $grantRepo->findByClientId($clientId);
		expect($grantsAfterReplay)->toBeEmpty();

		// Step 5: Confirm that refresh2 is now also rejected (grant is gone).
		$refresh2Response = doRefresh($this->app, $clientId, $clientSecret, $refresh2);
		expect($refresh2Response->getStatusCode())->toBe(400);
	});

});
