<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\OAuthClientCreator;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;

final class OAuthClientCreatorTest extends TestCase
{
	private string $tmpFile;
	private OAuthClientRepository $clients;
	private OAuthScopeRegistry $scopes;
	private OAuthClientCreator $creator;

	protected function setUp(): void
	{
		$this->tmpFile = sys_get_temp_dir() . '/oauth-clients-' . uniqid() . '.json';
		$this->clients = new OAuthClientRepository($this->tmpFile);
		$this->scopes  = new OAuthScopeRegistry();
		$this->creator = new OAuthClientCreator($this->clients, $this->scopes);
	}

	protected function tearDown(): void
	{
		if (is_file($this->tmpFile)) {
			unlink($this->tmpFile);
		}
	}

	/**
	 * Test 1: Creates client + returns plaintext secret that differs from hash.
	 * password_verify must confirm the relationship.
	 */
	public function testCreatesClientAndReturnsPlaintextSecret(): void
	{
		$result = $this->creator->create(
			name:         'Test App',
			redirectUris: ['https://app.test/cb'],
			allowedScopes: ['cms:read'],
			createdBy:    'admin',
		);

		$this->assertArrayHasKey('client', $result);
		$this->assertArrayHasKey('secret', $result);

		$secret = $result['secret'];
		$hash   = $result['client']->secretHash;

		$this->assertNotSame($secret, $hash, 'Plaintext secret must not equal its hash');
		$this->assertTrue(
			password_verify($secret, $hash),
			'password_verify must confirm plaintext matches stored hash',
		);
	}

	/**
	 * Test 2: Stored client survives a lookup via OAuthClientRepository::find.
	 */
	public function testClientIsPersistedAndRetrievable(): void
	{
		$result = $this->creator->create(
			name:          'Persisted App',
			redirectUris:  ['https://app.test/cb'],
			allowedScopes: ['cms:read'],
			createdBy:     'admin',
		);

		$id     = $result['client']->id;
		$loaded = $this->clients->find($id);

		$this->assertNotNull($loaded, 'Client must be findable by id after creation');
		$this->assertSame('Persisted App', $loaded->name);
		$this->assertSame($id, $loaded->id);
	}

	/**
	 * Test 3: Unknown scope throws InvalidArgumentException.
	 */
	public function testUnknownScopeThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/cms:bogus/');

		$this->creator->create(
			name:          'Bad Scope App',
			redirectUris:  ['https://app.test/cb'],
			allowedScopes: ['cms:bogus'],
			createdBy:     'admin',
		);
	}

	/**
	 * Test 4: HTTPS redirect URI is accepted.
	 */
	public function testHttpsRedirectUriIsAccepted(): void
	{
		$result = $this->creator->create(
			name:          'HTTPS App',
			redirectUris:  ['https://app.test/cb'],
			allowedScopes: ['cms:read'],
			createdBy:     'admin',
		);

		$this->assertSame(['https://app.test/cb'], $result['client']->redirectUris);
	}

	/**
	 * Test 5: HTTP localhost redirect URIs are accepted.
	 */
	public function testHttpLocalhostRedirectUrisAreAccepted(): void
	{
		$result1 = $this->creator->create(
			name:          'Localhost App',
			redirectUris:  ['http://localhost/cb'],
			allowedScopes: ['cms:read'],
			createdBy:     'admin',
		);
		$this->assertSame(['http://localhost/cb'], $result1['client']->redirectUris);

		$result2 = $this->creator->create(
			name:          '127.0.0.1 App',
			redirectUris:  ['http://127.0.0.1/cb'],
			allowedScopes: ['cms:read'],
			createdBy:     'admin',
		);
		$this->assertSame(['http://127.0.0.1/cb'], $result2['client']->redirectUris);
	}

	/**
	 * Test 6: HTTP non-localhost redirect URI is rejected.
	 */
	public function testHttpNonLocalhostRedirectUriIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/HTTPS.*localhost/i');

		$this->creator->create(
			name:          'Attacker App',
			redirectUris:  ['http://attacker.test/cb'],
			allowedScopes: ['cms:read'],
			createdBy:     'admin',
		);
	}

	/**
	 * Test 7: Custom scheme is accepted (native-app pattern).
	 */
	public function testCustomSchemeRedirectUriIsAccepted(): void
	{
		$result = $this->creator->create(
			name:          'Native App',
			redirectUris:  ['claude://oauth/callback'],
			allowedScopes: ['cms:read'],
			createdBy:     'admin',
		);

		$this->assertSame(['claude://oauth/callback'], $result['client']->redirectUris);
	}

	/**
	 * Test 8: Invalid URL syntax is rejected.
	 */
	public function testInvalidUriSyntaxIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Invalid redirect URI/i');

		$this->creator->create(
			name:          'Bad URI App',
			redirectUris:  ['not-a-url'],
			allowedScopes: ['cms:read'],
			createdBy:     'admin',
		);
	}

	/**
	 * Test 9: Generated UUID matches UUIDv4 format.
	 */
	public function testGeneratedUuidMatchesV4Format(): void
	{
		$result = $this->creator->create(
			name:          'UUID Check App',
			redirectUris:  ['https://app.test/cb'],
			allowedScopes: ['cms:read'],
			createdBy:     'admin',
		);

		$id = $result['client']->id;
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
			$id,
			"Client id '{$id}' must match UUIDv4 format",
		);
	}

	/**
	 * Test 10: Two consecutive calls produce different ids + secrets.
	 */
	public function testTwoCallsProduceDifferentIdsAndSecrets(): void
	{
		$result1 = $this->creator->create(
			name:          'App One',
			redirectUris:  ['https://app.test/cb'],
			allowedScopes: ['cms:read'],
			createdBy:     'admin',
		);
		$result2 = $this->creator->create(
			name:          'App Two',
			redirectUris:  ['https://app.test/cb'],
			allowedScopes: ['cms:read'],
			createdBy:     'admin',
		);

		$this->assertNotSame($result1['client']->id, $result2['client']->id, 'IDs must be unique');
		$this->assertNotSame($result1['secret'], $result2['secret'], 'Secrets must be unique');
	}
}
