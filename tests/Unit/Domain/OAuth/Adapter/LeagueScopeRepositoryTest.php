<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OAuth\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\OAuth\Adapter\LeagueClientEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeEntity;
use TotalCMS\Domain\OAuth\Adapter\LeagueScopeRepository;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Support\Config;

final class LeagueScopeRepositoryTest extends TestCase
{
	private string $tmpFile;
	private OAuthClientRepository $clientRepo;
	private OAuthScopeRegistry $registry;
	private \PHPUnit\Framework\MockObject\MockObject $accessControl;
	private Config $config;
	private LeagueScopeRepository $adapter;

	protected function setUp(): void
	{
		$this->tmpFile       = sys_get_temp_dir() . '/oauth-clients-' . uniqid() . '.json';
		$this->clientRepo    = new OAuthClientRepository($this->tmpFile);
		$this->registry      = new OAuthScopeRegistry();
		$this->accessControl = $this->createMock(AccessControlService::class);
		$this->config        = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->auth  = ['collection' => 'auth'];
		$this->adapter       = new LeagueScopeRepository($this->registry, $this->clientRepo, $this->accessControl, $this->config);
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

	// ── user-privilege narrowing ───────────────────────────────────────────

	public function testFinalizeScopesStripsCmsAdminForNonAdminUser(): void
	{
		$client = $this->makeClient('c-3', ['cms:read', 'cms:admin', 'mcp:tools']);
		$this->clientRepo->save($client);
		$this->accessControl->method('isAdmin')->with('member-user')->willReturn(false);
		// Widened gate (Task 8): isAdmin() alone no longer decides — a non-admin
		// with no admin-domain access-group grants must still be stripped.
		$this->accessControl->method('authorityFor')->willReturn(UserAuthority::denied());

		$result = $this->adapter->finalizeScopes(
			[new LeagueScopeEntity('cms:read'), new LeagueScopeEntity('cms:admin'), new LeagueScopeEntity('mcp:tools')],
			'authorization_code',
			new LeagueClientEntity($client),
			'member-user',
		);

		$ids = array_map(static fn (LeagueScopeEntity|\League\OAuth2\Server\Entities\ScopeEntityInterface $s): string => $s->getIdentifier(), $result);
		sort($ids);
		$this->assertSame(['cms:read', 'mcp:tools'], $ids);
	}

	/**
	 * Widened issuance gate (Task 8, spec refinement): a non-admin user whose
	 * access groups grant SOME admin-domain permission (schemas,
	 * collectionsMeta, or a non-empty utils allow) can also convey cms:admin —
	 * UserAuthority::hasAdminDomainGrants() is the same check the MCP
	 * admin-domain tools' ToolRequirement guard uses. The group layer still
	 * caps what the resulting token can do; this only widens who may request
	 * the scope at all.
	 */
	public function testFinalizeScopesKeepsCmsAdminForNonAdminWithAdminDomainGrants(): void
	{
		$client = $this->makeClient('c-6', ['cms:read', 'cms:admin', 'mcp:tools']);
		$this->clientRepo->save($client);
		$this->accessControl->method('isAdmin')->with('schema-editor')->willReturn(false);

		// Default AccessGroupData permissions grant schemas {operations:[read],
		// all:true} — enough for hasAdminDomainGrants() to return true.
		$authority = new UserAuthority(isAdmin: false, groups: [new AccessGroupData(['id' => 'schema-editors'])]);
		$this->accessControl->method('authorityFor')->willReturn($authority);

		$result = $this->adapter->finalizeScopes(
			[new LeagueScopeEntity('cms:read'), new LeagueScopeEntity('cms:admin'), new LeagueScopeEntity('mcp:tools')],
			'authorization_code',
			new LeagueClientEntity($client),
			'schema-editor',
		);

		$ids = array_map(static fn (LeagueScopeEntity|\League\OAuth2\Server\Entities\ScopeEntityInterface $s): string => $s->getIdentifier(), $result);
		sort($ids);
		$this->assertSame(['cms:admin', 'cms:read', 'mcp:tools'], $ids);
	}

	public function testFinalizeScopesKeepsCmsAdminForAdminUser(): void
	{
		$client = $this->makeClient('c-4', ['cms:admin', 'mcp:tools']);
		$this->clientRepo->save($client);
		$this->accessControl->method('isAdmin')->with('joe')->willReturn(true);

		$result = $this->adapter->finalizeScopes(
			[new LeagueScopeEntity('cms:admin'), new LeagueScopeEntity('mcp:tools')],
			'authorization_code',
			new LeagueClientEntity($client),
			'joe',
		);

		$this->assertCount(2, $result);
	}

	public function testFinalizeScopesStripsCmsAdminWhenUserUnknown(): void
	{
		// No user identifier at all — fail closed on the privileged scope.
		$client = $this->makeClient('c-5', ['cms:admin', 'cms:read']);
		$this->clientRepo->save($client);

		$result = $this->adapter->finalizeScopes(
			[new LeagueScopeEntity('cms:admin'), new LeagueScopeEntity('cms:read')],
			'authorization_code',
			new LeagueClientEntity($client),
			null,
		);

		$this->assertCount(1, $result);
		$this->assertSame('cms:read', $result[0]->getIdentifier());
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
