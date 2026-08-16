<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Auth\Service;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Exception\McpAuthException;
use TotalCMS\Domain\Mcp\Auth\Service\McpAuth;
use TotalCMS\Support\Config;

final class McpAuthTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $authenticator;
	private \PHPUnit\Framework\MockObject\MockObject $accessControl;
	private Config $config;

	protected function setUp(): void
	{
		$this->authenticator = $this->createMock(ApiKeyAuthenticator::class);
		$this->accessControl = $this->createMock(AccessControlService::class);

		// Config can't be createMock'd reliably because its constructor expects
		// a fully-populated settings array; bypass it.
		$this->config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->mcp  = ['publicAccess' => true];
		$this->config->auth = ['collection' => 'auth'];
	}

	/**
	 * @param list<EditionFeature> $denied Features this licence does NOT include
	 */
	private function auth(array $denied = []): McpAuth
	{
		$editions = $this->createMock(EditionFeatureService::class);
		$editions->method('can')->willReturnCallback(
			static fn (EditionFeature $f): bool => !in_array($f, $denied, true),
		);

		return new McpAuth($this->authenticator, $this->accessControl, $this->config, $editions);
	}

	/**
	 * Request mock carrying the attributes OAuthBearerMiddleware would set.
	 *
	 * @param list<string> $scopes
	 */
	private function bearerRequest(array $scopes, ?string $userId = null): ServerRequestInterface
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => match ($name) {
				'oauth_scopes'  => $scopes,
				'oauth_user_id' => $userId,
				default         => $default,
			},
		);

		return $request;
	}

	private function makeApiKey(): ApiKeyData
	{
		return new ApiKeyData([
			'id'      => 'k1',
			'name'    => 'Test',
			'key'     => 'tcms_valid',
			'created' => '2025-01-01T00:00:00Z',
			'scopes'  => ['methods' => ['POST'], 'paths' => ['/mcp']],
		]);
	}

	public function testNoKeyAndPublicAccessOnReturnsPublicPersona(): void
	{
		$this->config->mcp = ['publicAccess' => true];
		$request           = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(false);

		$persona = $this->auth()->resolvePersona($request);

		$this->assertSame(McpPersona::PUBLIC_, $persona);
	}

	public function testNoKeyAndPublicAccessOffThrows(): void
	{
		$this->config->mcp = ['publicAccess' => false];
		$request           = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(false);

		$this->expectException(McpAuthException::class);
		$this->expectExceptionMessage('Anonymous access is disabled');

		$this->auth()->resolvePersona($request);
	}

	public function testNoKeyAndPublicAccessMissingDefaultsToDeny(): void
	{
		// Defensive: an MCP block missing publicAccess entirely should deny,
		// not silently allow public.
		$this->config->mcp = [];
		$request           = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(false);

		$this->expectException(McpAuthException::class);

		$this->auth()->resolvePersona($request);
	}

	public function testValidKeyWithMcpScopeResolvesToAdmin(): void
	{
		// authenticate() returns ApiKeyData when the key is valid AND its
		// scopes cover the actual request method + path. McpAuth treats that
		// as proof of admin authority — no further checks.
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->with($request)->willReturn($this->makeApiKey());

		$persona = $this->auth()->resolvePersona($request);

		$this->assertSame(McpPersona::ADMIN, $persona);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Edition gating. MCP is available on every edition, but only the anonymous
	// persona comes with it: the ADMIN and AUTHENTICATED personas require
	// credentials that are Pro-only. A credential can outlive the licence that
	// created it — a lapsed trial, a downgrade, a restored backup, a copied
	// tcms-data — so the persona resolver has to check the edition itself
	// rather than trust that no key or token exists.
	// ──────────────────────────────────────────────────────────────────────────

	public function testValidApiKeyOnNonProResolvesPublicNotAdmin(): void
	{
		$request = $this->createMock(ServerRequestInterface::class);
		// A genuinely valid key: the authenticator would happily accept it.
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->willReturn($this->makeApiKey());

		$persona = $this->auth(denied: [EditionFeature::API_KEYS])->resolvePersona($request);

		// Treated as absent rather than invalid — the caller is anonymous and
		// still gets whatever the site exposes publicly.
		$this->assertSame(McpPersona::PUBLIC_, $persona);
	}

	public function testValidApiKeyOnNonProIsRefusedWhenPublicAccessIsOff(): void
	{
		$this->config->mcp = ['publicAccess' => false];
		$request           = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->willReturn($this->makeApiKey());

		// No persona is available to this caller at all: the key cannot grant
		// ADMIN on this edition, and anonymous access is switched off.
		$this->expectException(McpAuthException::class);
		$this->auth(denied: [EditionFeature::API_KEYS])->resolvePersona($request);
	}

	public function testOauthTokenOnNonProResolvesPublicNotAuthenticated(): void
	{
		$request = $this->bearerRequest(['mcp:tools', 'cms:read']);

		$persona = $this->auth(denied: [EditionFeature::OAUTH_SERVER])->resolvePersona($request);

		$this->assertSame(McpPersona::PUBLIC_, $persona);
	}

	public function testApiKeyStillResolvesAdminWhenTheEditionIncludesIt(): void
	{
		// The control for the two above: nothing changes on Pro.
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->willReturn($this->makeApiKey());

		$this->assertSame(McpPersona::ADMIN, $this->auth()->resolvePersona($request));
	}

	public function testInvalidKeyThrows(): void
	{
		// authenticate() returns null for both "key doesn't exist" and "valid
		// key without /mcp scope". McpAuth collapses both into one error since
		// they're functionally identical for the caller.
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->with($request)->willReturn(null);

		$this->expectException(McpAuthException::class);
		$this->expectExceptionMessage('insufficient permissions for MCP access');

		$this->auth()->resolvePersona($request);
	}

	public function testKeyWithoutMcpScopeIsRejected(): void
	{
		// A key scoped only to /collections/blog should not unlock MCP —
		// expressed via authenticate() returning null because the scope check
		// fails inside the standard pipeline.
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->with($request)->willReturn(null);

		$this->expectException(McpAuthException::class);

		$this->auth()->resolvePersona($request);
	}

	public function testNoKeyAndPublicAccessOffCarriesLoginRequiredReason(): void
	{
		// The exception carries a `reason` so the endpoint action can emit the
		// correct WWW-Authenticate header: login_required for absent
		// credentials vs invalid_token for bad credentials. Lazy-auth UX in
		// Claude relies on the distinction.
		$this->config->mcp = ['publicAccess' => false];
		$request           = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(false);

		try {
			$this->auth()->resolvePersona($request);
			$this->fail('Expected McpAuthException.');
		} catch (McpAuthException $e) {
			$this->assertSame('login_required', $e->reason);
		}
	}

	public function testInvalidKeyCarriesInvalidTokenReason(): void
	{
		$request = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->with($request)->willReturn(null);

		try {
			$this->auth()->resolvePersona($request);
			$this->fail('Expected McpAuthException.');
		} catch (McpAuthException $e) {
			$this->assertSame('invalid_token', $e->reason);
		}
	}

	public function testValidKeyBypassesPublicAccessFlag(): void
	{
		// Even with publicAccess off, a valid key still authenticates as admin.
		$this->config->mcp = ['publicAccess' => false];
		$request           = $this->createMock(ServerRequestInterface::class);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(true);
		$this->authenticator->method('authenticate')->with($request)->willReturn($this->makeApiKey());

		$persona = $this->auth()->resolvePersona($request);

		$this->assertSame(McpPersona::ADMIN, $persona);
	}

	// ── Bearer / OAuth tests ────────────────────────────────────────────────────

	public function testBearerWithMcpToolsScopeResolvesToAuthenticated(): void
	{
		// OAuthBearerMiddleware upstream has already validated the JWT and set
		// oauth_scopes on the request. McpAuth reads the attribute directly.
		$persona = $this->auth()->resolvePersona($this->bearerRequest(['mcp:tools']));

		$this->assertSame(McpPersona::AUTHENTICATED, $persona);
	}

	public function testBearerWithMcpResourcesScopeResolvesToAuthenticated(): void
	{
		$persona = $this->auth()->resolvePersona($this->bearerRequest(['mcp:resources']));

		$this->assertSame(McpPersona::AUTHENTICATED, $persona);
	}

	public function testBearerWithMixedScopesResolvesToAuthenticatedWhenMcpPresent(): void
	{
		// A token that includes both non-MCP and MCP scopes is still valid for
		// MCP access — the presence of at least one mcp:* scope is sufficient.
		// No oauth_user_id on the request (and the access-control mock denies
		// by default), so cms:admin alone cannot elevate.
		$persona = $this->auth()->resolvePersona($this->bearerRequest(['cms:admin', 'mcp:tools']));

		$this->assertSame(McpPersona::AUTHENTICATED, $persona);
	}

	// ── super-admin elevation ──────────────────────────────────────────────

	public function testBearerElevatesToAdminForAdminGroupUserWithCmsAdminScope(): void
	{
		$this->accessControl->method('isAdmin')->with('joe')->willReturn(true);

		$persona = $this->auth()->resolvePersona($this->bearerRequest(['cms:admin', 'mcp:tools'], 'joe'));

		$this->assertSame(McpPersona::ADMIN, $persona);
	}

	public function testBearerStaysAuthenticatedForAdminUserWithoutCmsAdminScope(): void
	{
		// The consent screen never showed "Administer your site" — the admin
		// deliberately connected a read-only assistant.
		$this->accessControl->method('isAdmin')->willReturn(true);

		$persona = $this->auth()->resolvePersona($this->bearerRequest(['cms:read', 'mcp:tools'], 'joe'));

		$this->assertSame(McpPersona::AUTHENTICATED, $persona);
	}

	public function testBearerStaysAuthenticatedForNonAdminUserWithCmsAdminScope(): void
	{
		$this->accessControl->method('isAdmin')->with('member')->willReturn(false);

		$persona = $this->auth()->resolvePersona($this->bearerRequest(['cms:admin', 'mcp:tools'], 'member'));

		$this->assertSame(McpPersona::AUTHENTICATED, $persona);
	}

	public function testBearerWithNoMcpScopeThrowsInsufficientScope(): void
	{
		// A valid OAuth token that carries only non-MCP scopes must be rejected
		// with insufficient_scope — it reached the server but isn't authorised.
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')
			->with('oauth_scopes')
			->willReturn(['cms:read']);

		$this->expectException(McpAuthException::class);

		try {
			$this->auth()->resolvePersona($request);
			$this->fail('Expected McpAuthException.');
		} catch (McpAuthException $e) {
			$this->assertSame('insufficient_scope', $e->reason);
			throw $e;
		}
	}

	public function testBearerWithEmptyScopesArrayThrowsInsufficientScope(): void
	{
		// An empty scopes list from the middleware means the token carries no
		// scopes at all — should be rejected with insufficient_scope.
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')
			->with('oauth_scopes')
			->willReturn([]);

		$this->expectException(McpAuthException::class);

		try {
			$this->auth()->resolvePersona($request);
			$this->fail('Expected McpAuthException.');
		} catch (McpAuthException $e) {
			$this->assertSame('insufficient_scope', $e->reason);
			throw $e;
		}
	}

	public function testNoOauthScopesAttributeFallsThroughToApiKeyFlow(): void
	{
		// When oauth_scopes is null (no Bearer header / middleware didn't run),
		// McpAuth falls through to the existing API key + public flow unchanged.
		$request = $this->createMock(ServerRequestInterface::class);
		$request->method('getAttribute')
			->with('oauth_scopes')
			->willReturn(null);
		$this->authenticator->method('hasApiKeyHeader')->willReturn(false);
		$this->config->mcp = ['publicAccess' => true];

		$persona = $this->auth()->resolvePersona($request);

		$this->assertSame(McpPersona::PUBLIC_, $persona);
	}
}
