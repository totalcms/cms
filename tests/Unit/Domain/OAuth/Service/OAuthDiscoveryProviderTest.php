<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Service\OAuthDiscoveryProvider;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Support\Config;

final class OAuthDiscoveryProviderTest extends TestCase
{
	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function makeConfig(string $url = 'https://example.com', array $oauth = [], string $api = ''): Config
	{
		$config     = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$reflection = new \ReflectionClass($config);

		$urlProp = $reflection->getProperty('url');
		$urlProp->setValue($config, $url);

		$oauthProp = $reflection->getProperty('oauth');
		$oauthProp->setValue($config, $oauth);

		// The subpath mount prefix ('' for a root install, '/tcms' for a subfolder).
		$apiProp = $reflection->getProperty('api');
		$apiProp->setValue($config, $api);

		return $config;
	}

	private function makeProvider(Config $config): OAuthDiscoveryProvider
	{
		return new OAuthDiscoveryProvider($config, new OAuthScopeRegistry());
	}

	// -------------------------------------------------------------------------
	// Required RFC 8414 fields
	// -------------------------------------------------------------------------

	public function testMetadataContainsAllRequiredRfc8414Fields(): void
	{
		$meta = $this->makeProvider($this->makeConfig())->metadata();

		$required = [
			'issuer',
			'authorization_endpoint',
			'token_endpoint',
			'jwks_uri',
			'scopes_supported',
			'response_types_supported',
			'grant_types_supported',
			'code_challenge_methods_supported',
			'token_endpoint_auth_methods_supported',
		];

		foreach ($required as $field) {
			$this->assertArrayHasKey($field, $meta, "Missing required RFC 8414 field: {$field}");
		}
	}

	// -------------------------------------------------------------------------
	// Issuer + endpoint URL construction
	// -------------------------------------------------------------------------

	public function testIssuerIsBuiltFromConfigUrl(): void
	{
		$meta = $this->makeProvider($this->makeConfig('https://mysite.com'))->metadata();

		$this->assertSame('https://mysite.com', $meta['issuer']);
	}

	public function testIssuerStripsTrailingSlash(): void
	{
		$meta = $this->makeProvider($this->makeConfig('https://mysite.com/'))->metadata();

		$this->assertSame('https://mysite.com', $meta['issuer']);
	}

	public function testEndpointUrlsArePrefixedWithIssuer(): void
	{
		$meta = $this->makeProvider($this->makeConfig('https://mysite.com'))->metadata();

		$this->assertSame('https://mysite.com/oauth/authorize', $meta['authorization_endpoint']);
		$this->assertSame('https://mysite.com/oauth/token', $meta['token_endpoint']);
		$this->assertSame('https://mysite.com/oauth/revoke', $meta['revocation_endpoint']);
		$this->assertSame('https://mysite.com/.well-known/jwks.json', $meta['jwks_uri']);
	}

	public function testExplicitJwtIssuerOverridesConfigUrl(): void
	{
		$meta = $this->makeProvider(
			$this->makeConfig('https://mysite.com', ['jwtIssuer' => 'https://auth.mysite.com']),
		)->metadata();

		$this->assertSame('https://auth.mysite.com', $meta['issuer']);
		$this->assertSame('https://auth.mysite.com/oauth/authorize', $meta['authorization_endpoint']);
	}

	public function testSubpathInstallIncludesBaseInIssuerAndEndpoints(): void
	{
		// App mounted at https://example.com/tcms/ — config.url is host-only,
		// config.api carries the subpath. Discovery must advertise the subpath
		// or clients hit 404s.
		$meta = $this->makeProvider(
			$this->makeConfig('https://example.com', ['dynamicRegistration' => true], '/tcms'),
		)->metadata();

		$this->assertSame('https://example.com/tcms', $meta['issuer']);
		$this->assertSame('https://example.com/tcms/oauth/authorize', $meta['authorization_endpoint']);
		$this->assertSame('https://example.com/tcms/oauth/token', $meta['token_endpoint']);
		$this->assertSame('https://example.com/tcms/oauth/revoke', $meta['revocation_endpoint']);
		$this->assertSame('https://example.com/tcms/.well-known/jwks.json', $meta['jwks_uri']);
		$this->assertSame('https://example.com/tcms/oauth/register', $meta['registration_endpoint']);
	}

	public function testSubpathStripsTrailingSlash(): void
	{
		$meta = $this->makeProvider(
			$this->makeConfig('https://example.com/', [], '/tcms/'),
		)->metadata();

		$this->assertSame('https://example.com/tcms', $meta['issuer']);
	}

	public function testExplicitJwtIssuerIgnoresSubpath(): void
	{
		// An operator-supplied issuer is a full URL — we must not append the
		// local mount prefix onto it.
		$meta = $this->makeProvider(
			$this->makeConfig('https://example.com', ['jwtIssuer' => 'https://auth.example.com'], '/tcms'),
		)->metadata();

		$this->assertSame('https://auth.example.com', $meta['issuer']);
		$this->assertSame('https://auth.example.com/oauth/authorize', $meta['authorization_endpoint']);
	}

	// -------------------------------------------------------------------------
	// Scopes
	// -------------------------------------------------------------------------

	public function testScopesSupportedContainsT3Scopes(): void
	{
		$meta   = $this->makeProvider($this->makeConfig())->metadata();
		$scopes = $meta['scopes_supported'];

		$this->assertIsArray($scopes);
		$this->assertContains('cms:read', $scopes);
		$this->assertContains('cms:write', $scopes);
		$this->assertContains('cms:admin', $scopes);
		$this->assertContains('mcp:tools', $scopes);
		$this->assertContains('mcp:resources', $scopes);
		$this->assertContains('mcp:prompts', $scopes);
		$this->assertCount(6, $scopes);
	}

	// -------------------------------------------------------------------------
	// Dynamic registration toggle
	// -------------------------------------------------------------------------

	public function testRegistrationEndpointPresentWhenDynamicRegistrationTrue(): void
	{
		$meta = $this->makeProvider(
			$this->makeConfig('https://mysite.com', ['dynamicRegistration' => true]),
		)->metadata();

		$this->assertArrayHasKey('registration_endpoint', $meta);
		$this->assertSame('https://mysite.com/oauth/register', $meta['registration_endpoint']);
	}

	public function testRegistrationEndpointAbsentWhenDynamicRegistrationFalse(): void
	{
		$meta = $this->makeProvider(
			$this->makeConfig('https://mysite.com', ['dynamicRegistration' => false]),
		)->metadata();

		$this->assertArrayNotHasKey('registration_endpoint', $meta);
	}

	public function testRegistrationEndpointAbsentByDefaultWhenOauthConfigEmpty(): void
	{
		// dynamicRegistration is off by default (secure-by-default) when not set,
		// so discovery must not advertise the registration endpoint.
		$meta = $this->makeProvider($this->makeConfig('https://mysite.com'))->metadata();

		$this->assertArrayNotHasKey('registration_endpoint', $meta);
	}

	// -------------------------------------------------------------------------
	// Grant types + PKCE defaults
	// -------------------------------------------------------------------------

	public function testDefaultGrantTypesIncludeAuthorizationCodeAndRefreshToken(): void
	{
		$meta = $this->makeProvider($this->makeConfig())->metadata();

		$this->assertContains('authorization_code', $meta['grant_types_supported']);
		$this->assertContains('refresh_token', $meta['grant_types_supported']);
	}

	public function testCustomGrantTypesAreRespected(): void
	{
		$meta = $this->makeProvider(
			$this->makeConfig('https://mysite.com', ['allowedGrantTypes' => ['authorization_code']]),
		)->metadata();

		$this->assertSame(['authorization_code'], $meta['grant_types_supported']);
	}

	public function testDefaultPkceMethodIsS256(): void
	{
		$meta = $this->makeProvider($this->makeConfig())->metadata();

		$this->assertContains('S256', $meta['code_challenge_methods_supported']);
	}

	public function testResponseTypesAlwaysContainsCode(): void
	{
		$meta = $this->makeProvider($this->makeConfig())->metadata();

		$this->assertSame(['code'], $meta['response_types_supported']);
	}
}
