<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\OAuth\Adapter\LeagueClientEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeRepository;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;

final class LeagueScopeRepositoryTest extends TestCase
{
	private string $tmpFile;
	private OAuthClientRepository $clientRepo;
	private OAuthScopeRegistry $registry;
	private LeagueScopeRepository $adapter;

	protected function setUp(): void
	{
		$this->tmpFile    = sys_get_temp_dir() . '/oauth-clients-' . uniqid() . '.json';
		$this->clientRepo = new OAuthClientRepository($this->tmpFile);
		$this->registry   = new OAuthScopeRegistry();
		$this->adapter    = new LeagueScopeRepository($this->registry, $this->clientRepo);
	}

	protected function tearDown(): void
	{
		if (is_file($this->tmpFile)) {
			unlink($this->tmpFile);
		}
	}

	public function testGetScopeEntityByIdentifierReturnsEntityForKnownScope(): void
	{
		$entity = $this->adapter->getScopeEntityByIdentifier('cms:read');
		$this->assertNotNull($entity);
		$this->assertSame('cms:read', $entity->getIdentifier());
	}

	public function testGetScopeEntityByIdentifierReturnsNullForUnknownScope(): void
	{
		$entity = $this->adapter->getScopeEntityByIdentifier('not-a-scope');
		$this->assertNull($entity);
	}

	public function testFinalizeScopesReturnsIntersectionOfRequestedAndAllowed(): void
	{
		$client = $this->makeClient('c-1', ['cms:read', 'cms:write']);
		$this->clientRepo->save($client);

		$clientEntity   = new LeagueClientEntity($client);
		$requestedRead  = new LeagueScopeEntity('cms:read');
		$requestedAdmin = new LeagueScopeEntity('cms:admin');

		$result = $this->adapter->finalizeScopes(
			[$requestedRead, $requestedAdmin],
			'authorization_code',
			$clientEntity,
		);

		// Only cms:read is in the allowed list; cms:admin is not.
		$this->assertCount(1, $result);
		$this->assertSame('cms:read', $result[0]->getIdentifier());
	}

	public function testFinalizeScopesReturnsEmptyForUnknownClient(): void
	{
		$clientData   = $this->makeClient('c-does-not-exist', ['cms:read']);
		$clientEntity = new LeagueClientEntity($clientData);
		// Do NOT save the client — it should not be findable.

		$result = $this->adapter->finalizeScopes(
			[new LeagueScopeEntity('cms:read')],
			'authorization_code',
			$clientEntity,
		);

		$this->assertSame([], $result);
	}

	public function testFinalizeScopesExcludesScopesClientIsNotAllowed(): void
	{
		$client = $this->makeClient('c-2', ['cms:read']); // only cms:read allowed
		$this->clientRepo->save($client);

		$clientEntity = new LeagueClientEntity($client);

		$result = $this->adapter->finalizeScopes(
			[new LeagueScopeEntity('cms:write'), new LeagueScopeEntity('cms:admin')],
			'authorization_code',
			$clientEntity,
		);

		$this->assertSame([], $result);
	}

	private function makeClient(string $id, array $scopes): OAuthClientData
	{
		return new OAuthClientData(
			id: $id,
			name: 'Test ' . $id,
			secretHash: 'hash',
			redirectUris: ['https://x.test/cb'],
			scopes: $scopes,
			isDynamic: false,
			isConfidential: true,
			createdAt: '2026-05-24T00:00:00Z',
			createdBy: 'admin',
		);
	}
}
