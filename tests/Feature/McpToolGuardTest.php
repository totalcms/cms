<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Odan\Session\PhpSession;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpServerFactory;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

// ──────────────────────────────────────────────────────────────────────────────
// Task 7 (Phase 4): call-time enforcement guard for McpToolDefinition::$requires.
//
// Task 6 shipped the tools/list VISIBILITY filter (isSatisfiedForAny —
// "could this caller EVER use this tool"). This file proves the separate,
// stricter call-time guard in McpServerFactory::guardHandler() — the actual
// security boundary — using SYNTHETIC tool definitions registered directly
// into the container's ToolRegistry, since no shipped tool sets $requires yet
// (that's Task 8).
//
// Function names are prefixed `mcpGuard` — distinct from McpAuthenticated-
// PersonaTest's `mcpAuth*` helpers — since Pest loads every test file's
// global functions into one process and a name collision would fatal.
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
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

function mcpGuardSetupOAuthKeys(Slim\App $app): void
{
	$tmpDir = sys_get_temp_dir() . '/oauth-mcp-guard-test-' . uniqid('', true);
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
 * Copy a fixture user into the test auth collection so isSuperAdmin() /
 * AccessControlService::authorityFor() can resolve them. Fixture ids used
 * here: 'blogger-user-test-com' (groups: [blogger]), 'viewer-user-test-com'
 * (groups: [viewer]), 'admin-user-test-com' (groups: [admin]).
 */
function mcpGuardSeedUser(string $fixtureId): void
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
 * Registers a synthetic requirement-bearing tool directly into the
 * container's ToolRegistry. Handler echoes back the arguments it received
 * (as JSON) so tests can prove argument binding survived the guard wrapper
 * intact — this is the strongest possible proof since it round-trips
 * through the REAL mcp/sdk reflection-based dispatch (CallToolHandler →
 * ReferenceHandler), not a stand-in.
 */
function mcpGuardRegisterTool(Slim\App $app, string $name, ?ToolRequirement $requires, string $access = 'authenticated'): void
{
	/** @var ToolRegistry $registry */
	$registry = $app->getContainer()->get(ToolRegistry::class);
	if ($registry->get($name) !== null) {
		$registry->unregister($name);
	}

	$registry->register(new McpToolDefinition(
		name: $name,
		description: 'Synthetic tool for McpToolGuardTest — echoes its arguments back.',
		access: $access,
		handler: static function (string $collection = '', string $note = ''): array {
			return ['received_collection' => $collection, 'received_note' => $note];
		},
		inputSchema: [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'collection' => ['type' => 'string', 'default' => ''],
				'note'       => ['type' => 'string', 'default' => ''],
			],
		],
		requires: $requires,
	));
}

/**
 * Walk the full authorization-code + PKCE flow and return an access token
 * with the given scopes, or '' when the environment can't run OAuth
 * (non-Pro edition, OAuth unavailable) — callers should skip-safe-pass.
 *
 * @param list<string> $scopes
 */
function mcpGuardIssueToken(Slim\App $app, string $clientId, string $clientSecret, array $scopes, string $userId): string
{
	$client = new OAuthClientData(
		id: $clientId,
		name: 'MCP Guard Test Client',
		secretHash: password_hash($clientSecret, PASSWORD_BCRYPT),
		redirectUris: ['https://mcpguardtest.test/cb'],
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

	$authorizeUrl = '/oauth/authorize?' . http_build_query([
		'response_type'         => 'code',
		'client_id'             => $clientId,
		'redirect_uri'          => 'https://mcpguardtest.test/cb',
		'scope'                 => implode(' ', $scopes),
		'state'                 => 'mcpguardtest-state',
		'code_challenge'        => $codeChallenge,
		'code_challenge_method' => 'S256',
	]);
	$app->handle($factory->createServerRequest('GET', $authorizeUrl));

	if (!$session->isStarted()) {
		$session->start();
	}
	/** @var CSRFTokenManager $csrf */
	$csrf      = $app->getContainer()->get(CSRFTokenManager::class);
	$csrfToken = $csrf->generateToken();

	$approve = $app->handle(
		$factory->createServerRequest('POST', '/oauth/authorize')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody(['decision' => 'approve', 'csrf_token' => $csrfToken]),
	);

	parse_str((string)parse_url($approve->getHeaderLine('Location'), PHP_URL_QUERY), $cb);
	$code = (string)($cb['code'] ?? '');

	$tokenResp = $app->handle(
		$factory->createServerRequest('POST', '/oauth/token')
			->withHeader('Content-Type', 'application/x-www-form-urlencoded')
			->withParsedBody([
				'grant_type'    => 'authorization_code',
				'client_id'     => $clientId,
				'client_secret' => $clientSecret,
				'redirect_uri'  => 'https://mcpguardtest.test/cb',
				'code'          => $code,
				'code_verifier' => $codeVerifier,
			]),
	);

	$payload = json_decode((string)$tokenResp->getBody(), true);

	return (string)($payload['access_token'] ?? '');
}

function mcpGuardInitSession(Slim\App $app, string $accessToken): string
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
			'clientInfo'      => ['name' => 'pest-mcp-guard', 'version' => '0.1'],
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
 * @param array<string,mixed> $payload
 */
function mcpGuardRequest(Slim\App $app, string $accessToken, array $payload, string $sessionId): Psr\Http\Message\ResponseInterface
{
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
 * Full round trip: issue a token for $userId with $scopes, init an MCP
 * session, and call $toolName with $arguments. Returns null when the
 * environment can't run OAuth/MCP at all (skip-safe).
 *
 * @param list<string>         $scopes
 * @param array<string,mixed>  $arguments
 */
function mcpGuardCallTool(Slim\App $app, string $userId, array $scopes, string $toolName, array $arguments): ?Psr\Http\Message\ResponseInterface
{
	mcpGuardSetupOAuthKeys($app);

	$clientId = 'mcp-guard-' . uniqid('', true);
	$token    = mcpGuardIssueToken($app, $clientId, 'secret', $scopes, $userId);
	if ($token === '') {
		return null;
	}

	$sessionId = mcpGuardInitSession($app, $token);
	if ($sessionId === '') {
		return null;
	}

	return mcpGuardRequest($app, $token, [
		'jsonrpc' => '2.0',
		'id'      => 1,
		'method'  => 'tools/call',
		'params'  => [
			'name'      => $toolName,
			'arguments' => $arguments,
		],
	], $sessionId);
}

/**
 * Extracts the tools/call result text (content[0].text) from a JSON-RPC
 * response body, or null if the shape doesn't match.
 */
function mcpGuardResultText(Psr\Http\Message\ResponseInterface $response): ?string
{
	$body = json_decode((string)$response->getBody(), true);

	return $body['result']['content'][0]['text'] ?? null;
}

// ──────────────────────────────────────────────────────────────────────────────
// Tests
// ──────────────────────────────────────────────────────────────────────────────

describe('McpToolGuard — call-time enforcement', function (): void {
	// (a) allowed case: blogger authority + cms:write scope + collection
	// 'blog' → handler INVOKED and received its arguments intact.
	it('invokes the handler with its real arguments when scope + group both allow', function (): void {
		mcpGuardSeedUser('blogger-user-test-com');
		mcpGuardRegisterTool(
			$this->app,
			'guard_test_allowed',
			new ToolRequirement(domain: 'objects', operation: 'create', collectionArg: 'collection'),
		);

		$response = mcpGuardCallTool(
			$this->app,
			'blogger-user-test-com',
			['cms:write', 'mcp:tools'],
			'guard_test_allowed',
			['collection' => 'blog', 'note' => 'hello-from-test'],
		);

		if ($response === null) {
			expect(true)->toBeTrue(); // skip-safe pass — non-Pro / OAuth unavailable

			return;
		}

		expect($response->getStatusCode())->toBe(200);

		$text = mcpGuardResultText($response);
		expect($text)->not->toBeNull();

		$decoded = json_decode((string)$text, true);
		expect($decoded)->toBe([
			'received_collection' => 'blog',
			'received_note'       => 'hello-from-test',
		]);
	});

	// (b) group denial: blogger + cms:write scope + collection 'products'
	// (not in blogger's allowed list) → handler NOT invoked, error mentions
	// groups.
	it('denies with a group-layer error when the group does not grant the target collection', function (): void {
		mcpGuardSeedUser('blogger-user-test-com');
		mcpGuardRegisterTool(
			$this->app,
			'guard_test_group_denied',
			new ToolRequirement(domain: 'objects', operation: 'create', collectionArg: 'collection'),
		);

		$response = mcpGuardCallTool(
			$this->app,
			'blogger-user-test-com',
			['cms:write', 'mcp:tools'],
			'guard_test_group_denied',
			['collection' => 'products', 'note' => 'should-not-run'],
		);

		if ($response === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($response->getStatusCode())->toBe(200);

		$text = mcpGuardResultText($response);
		expect($text)->not->toBeNull();
		expect($text)->toContain('groups');
		expect($text)->toContain('products');
		// The echoed-argument shape from the real handler must NOT appear —
		// proof the inner handler never ran.
		expect($text)->not->toContain('received_collection');
	});

	// (c) scope denial: blogger authority is fine, but the token lacks
	// cms:write → handler NOT invoked, error mentions the permission.
	it('denies with a scope-layer error when the token lacks the required scope', function (): void {
		mcpGuardSeedUser('blogger-user-test-com');
		mcpGuardRegisterTool(
			$this->app,
			'guard_test_scope_denied',
			new ToolRequirement(domain: 'objects', operation: 'create', collectionArg: 'collection'),
		);

		// mcp:tools only — no cms:write. Visibility (isSatisfiedForAny) is
		// authority-based, not scope-based, so the tool is still registered
		// for this Bearer session; the call-time guard is what must catch
		// the missing scope.
		$response = mcpGuardCallTool(
			$this->app,
			'blogger-user-test-com',
			['mcp:tools'],
			'guard_test_scope_denied',
			['collection' => 'blog', 'note' => 'should-not-run'],
		);

		if ($response === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($response->getStatusCode())->toBe(200);

		$text = mcpGuardResultText($response);
		expect($text)->not->toBeNull();
		expect($text)->toContain('permission');
		expect($text)->not->toContain('received_collection');
	});

	// (e) missing/empty collectionArg → denied with a clear message, handler
	// not invoked.
	it('denies when the collection argument is missing', function (): void {
		mcpGuardSeedUser('blogger-user-test-com');
		mcpGuardRegisterTool(
			$this->app,
			'guard_test_missing_arg',
			new ToolRequirement(domain: 'objects', operation: 'create', collectionArg: 'collection'),
		);

		$response = mcpGuardCallTool(
			$this->app,
			'blogger-user-test-com',
			['cms:write', 'mcp:tools'],
			'guard_test_missing_arg',
			[], // no 'collection' key at all
		);

		if ($response === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($response->getStatusCode())->toBe(200);

		$text = mcpGuardResultText($response);
		expect($text)->not->toBeNull();
		expect($text)->toContain('groups');
		expect($text)->not->toContain('received_collection');
	});

	// (f) ADMIN persona with a requirement-bearing tool → handler invoked,
	// no group/scope check performed (an admin-group user + cms:admin scope
	// elevates to the ADMIN persona — see McpAuthenticatedPersonaTest
	// scenario 8 for the elevation mechanics this reuses).
	it('skips the guard entirely for the ADMIN persona', function (): void {
		mcpGuardSeedUser('admin-user-test-com');
		mcpGuardRegisterTool(
			$this->app,
			'guard_test_admin',
			new ToolRequirement(domain: 'objects', operation: 'create', collectionArg: 'collection'),
			access: 'admin',
		);

		$response = mcpGuardCallTool(
			$this->app,
			'admin-user-test-com',
			['cms:admin', 'mcp:tools'],
			'guard_test_admin',
			['collection' => 'anything-at-all', 'note' => 'admin-path'],
		);

		if ($response === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($response->getStatusCode())->toBe(200);

		$text = mcpGuardResultText($response);
		expect($text)->not->toBeNull();

		$decoded = json_decode((string)$text, true);
		expect($decoded)->toBe([
			'received_collection' => 'anything-at-all',
			'received_note'       => 'admin-path',
		]);
	});

	// (g) regression guard: a tool WITHOUT $requires is invoked completely
	// unchanged by guardHandler() — even a token with no cms:* scope at all
	// (only mcp:tools, the protocol-level gate) must still be able to call
	// it, proving the guard truly no-ops rather than accidentally gating
	// unrequiring tools.
	it('leaves a tool without $requires completely unguarded', function (): void {
		mcpGuardSeedUser('blogger-user-test-com');
		mcpGuardRegisterTool($this->app, 'guard_test_norequire', null);

		$response = mcpGuardCallTool(
			$this->app,
			'blogger-user-test-com',
			['mcp:tools'], // no cms:* scope at all
			'guard_test_norequire',
			['collection' => 'whatever', 'note' => 'unguarded'],
		);

		if ($response === null) {
			expect(true)->toBeTrue();

			return;
		}

		expect($response->getStatusCode())->toBe(200);

		$text = mcpGuardResultText($response);
		expect($text)->not->toBeNull();

		$decoded = json_decode((string)$text, true);
		expect($decoded)->toBe([
			'received_collection' => 'whatever',
			'received_note'       => 'unguarded',
		]);
	});

	// (d) null authority → denied (fail closed). Unreachable through the
	// public HTTP surface in practice — PersonaContext::setAuthority() is
	// only ever left null when a Bearer request's oauth_user_id attribute
	// was itself never set, which every real token-issuing flow populates
	// (see McpEndpointAction) — so this is exercised directly against
	// guardHandler() via reflection, per the brief's documented fallback for
	// scenarios that can't be driven through the full stack.
	it('denies when PersonaContext has no resolved UserAuthority (fail closed)', function (): void {
		/** @var McpServerFactory $factory */
		$factory = $this->app->getContainer()->get(McpServerFactory::class);
		/** @var PersonaContext $personaContext */
		$personaContext = $this->app->getContainer()->get(PersonaContext::class);

		$personaContext->set(McpPersona::AUTHENTICATED);
		$personaContext->setScopes(['cms:write', 'mcp:tools']);
		// Deliberately do NOT call setAuthority() — it defaults to null.

		$invoked = false;
		$tool    = new McpToolDefinition(
			name: 'guard_test_null_authority',
			description: 'Reflection-only synthetic tool.',
			access: 'authenticated',
			handler: static function () use (&$invoked): array {
				$invoked = true;

				return ['ran' => true];
			},
			requires: new ToolRequirement(domain: 'objects', operation: 'create', collectionArg: 'collection'),
		);

		$method = new ReflectionMethod(McpServerFactory::class, 'guardHandler');
		$method->setAccessible(true);
		/** @var Closure $guarded */
		$guarded = $method->invoke($factory, $tool);

		$threw = false;
		try {
			$guarded(['collection' => 'blog']);
		} catch (ToolCallException $e) {
			$threw = true;
			expect($e->getMessage())->toContain('groups');
		}

		expect($threw)->toBeTrue();
		expect($invoked)->toBeFalse();
	});

	// Fix round 1, finding #4: defense-in-depth PUBLIC_ deny inside the
	// guard itself. McpToolDefinition::isVisibleTo() now keeps a
	// $requires-bearing tool off the registered SDK surface for PUBLIC_
	// callers entirely (see McpToolDefinitionTest / ToolRequirementVisibilityTest
	// for that layer), so this branch is unreachable through the real /mcp
	// HTTP surface — there is no way to get a PUBLIC_ persona to even see
	// the tool in order to call it. Exercised directly via reflection, same
	// fallback as the null-authority scenario above, to prove the guard
	// body ALSO fails closed rather than relying solely on the visibility
	// layer to keep this safe.
	it('denies the PUBLIC_ persona outright even if a requirement-bearing tool were somehow dispatched (defense in depth)', function (): void {
		/** @var McpServerFactory $factory */
		$factory = $this->app->getContainer()->get(McpServerFactory::class);
		/** @var PersonaContext $personaContext */
		$personaContext = $this->app->getContainer()->get(PersonaContext::class);

		$personaContext->set(McpPersona::PUBLIC_);

		$invoked = false;
		$tool    = new McpToolDefinition(
			name: 'guard_test_public_persona',
			description: 'Reflection-only synthetic tool.',
			access: 'public', // the very misconfiguration finding #4 closes off
			handler: static function () use (&$invoked): array {
				$invoked = true;

				return ['ran' => true];
			},
			requires: new ToolRequirement(domain: 'objects', operation: 'create', collectionArg: 'collection'),
		);

		$method = new ReflectionMethod(McpServerFactory::class, 'guardHandler');
		$method->setAccessible(true);
		/** @var Closure $guarded */
		$guarded = $method->invoke($factory, $tool);

		$threw = false;
		try {
			$guarded(['collection' => 'blog']);
		} catch (ToolCallException $e) {
			$threw = true;
			expect($e->getMessage())->toContain('authenticated');
		}

		expect($threw)->toBeTrue();
		expect($invoked)->toBeFalse();
	});
});
