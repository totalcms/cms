<?php

declare(strict_types=1);

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\RegisteredClaims;
use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthRevocationList;
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
// Helpers (reused from OAuthAuthorizationCodeFlowTest via local copies)
// ---------------------------------------------------------------------------

/**
 * Set up RSA keys and configure the app for OAuth.
 *
 * @return array{privateKey: string, publicKey: string, tmpDir: string}
 */
function revokeSetupOAuthKeys(Slim\App $app): array
{
	$tmpDir = sys_get_temp_dir() . '/oauth-revoke-test-' . uniqid('', true);
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
function revokeCreateTestClient(
	Slim\App $app,
	string $clientId,
	string $secret,
	array $redirectUris = ['https://app.test/callback'],
	array $scopes = ['cms:read'],
): OAuthClientData {
	$client = new OAuthClientData(
		id:             $clientId,
		name:           'Revoke Test Client',
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
function revokeIssueTokens(
	Slim\App $app,
	string $clientId,
	string $clientSecret,
	string $redirectUri = 'https://app.test/callback',
): array {
	$factory = new Psr17Factory();

	// Seed session user.
	/** @var PhpSession $session */
	$session = $app->getContainer()->get(PhpSession::class);
	if (!$session->isStarted()) {
		$session->start();
	}
	$session->set(SessionKeys::AUTH_USER, 'admin@example.test');

	// PKCE pair.
	$codeVerifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

	// Step 1: GET /oauth/authorize
	$authorizeUrl = '/oauth/authorize?' . http_build_query([
		'response_type'         => 'code',
		'client_id'             => $clientId,
		'redirect_uri'          => $redirectUri,
		'scope'                 => 'cms:read',
		'state'                 => 'state-test',
		'code_challenge'        => $codeChallenge,
		'code_challenge_method' => 'S256',
	]);
	$consentResponse = $app->handle(
		$factory->createServerRequest('GET', $authorizeUrl),
	);
	assert($consentResponse->getStatusCode() === 200, 'Consent screen should return 200');

	// Re-open session between requests.
	if (!$session->isStarted()) {
		$session->start();
	}

	// Mint a CSRF token bound to the now-active session. CSRFProtectionMiddleware
	// sits on POST /oauth/authorize and rejects requests without a valid token.
	$csrfToken = $app->getContainer()->get(CSRFTokenManager::class)->generateToken();

	// Step 2: POST /oauth/authorize to approve.
	$approveResponse = $app->handle(
		$factory->createServerRequest('POST', '/oauth/authorize')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody(['decision' => 'approve', 'csrf_token' => $csrfToken]),
	);
	assert($approveResponse->getStatusCode() === 302, 'Approve should redirect');

	$location = $approveResponse->getHeaderLine('Location');
	parse_str((string)parse_url($location, PHP_URL_QUERY), $callbackParams);
	$code = (string)($callbackParams['code'] ?? '');
	assert($code !== '', 'Auth code must be non-empty');

	// Step 3: POST /oauth/token to exchange code.
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
	assert($tokenResponse->getStatusCode() === 200, 'Token endpoint should return 200');

	/** @var array<string,mixed> $payload */
	$payload = json_decode((string)$tokenResponse->getBody(), true);
	assert(isset($payload['access_token'], $payload['refresh_token']), 'Token response must contain tokens');

	return [
		'access_token'  => (string)$payload['access_token'],
		'refresh_token' => (string)$payload['refresh_token'],
	];
}

/**
 * POST to /oauth/revoke and return the response.
 *
 * @param array<string,string> $params
 */
function postRevoke(Slim\App $app, array $params): \Psr\Http\Message\ResponseInterface
{
	$factory = new Psr17Factory();
	return $app->handle(
		$factory->createServerRequest('POST', '/oauth/revoke')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody($params),
	);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('OAuthRevocation', function (): void {

	// -----------------------------------------------------------------------
	// 1. Missing token parameter → 400 invalid_request
	// -----------------------------------------------------------------------

	it('returns 400 invalid_request when token param is missing', function (): void {
		revokeSetupOAuthKeys($this->app);
		revokeCreateTestClient($this->app, 'client-missing-token', 'secret-missing');

		$response = postRevoke($this->app, [
			'client_id'     => 'client-missing-token',
			'client_secret' => 'secret-missing',
			// Intentionally omitting 'token'
		]);

		expect($response->getStatusCode())->toBe(400);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('error');
		expect($body['error'])->toBe('invalid_request');
	});

	// -----------------------------------------------------------------------
	// 2. Wrong client_secret → 401 invalid_client
	// -----------------------------------------------------------------------

	it('returns 401 invalid_client when client_secret is wrong', function (): void {
		revokeSetupOAuthKeys($this->app);
		revokeCreateTestClient($this->app, 'client-bad-secret', 'correct-secret');

		$response = postRevoke($this->app, [
			'client_id'     => 'client-bad-secret',
			'client_secret' => 'wrong-secret',
			'token'         => 'some-token-value',
		]);

		expect($response->getStatusCode())->toBe(401);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toHaveKey('error');
		expect($body['error'])->toBe('invalid_client');
	});

	// -----------------------------------------------------------------------
	// 3. Unknown / random token → 200 (RFC 7009 §2.2 — no leakage)
	// -----------------------------------------------------------------------

	it('returns 200 for an unknown token (no leakage per RFC 7009)', function (): void {
		revokeSetupOAuthKeys($this->app);
		revokeCreateTestClient($this->app, 'client-unknown-token', 'secret-unknown');

		$response = postRevoke($this->app, [
			'client_id'     => 'client-unknown-token',
			'client_secret' => 'secret-unknown',
			'token'         => 'completely-random-garbage-token-' . uniqid('', true),
		]);

		expect($response->getStatusCode())->toBe(200);
	});

	// -----------------------------------------------------------------------
	// 4. Revoking a refresh token deletes the grant
	// -----------------------------------------------------------------------

	it('revoking a refresh token deletes the grant from the repository', function (): void {
		revokeSetupOAuthKeys($this->app);

		$clientId     = 'client-revoke-refresh-' . uniqid('', true);
		$clientSecret = 'secret-revoke-refresh';
		revokeCreateTestClient($this->app, $clientId, $clientSecret);

		$tokens = revokeIssueTokens($this->app, $clientId, $clientSecret);

		$refreshToken = $tokens['refresh_token'];

		/** @var OAuthGrantRepository $grantRepo */
		$grantRepo = $this->app->getContainer()->get(OAuthGrantRepository::class);

		// Grant must exist before revocation — look up by client ID since
		// the opaque refresh token is Defuse-encrypted and the stored hash
		// is hash(refresh_token_id), not hash(opaque_token_string).
		$grantsBefore = $grantRepo->findByClientId($clientId);
		expect($grantsBefore)->not()->toBeEmpty();

		// Revoke the refresh token.
		$response = postRevoke($this->app, [
			'client_id'        => $clientId,
			'client_secret'    => $clientSecret,
			'token'            => $refreshToken,
			'token_type_hint'  => 'refresh_token',
		]);

		expect($response->getStatusCode())->toBe(200);

		// Grant must be gone after revocation.
		$grantsAfter = $grantRepo->findByClientId($clientId);
		expect($grantsAfter)->toBeEmpty();
	});

	// -----------------------------------------------------------------------
	// 5. Revoking an access token adds the jti to the revocation list
	// -----------------------------------------------------------------------

	it('revoking an access token adds the jti to the revocation list', function (): void {
		revokeSetupOAuthKeys($this->app);

		$clientId     = 'client-revoke-access-' . uniqid('', true);
		$clientSecret = 'secret-revoke-access';
		revokeCreateTestClient($this->app, $clientId, $clientSecret);

		$tokens      = revokeIssueTokens($this->app, $clientId, $clientSecret);
		$accessToken = $tokens['access_token'];

		// Parse the JWT to extract the jti before calling revoke.
		$parser = new Parser(new JoseEncoder());
		$jwt    = $parser->parse($accessToken);
		$jti    = (string)$jwt->claims()->get(RegisteredClaims::ID, '');

		expect($jti)->not()->toBeEmpty();

		/** @var OAuthRevocationList $revocationList */
		$revocationList = $this->app->getContainer()->get(OAuthRevocationList::class);

		expect($revocationList->isRevoked($jti))->toBeFalse();

		// Revoke the access token.
		$response = postRevoke($this->app, [
			'client_id'       => $clientId,
			'client_secret'   => $clientSecret,
			'token'           => $accessToken,
			'token_type_hint' => 'access_token',
		]);

		expect($response->getStatusCode())->toBe(200);

		// jti must now be on the revocation list.
		expect($revocationList->isRevoked($jti))->toBeTrue();
	});

	// -----------------------------------------------------------------------
	// 6. Cross-client: client B cannot revoke client A's refresh token
	// -----------------------------------------------------------------------

	it('does not revoke a refresh token that belongs to a different client', function (): void {
		revokeSetupOAuthKeys($this->app);

		$clientAId     = 'client-a-cross-' . uniqid('', true);
		$clientASecret = 'secret-a-cross';
		$clientBId     = 'client-b-cross-' . uniqid('', true);
		$clientBSecret = 'secret-b-cross';

		revokeCreateTestClient($this->app, $clientAId, $clientASecret);
		revokeCreateTestClient($this->app, $clientBId, $clientBSecret);

		// Issue tokens for client A.
		$tokens       = revokeIssueTokens($this->app, $clientAId, $clientASecret);
		$refreshToken = $tokens['refresh_token'];

		/** @var OAuthGrantRepository $grantRepo */
		$grantRepo = $this->app->getContainer()->get(OAuthGrantRepository::class);

		// Grant for client A must exist before the cross-client revocation attempt.
		$grantsBefore = $grantRepo->findByClientId($clientAId);
		expect($grantsBefore)->not()->toBeEmpty();

		// Client B tries to revoke client A's refresh token — must be a no-op.
		$response = postRevoke($this->app, [
			'client_id'       => $clientBId,
			'client_secret'   => $clientBSecret,
			'token'           => $refreshToken,
			'token_type_hint' => 'refresh_token',
		]);

		// RFC 7009: still returns 200.
		expect($response->getStatusCode())->toBe(200);

		// Client A's grant must still exist.
		$grantsAfter = $grantRepo->findByClientId($clientAId);
		expect($grantsAfter)->not()->toBeEmpty();
	});

});
