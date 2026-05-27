<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthClientCreator;
use TotalCMS\Domain\OAuth\Service\OAuthDynamicRegistrar;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;

final class OAuthDynamicRegistrarTest extends TestCase
{
	private string $tmpFile;
	private OAuthClientRepository $clients;
	private OAuthScopeRegistry $scopes;
	private OAuthDynamicRegistrar $registrar;

	protected function setUp(): void
	{
		$this->tmpFile = sys_get_temp_dir() . '/oauth-dyn-clients-' . uniqid() . '.json';
		$this->clients = new OAuthClientRepository($this->tmpFile);
		$this->scopes  = new OAuthScopeRegistry();
		$creator       = new OAuthClientCreator($this->clients, $this->scopes, new OAuthActivityLogger(new NullLogger()));
		$this->registrar = new OAuthDynamicRegistrar($creator, $this->scopes);
	}

	protected function tearDown(): void
	{
		if (is_file($this->tmpFile)) {
			unlink($this->tmpFile);
		}
	}

	/**
	 * Test 1: Valid payload returns RFC 7591 response shape.
	 */
	public function testValidPayloadReturnsRfc7591Shape(): void
	{
		$result = $this->registrar->register([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'My MCP Client',
			'scope'         => 'cms:read mcp:tools',
		]);

		$this->assertArrayHasKey('client_id', $result);
		$this->assertArrayHasKey('client_secret', $result);
		$this->assertArrayHasKey('client_secret_expires_at', $result);
		$this->assertArrayHasKey('registration_access_token', $result);
		$this->assertArrayHasKey('client_id_issued_at', $result);
		$this->assertArrayHasKey('redirect_uris', $result);
		$this->assertArrayHasKey('client_name', $result);
		$this->assertArrayHasKey('scope', $result);

		$this->assertIsString($result['client_id']);
		$this->assertIsString($result['client_secret']);
		$this->assertSame(0, $result['client_secret_expires_at']); // RFC 7591 §3.2.1: 0 = non-expiring
		$this->assertIsString($result['registration_access_token']);
		$this->assertIsInt($result['client_id_issued_at']);
		$this->assertSame(['https://app.test/cb'], $result['redirect_uris']);
		$this->assertSame('My MCP Client', $result['client_name']);
		$this->assertSame('cms:read mcp:tools', $result['scope']);
	}

	/**
	 * Test 2: Missing redirect_uris throws InvalidArgumentException.
	 */
	public function testMissingRedirectUrisThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/redirect_uris/i');

		$this->registrar->register([
			'client_name' => 'No URIs Client',
		]);
	}

	/**
	 * Test 3: Empty redirect_uris array throws.
	 */
	public function testEmptyRedirectUrisArrayThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/redirect_uris/i');

		$this->registrar->register([
			'redirect_uris' => [],
			'client_name'   => 'Empty URIs Client',
		]);
	}

	/**
	 * Test 4: Unknown scope throws InvalidArgumentException.
	 */
	public function testUnknownScopeThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/cms:bogus/');

		$this->registrar->register([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'Bad Scope Client',
			'scope'         => 'cms:bogus',
		]);
	}

	/**
	 * Test 5: Empty scope is allowed — should NOT throw; returns with empty scope.
	 */
	public function testEmptyScopeIsAllowed(): void
	{
		$result = $this->registrar->register([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'No Scope Client',
			'scope'         => '',
		]);

		$this->assertSame('', $result['scope']);
	}

	/**
	 * Test 5b: Absent scope key is also allowed.
	 */
	public function testAbsentScopeIsAllowed(): void
	{
		$result = $this->registrar->register([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'No Scope Client',
		]);

		$this->assertSame('', $result['scope']);
	}

	/**
	 * Test 6: client_name defaults to "Unnamed dynamic client" when missing.
	 */
	public function testClientNameDefaultsWhenMissing(): void
	{
		$result = $this->registrar->register([
			'redirect_uris' => ['https://app.test/cb'],
		]);

		$this->assertSame('Unnamed dynamic client', $result['client_name']);
	}

	/**
	 * Test 7: Resulting client has isDynamic = true.
	 */
	public function testResultingClientIsDynamic(): void
	{
		$result = $this->registrar->register([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'Dynamic Client',
			'scope'         => 'cms:read',
		]);

		$loaded = $this->clients->find($result['client_id']);
		$this->assertNotNull($loaded, 'Client must be persisted');
		$this->assertTrue($loaded->isDynamic, 'isDynamic must be true for self-registered clients');
	}

	/**
	 * Test 8: registration_access_token differs across calls — randomness check.
	 */
	public function testRegistrationAccessTokenDiffersAcrossCalls(): void
	{
		$result1 = $this->registrar->register([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'Client One',
		]);
		$result2 = $this->registrar->register([
			'redirect_uris' => ['https://app.test/cb'],
			'client_name'   => 'Client Two',
		]);

		$this->assertNotSame(
			$result1['registration_access_token'],
			$result2['registration_access_token'],
			'registration_access_token must be unique per registration',
		);
	}
}
