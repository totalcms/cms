<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Adapter\LeagueClientRepository;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;

final class LeagueClientRepositoryTest extends TestCase
{
	private string $tmpFile;
	private OAuthClientRepository $repo;
	private LeagueClientRepository $adapter;

	protected function setUp(): void
	{
		$this->tmpFile = sys_get_temp_dir() . '/oauth-clients-' . uniqid() . '.json';
		$this->repo    = new OAuthClientRepository($this->tmpFile);
		$this->adapter = new LeagueClientRepository($this->repo);
	}

	protected function tearDown(): void
	{
		if (is_file($this->tmpFile)) {
			unlink($this->tmpFile);
		}
	}

	public function testGetClientEntityReturnsNullForUnknownClient(): void
	{
		$entity = $this->adapter->getClientEntity('unknown-id');
		$this->assertNull($entity);
	}

	public function testGetClientEntityReturnsEntityForKnownClient(): void
	{
		$this->repo->save($this->makeConfidentialClient('c-1'));

		$entity = $this->adapter->getClientEntity('c-1');
		$this->assertNotNull($entity);
		$this->assertSame('c-1', $entity->getIdentifier());
		$this->assertSame('Test c-1', $entity->getName());
		$this->assertTrue($entity->isConfidential());
	}

	public function testValidateClientReturnsFalseForUnknownClient(): void
	{
		$result = $this->adapter->validateClient('no-such-client', 'any-secret', 'authorization_code');
		$this->assertFalse($result);
	}

	public function testValidateClientReturnsTrueForCorrectSecret(): void
	{
		$plaintext = 'correct-secret';
		$hash      = password_hash($plaintext, PASSWORD_BCRYPT);
		$client    = $this->makeConfidentialClientWithHash('c-2', $hash);
		$this->repo->save($client);

		$result = $this->adapter->validateClient('c-2', $plaintext, 'authorization_code');
		$this->assertTrue($result);
	}

	public function testValidateClientReturnsFalseForWrongSecret(): void
	{
		$hash   = password_hash('correct-secret', PASSWORD_BCRYPT);
		$client = $this->makeConfidentialClientWithHash('c-3', $hash);
		$this->repo->save($client);

		$result = $this->adapter->validateClient('c-3', 'wrong-secret', 'authorization_code');
		$this->assertFalse($result);
	}

	public function testValidateClientReturnsFalseForNullSecretOnConfidentialClient(): void
	{
		$this->repo->save($this->makeConfidentialClient('c-4'));

		$result = $this->adapter->validateClient('c-4', null, 'authorization_code');
		$this->assertFalse($result);
	}

	public function testValidateClientReturnsTrueForNullSecretOnPublicClient(): void
	{
		$client = new OAuthClientData(
			id: 'c-5',
			name: 'Public App',
			secretHash: '',
			redirectUris: ['https://example.test/cb'],
			scopes: ['cms:read'],
			isDynamic: false,
			isConfidential: false,
			createdAt: '2026-05-24T00:00:00Z',
			createdBy: 'admin',
		);
		$this->repo->save($client);

		$result = $this->adapter->validateClient('c-5', null, 'authorization_code');
		$this->assertTrue($result);
	}

	private function makeConfidentialClient(string $id): OAuthClientData
	{
		return $this->makeConfidentialClientWithHash($id, password_hash('s3cr3t', PASSWORD_BCRYPT));
	}

	private function makeConfidentialClientWithHash(string $id, string $hash): OAuthClientData
	{
		return new OAuthClientData(
			id: $id,
			name: 'Test ' . $id,
			secretHash: $hash,
			redirectUris: ['https://example.test/cb'],
			scopes: ['cms:read'],
			isDynamic: false,
			isConfidential: true,
			createdAt: '2026-05-24T00:00:00Z',
			createdBy: 'admin',
		);
	}
}
