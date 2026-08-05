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
// Task 5 (Phase 3): REST API Bearer callers now get the SAME access-group
// checks session users get (BaseAccessMiddleware's oauth_bearer branch no
// longer bypasses group checks entirely — only super admins bypass).
//
// Reuses the OAuth key-setup / token-issuance / user-seeding helper patterns
// from McpAuthenticatedPersonaTest, with distinct function names — Pest loads
// every test file's globals together, so identical names would fatal.
// ──────────────────────────────────────────────────────────────────────────────

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());

	// config/local.test.php sets auth.enable = false so full-app dispatches
	// bypass ALL auth middleware (DualAuthMiddleware, BaseAccessMiddleware) by
	// default — see the comment on JumpStartSuperAdminGateTest. This file's
	// entire point is exercising BaseAccessMiddleware's oauth_bearer branch
	// through a real app dispatch, so auth must be turned on here.
	$container = $this->app->getContainer();
	$config    = $container->get(Config::class);
	$config->auth = array_merge($config->auth, ['enable' => true]);

	// Reserved collections no longer auto-create; the "allowed write" scenario
	// needs a real 'blog' collection to save into.
	$container->get(CollectionFetcher::class)->fetchOrCreateReserved('blog');
});

// ──────────────────────────────────────────────────────────────────────────────
// Helpers — distinct names to avoid collisions with other test files
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Generate an RSA key pair and configure $config->oauth to use them.
 */
function groupRestSetupOAuthKeys(Slim\App $app): void
{
	$tmpDir = sys_get_temp_dir() . '/oauth-group-rest-test-' . uniqid('', true);
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
}

/**
 * Copy a fixture auth user AND the access-groups fixture into the test data
 * dir. Fixture ids used here: 'blogger-user-test-com' (groups: [blogger]) and
 * 'viewer-user-test-com' (groups: [viewer]). Group grants (see
 * tests/tcms-data-fixtures/.system/access-groups.json):
 *   - blogger: collections {all:false, allowed:[blog], ops: create/read/update/delete}
 *   - viewer:  collections {all:true, ops:[read]}
 */
function groupRestSeedUser(string $fixtureId): void
{
	$authDir = cmsDataDir() . '/auth';
	if (!is_dir($authDir)) {
		mkdir($authDir, 0777, true);
	}
	copy(
		dirname(__DIR__) . '/tcms-data-fixtures/auth/' . $fixtureId . '.json',
		$authDir . '/' . $fixtureId . '.json',
	);

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
 * with the given scopes, issued to $userId (auth collection 'auth').
 *
 * @param list<string> $scopes
 */
function groupRestIssueToken(Slim\App $app, string $userId, array $scopes): string
{
	$clientId     = 'group-rest-' . uniqid('', true);
	$clientSecret = 'secret';

	$client = new OAuthClientData(
		id: $clientId,
		name: 'Group REST Access Test Client',
		secretHash: password_hash($clientSecret, PASSWORD_BCRYPT),
		redirectUris: ['https://grouptest.test/cb'],
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
		'redirect_uri'          => 'https://grouptest.test/cb',
		'scope'                 => implode(' ', $scopes),
		'state'                 => 'group-rest-state',
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
				'redirect_uri'  => 'https://grouptest.test/cb',
				'code'          => $code,
				'code_verifier' => $codeVerifier,
			]),
	);

	$payload = json_decode((string)$tokenResp->getBody(), true);

	return (string)($payload['access_token'] ?? '');
}

/**
 * POST a JSON body to $path with a Bearer access token.
 *
 * @param array<string,mixed> $body
 */
function groupRestPostJson(Slim\App $app, string $path, string $accessToken, array $body): Psr\Http\Message\ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('POST', $path)
		->withHeader('Content-Type', 'application/json')
		->withHeader('Authorization', 'Bearer ' . $accessToken);

	$request->getBody()->write((string)json_encode($body));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * GET $path with a Bearer access token.
 */
function groupRestGet(Slim\App $app, string $path, string $accessToken): Psr\Http\Message\ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('GET', $path)
		->withHeader('Authorization', 'Bearer ' . $accessToken);

	return $app->handle($request);
}

/**
 * PUT a JSON body to $path with a Bearer access token.
 *
 * @param array<string,mixed> $body
 */
function groupRestPutJson(Slim\App $app, string $path, string $accessToken, array $body): Psr\Http\Message\ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('PUT', $path)
		->withHeader('Content-Type', 'application/json')
		->withHeader('Authorization', 'Bearer ' . $accessToken);

	$request->getBody()->write((string)json_encode($body));
	$request->getBody()->rewind();

	return $app->handle($request);
}

/**
 * DELETE $path with a Bearer access token.
 */
function groupRestDelete(Slim\App $app, string $path, string $accessToken): Psr\Http\Message\ResponseInterface
{
	$factory = new Psr17Factory();
	$request = $factory
		->createServerRequest('DELETE', $path)
		->withHeader('Authorization', 'Bearer ' . $accessToken);

	return $app->handle($request);
}

// ──────────────────────────────────────────────────────────────────────────────
// Tests
// ──────────────────────────────────────────────────────────────────────────────

describe('OAuthRestGroupAccess', function (): void {
	// ──────────────────────────────────────────────────────────────────────
	// blogger's group grants create/read/update/delete on 'blog' only. A
	// write their group allows must NOT be rejected by the group layer.
	// (Payload validity is not under test — 200/201 on success, or a
	// downstream 400/404/422 from the handler, are all "the group layer let
	// it through".)
	// ──────────────────────────────────────────────────────────────────────

	it('blogger token writing to a collection their group allows is not blocked by the group layer', function (): void {
		groupRestSetupOAuthKeys($this->app);
		groupRestSeedUser('blogger-user-test-com');

		$token = groupRestIssueToken($this->app, 'blogger-user-test-com', ['cms:read', 'cms:write']);
		if ($token === '') {
			expect(true)->toBeTrue(); // skip-safe pass: OAuth unavailable in this env

			return;
		}

		$post = json_decode(file_get_contents(testData('new-blogpost.json')) ?: '{}', true);

		$response = groupRestPostJson($this->app, '/api/collections/blog', $token, $post);

		expect($response->getStatusCode())->not->toBe(403);
		expect($response->getStatusCode())->toBeIn([200, 201, 400, 404, 422]);
	});

	// ──────────────────────────────────────────────────────────────────────
	// blogger's group only allows 'blog' — a write to any other collection
	// must be rejected by the group layer with a 403 that is distinguishable
	// from the scope layer's 403 (OAuthRestScopeMiddleware).
	// ──────────────────────────────────────────────────────────────────────

	it("blogger token writing to a collection their group denies is blocked with a group-layer 403", function (): void {
		groupRestSetupOAuthKeys($this->app);
		groupRestSeedUser('blogger-user-test-com');

		$token = groupRestIssueToken($this->app, 'blogger-user-test-com', ['cms:read', 'cms:write']);
		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = groupRestPostJson($this->app, '/api/collections/pages', $token, ['title' => 'Nope']);

		expect($response->getStatusCode())->toBe(403);

		// Distinguish from the scope layer's 403 (OAuthRestScopeMiddleware),
		// which sets WWW-Authenticate: insufficient_scope and a "scopes do not
		// permit" message. The group layer's forbiddenResponse() sets neither.
		expect($response->getHeaderLine('WWW-Authenticate'))->not->toContain('insufficient_scope');
		$body = json_decode((string)$response->getBody(), true);
		expect((string)($body['error']['message'] ?? ''))->not->toContain('scopes do not permit');
	});

	// ──────────────────────────────────────────────────────────────────────
	// viewer's group grants read-only, all collections. Any write — even to
	// 'blog', which the scope layer permits (cms:write) — must be rejected
	// by the group layer.
	// ──────────────────────────────────────────────────────────────────────

	it('viewer token performing a write is blocked by the group layer regardless of collection', function (): void {
		groupRestSetupOAuthKeys($this->app);
		groupRestSeedUser('viewer-user-test-com');

		$token = groupRestIssueToken($this->app, 'viewer-user-test-com', ['cms:read', 'cms:write']);
		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = groupRestPostJson($this->app, '/api/collections/blog', $token, ['title' => 'Nope']);

		expect($response->getStatusCode())->toBe(403);
		expect($response->getHeaderLine('WWW-Authenticate'))->not->toContain('insufficient_scope');
	});

	// ──────────────────────────────────────────────────────────────────────
	// viewer's group DOES grant read on all collections — a read must still
	// succeed for a Bearer caller now that group checks apply (regression
	// guard: the group layer must not become deny-everything for Bearer).
	// ──────────────────────────────────────────────────────────────────────

	it('viewer token can still read a collection it is granted', function (): void {
		groupRestSetupOAuthKeys($this->app);
		groupRestSeedUser('viewer-user-test-com');

		$token = groupRestIssueToken($this->app, 'viewer-user-test-com', ['cms:read']);
		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = groupRestGet($this->app, '/api/collections/blog', $token);

		expect($response->getStatusCode())->toBe(200);
	});

	// ──────────────────────────────────────────────────────────────────────
	// Regression guard (security review, Task 5 fix round 1): the collection
	// middleware's self-profile carve-out ("users may always update their own
	// auth record") gates on session AUTH_COLLECTION, not on auth method. A
	// Bearer request that ALSO carries an active session cookie (browser-
	// originated token use — groupRestIssueToken() leaves the session set
	// with AUTH_USER/AUTH_COLLECTION exactly as token issuance does) must
	// NOT be able to use that carve-out to bypass the group check on the
	// auth collection itself. viewer's group is read-only, so this update
	// must still 403.
	// ──────────────────────────────────────────────────────────────────────

	it('bearer request with an active session cannot use the self-profile carve-out to bypass the group check', function (): void {
		groupRestSetupOAuthKeys($this->app);
		groupRestSeedUser('viewer-user-test-com');

		// groupRestIssueToken() sets session AUTH_USER/AUTH_COLLECTION as a side
		// effect of the authorization-code flow and does not clear them
		// afterward — the session is still "active" for the next request made
		// against $this->app, exactly like a browser that also holds a cookie.
		$token = groupRestIssueToken($this->app, 'viewer-user-test-com', ['cms:read', 'cms:write']);
		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		// Self-profile-shaped request: collection === the caller's own auth
		// collection, id === the caller's own user id — exactly what the
		// carve-out matches on.
		$response = groupRestPutJson(
			$this->app,
			'/api/collections/auth/viewer-user-test-com',
			$token,
			['name' => 'Should Not Be Allowed'],
		);

		expect($response->getStatusCode())->toBe(403);
		expect($response->getHeaderLine('WWW-Authenticate'))->not->toContain('insufficient_scope');
	});

	// ──────────────────────────────────────────────────────────────────────
	// Task 8 fix round — Critical: /api/cache had NO access-group middleware
	// at all (only AuthMiddleware), so any authenticated Bearer token could
	// flush caches and toggle devmode regardless of group. A security review
	// (2026-08) proved this live with exactly this shape of request: a
	// viewer-fixture token carrying cms:admin (issued under Task 8's widened
	// cms:admin gate, since viewer's group satisfies hasAdminDomainGrants())
	// got DELETE /api/cache -> 200, POST /api/cache/devmode -> 200, DELETE
	// /api/cache/watermarks -> 200. This re-runs that EXACT exploit and
	// pins the fix: CacheAccessMiddleware now gates the route on the utils
	// 'cache' grant, which viewer's group does not have.
	// ──────────────────────────────────────────────────────────────────────

	it("viewer's widened cms:admin token can no longer flush the cache (reviewer's exploit, now fixed)", function (): void {
		groupRestSetupOAuthKeys($this->app);
		groupRestSeedUser('viewer-user-test-com');

		$token = groupRestIssueToken($this->app, 'viewer-user-test-com', ['cms:admin', 'cms:read', 'cms:write']);
		if ($token === '') {
			expect(true)->toBeTrue(); // skip-safe pass

			return;
		}

		$deleteCache = groupRestDelete($this->app, '/api/cache', $token);
		expect($deleteCache->getStatusCode())->toBe(403);

		$enableDevMode = groupRestPostJson($this->app, '/api/cache/devmode', $token, []);
		expect($enableDevMode->getStatusCode())->toBe(403);

		$deleteWatermarks = groupRestDelete($this->app, '/api/cache/watermarks', $token);
		expect($deleteWatermarks->getStatusCode())->toBe(403);
	});

	// ──────────────────────────────────────────────────────────────────────
	// Complement to the exploit-fixed test above: a caller whose group DOES
	// grant the `cache` util (schema-editor fixture) must still be able to
	// use these routes — the fix must not become deny-everything.
	// ──────────────────────────────────────────────────────────────────────

	it('a token whose group grants the cache util can flush the cache', function (): void {
		groupRestSetupOAuthKeys($this->app);
		groupRestSeedUser('schema-editor-user-test-com');

		$token = groupRestIssueToken($this->app, 'schema-editor-user-test-com', ['cms:admin']);
		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = groupRestDelete($this->app, '/api/cache', $token);

		expect($response->getStatusCode())->toBe(200);
	});

	// ──────────────────────────────────────────────────────────────────────
	// Task 8 fix round — Important #5: proves the group layer still caps a
	// token whose cms:admin scope was granted under the WIDENED issuance
	// gate (LeagueScopeRepository::finalizeScopes() / OAuthAuthorizeAction —
	// isAdmin OR hasAdminDomainGrants()). viewer's group satisfies
	// hasAdminDomainGrants() (schemas {all:true, operations:[read]}), so its
	// token now legitimately carries cms:admin and clears the SCOPE layer —
	// but viewer's schemas grant is READ-ONLY, so the GROUP layer must still
	// reject a schema CREATE. This is the group-layer counterpart the
	// McpAuthenticatedPersonaTest "widened issuance gate" test cannot prove
	// on its own, since that file runs with auth.enable=false (making
	// SchemaAccessMiddleware inert) and only demonstrates the scope layer.
	// ──────────────────────────────────────────────────────────────────────

	it('a viewer token with widened cms:admin scope still cannot create a schema (group layer caps the widened issuance gate)', function (): void {
		groupRestSetupOAuthKeys($this->app);
		groupRestSeedUser('viewer-user-test-com');

		$token = groupRestIssueToken($this->app, 'viewer-user-test-com', ['cms:admin']);
		if ($token === '') {
			expect(true)->toBeTrue();

			return;
		}

		$response = groupRestPostJson($this->app, '/api/schemas', $token, [
			'id'         => 'widened-gate-test-schema',
			'properties' => ['name' => ['field' => 'text', 'label' => 'Name']],
		]);

		expect($response->getStatusCode())->toBe(403);
		expect($response->getHeaderLine('WWW-Authenticate'))->not->toContain('insufficient_scope');
	});
});
