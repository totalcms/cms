<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Utils;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Utils\OAuthPageData;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Support\Config;

final class OAuthPageDataTest extends TestCase
{
	private string $oauthClientsTmpFile;
	private string $oauthGrantsTmpFile;
	private OAuthClientRepository $oauthClientRepository;
	private OAuthGrantRepository $oauthGrantRepository;
	private OAuthScopeRegistry $oauthScopeRegistry;
	private MockObject $collectionLister;
	private MockObject $accessControlService;
	private MockObject $mcpSchemaResolver;
	private MockObject $request;
	private OAuthPageData $builder;

	protected function setUp(): void
	{
		$this->oauthClientsTmpFile   = sys_get_temp_dir() . '/oauth-clients-pagedata-test-' . uniqid() . '.json';
		$this->oauthGrantsTmpFile    = sys_get_temp_dir() . '/oauth-grants-pagedata-test-' . uniqid() . '.json';
		$this->oauthClientRepository = new OAuthClientRepository($this->oauthClientsTmpFile);
		$this->oauthGrantRepository  = new OAuthGrantRepository($this->oauthGrantsTmpFile);
		$this->oauthScopeRegistry    = new OAuthScopeRegistry();
		$this->collectionLister      = $this->createMock(CollectionLister::class);
		$this->accessControlService  = $this->createMock(AccessControlService::class);
		$this->mcpSchemaResolver     = $this->createMock(McpSchemaResolver::class);
		$this->request               = $this->createMock(ServerRequestInterface::class);

		$config       = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->auth = ['collection' => 'auth'];

		$this->builder = new OAuthPageData(
			$this->oauthClientRepository,
			$this->oauthGrantRepository,
			$this->oauthScopeRegistry,
			$this->collectionLister,
			$this->accessControlService,
			$this->mcpSchemaResolver,
			$config,
		);
	}

	protected function tearDown(): void
	{
		if (is_file($this->oauthClientsTmpFile)) {
			unlink($this->oauthClientsTmpFile);
		}
		if (is_file($this->oauthGrantsTmpFile)) {
			unlink($this->oauthGrantsTmpFile);
		}
	}

	public function testClientsPageBucketsStaticAndDynamicClientsWithActiveGrantCounts(): void
	{
		$this->oauthClientRepository->save(new OAuthClientData(
			id: 'static-1', name: 'Static App', secretHash: 'h', redirectUris: ['https://a/cb'],
			scopes: ['cms:read'], isDynamic: false, isConfidential: true,
			createdAt: '2026-01-01T00:00:00Z', createdBy: 'admin',
		));
		$this->oauthClientRepository->save(new OAuthClientData(
			id: 'dyn-1', name: 'Dynamic App', secretHash: 'h', redirectUris: ['https://b/cb'],
			scopes: ['cms:read'], isDynamic: true, isConfidential: false,
			createdAt: '2026-01-01T00:00:00Z', createdBy: 'admin',
		));
		$this->oauthGrantRepository->save(new OAuthGrantData(
			id: 'g-live', clientId: 'static-1', userId: 'u', scopes: ['cms:read'],
			refreshTokenHash: 'h1', issuedAt: '2026-01-01T00:00:00Z', expiresAt: '2099-01-01T00:00:00Z',
		));
		$this->oauthGrantRepository->save(new OAuthGrantData(
			id: 'g-expired', clientId: 'static-1', userId: 'u', scopes: ['cms:read'],
			refreshTokenHash: 'h2', issuedAt: '2020-01-01T00:00:00Z', expiresAt: '2020-02-01T00:00:00Z',
		));

		$data = $this->builder->build($this->request, 'oauth-clients', '');

		$this->assertCount(1, $data['oauthClients']['static']);
		$this->assertCount(1, $data['oauthClients']['dynamic']);
		$this->assertSame(1, $data['oauthClients']['static'][0]['grantCount'], 'expired grants do not count');
		$this->assertNull($data['oauthClientsForm']);
		$this->assertNull($data['oauthGrants']);
	}

	public function testClientsNewActionReturnsTheScopeListInsteadOfClients(): void
	{
		$data = $this->builder->build($this->request, 'oauth-clients', 'new');

		$this->assertNull($data['oauthClients']);
		$this->assertSame($this->oauthScopeRegistry->all(), $data['oauthClientsForm']['scopes']);
	}

	public function testGrantsPageJoinsGrantsToClientNamesNewestFirst(): void
	{
		$this->oauthClientRepository->save(new OAuthClientData(
			id: 'client-xyz', name: 'My OAuth App', secretHash: 'h', redirectUris: ['https://a/cb'],
			scopes: ['cms:read'], isDynamic: false, isConfidential: true,
			createdAt: '2026-01-01T00:00:00Z', createdBy: 'admin',
		));
		$this->oauthGrantRepository->save(new OAuthGrantData(
			id: 'old', clientId: 'client-xyz', userId: 'u@example.com', scopes: ['cms:read'],
			refreshTokenHash: 'h1', issuedAt: '2026-01-01T00:00:00Z', expiresAt: '2027-01-01T00:00:00Z',
		));
		$this->oauthGrantRepository->save(new OAuthGrantData(
			id: 'new', clientId: 'unknown-client', userId: 'u@example.com', scopes: ['cms:read'],
			refreshTokenHash: 'h2', issuedAt: '2026-06-01T00:00:00Z', expiresAt: '2027-06-01T00:00:00Z',
		));
		$this->collectionLister->method('listAllCollections')->willReturn([]);
		$this->accessControlService->method('userExists')->willReturn(false);

		$data = $this->builder->build($this->request, 'oauth-grants', '');

		$this->assertSame('new', $data['oauthGrants'][0]['grant']->id);
		$this->assertSame('unknown-client', $data['oauthGrants'][0]['clientName'], 'falls back to the id when the client is gone');
		$this->assertSame('My OAuth App', $data['oauthGrants'][1]['clientName']);
		$this->assertFalse($data['oauthGrants'][1]['isExpired']);
		$this->assertTrue($data['oauthGrants'][1]['effectiveReach']['userMissing']);
	}

	// ─── Effective reach ──────────────────────────────────────────────────
	// Moved from AdminUtilsActionTest. Same scenarios, asserted on the
	// builder's return value instead of the renderer callback.
	//
	// NOTE: the seed collections below ('blog' / 'pages', built via property
	// assignment) match AdminUtilsActionTest's ORIGINAL
	// effectiveReachForSingleGrant() helper (tests/Unit/Action/Admin/
	// AdminUtilsActionTest.php:639-702), not the array-constructor / 'products'
	// variant sketched in the task brief — CollectionData's constructor takes
	// no arguments, so `new CollectionData(['id' => ...])` cannot work. The
	// second parameter also stays nullable so the six verbatim-copied tests
	// below (which call this helper with only one argument) keep compiling.

	/**
	 * Seeds one client + one grant for the oauth-grants page and returns the
	 * single computed row's 'effectiveReach' array.
	 *
	 * $isAccessibleTo controls McpSchemaResolver::isAccessibleTo($collection,
	 * $persona) for the two collections seeded below ('blog', 'pages').
	 * Defaults to "nothing is exposed at any persona level" (the real default
	 * for a collection whose operator never touched mcp.access — it reads
	 * 'admin', so isAccessibleTo() is false for both 'public' and
	 * 'authenticated'). Tests that need a collection exposed pass their own
	 * callback: fn(CollectionData $c, string $persona): bool.
	 *
	 * @param  list<string>                                    $scopes
	 * @param  null|callable(CollectionData,string):bool        $isAccessibleTo
	 *
	 * @return array<string,mixed> the effectiveReach block of the single seeded grant
	 */
	private function effectiveReachForSingleGrant(array $scopes, ?callable $isAccessibleTo = null): array
	{
		$this->oauthClientRepository->save(new OAuthClientData(
			id: 'reach-client', name: 'Reach', secretHash: 'h', redirectUris: ['https://a/cb'],
			scopes: $scopes, isDynamic: false, isConfidential: true,
			createdAt: '2026-01-01T00:00:00Z', createdBy: 'admin',
		));
		$this->oauthGrantRepository->save(new OAuthGrantData(
			id: 'reach-grant', clientId: 'reach-client', userId: 'user-id', scopes: $scopes,
			refreshTokenHash: 'h', issuedAt: '2026-01-01T00:00:00Z', expiresAt: '2099-01-01T00:00:00Z',
		));

		$blog          = new CollectionData();
		$blog->id      = 'blog';
		$blog->schema  = 'blog';
		$blog->name    = 'Blog';
		$pages         = new CollectionData();
		$pages->id     = 'pages';
		$pages->schema = 'builder-page';
		$pages->name   = 'Pages';

		$this->collectionLister->method('listAllCollections')->willReturn([$blog, $pages]);
		$this->mcpSchemaResolver->method('isAccessibleTo')
			->willReturnCallback($isAccessibleTo ?? static fn (CollectionData $collection, string $persona): bool => false);

		$data = $this->builder->build($this->request, 'oauth-grants', '');

		return $data['oauthGrants'][0]['effectiveReach'];
	}

	public function testEffectiveReachListsOnlyTheirCollectionsForABloggerGrant(): void
	{
		$bloggerGroup = new AccessGroupData([
			'id'          => 'blogger',
			'permissions' => [
				'collections' => [
					'operations' => ['create', 'read', 'update', 'delete'],
					'all'        => false,
					'allowed'    => ['blog'],
				],
			],
		]);
		$authority = new UserAuthority(isAdmin: false, groups: [$bloggerGroup]);
		$this->accessControlService->method('userExists')->willReturn(true);
		$this->accessControlService->method('authorityFor')->willReturn($authority);

		// 'blog' has been opted into MCP for authenticated callers (e.g. the
		// operator set mcp.access: 'authenticated') — see the DEFAULT-exposure
		// counterpart test below, where the same group grant is NOT writable.
		$reach = $this->effectiveReachForSingleGrant(
			['cms:read', 'cms:write'],
			static fn (CollectionData $collection, string $persona): bool => $collection->id === 'blog' && $persona === 'authenticated',
		);

		$this->assertFalse($reach['fullAdmin']);
		$this->assertFalse($reach['userMissing']);
		$this->assertFalse($reach['noAccess']);
		$this->assertSame(['blog'], $reach['readable']);
		$this->assertSame(['blog'], $reach['writable']);
		$this->assertSame([], $reach['deleteOnly']);
	}

	public function testEffectiveReachHidesWriteWhenCollectionIsAtTheDefaultMcpAccess(): void
	{
		// Same blogger group grant as above, but 'blog' is left at the
		// DEFAULT mcp.access ('admin' — not exposed to any OAuth caller).
		// Every MCP write tool refuses this collection via
		// ObjectTools::requireExposed(), so the page must not claim it's
		// writable even though the access group grants create/update/delete.
		$bloggerGroup = new AccessGroupData([
			'id'          => 'blogger',
			'permissions' => [
				'collections' => [
					'operations' => ['create', 'read', 'update', 'delete'],
					'all'        => false,
					'allowed'    => ['blog'],
				],
			],
		]);
		$authority = new UserAuthority(isAdmin: false, groups: [$bloggerGroup]);

		$this->accessControlService->method('userExists')->willReturn(true);
		$this->accessControlService->method('authorityFor')->willReturn($authority);

		// No callback override — helper defaults to "not exposed at any
		// persona level", matching mcp.access's real default.
		$reach = $this->effectiveReachForSingleGrant(['cms:read', 'cms:write']);

		$this->assertSame(['blog'], $reach['readable']);
		$this->assertSame([], $reach['writable']);
		$this->assertSame([], $reach['deleteOnly']);
	}

	public function testEffectiveReachHidesWriteWhenTheGrantLacksTheWriteScope(): void
	{
		// Group grants full CRUD and the collection IS exposed to
		// authenticated callers, but the grant itself only carries cms:read —
		// the consent layer, not the group layer, is what's missing here.
		$bloggerGroup = new AccessGroupData([
			'id'          => 'blogger',
			'permissions' => [
				'collections' => [
					'operations' => ['create', 'read', 'update', 'delete'],
					'all'        => false,
					'allowed'    => ['blog'],
				],
			],
		]);
		$authority = new UserAuthority(isAdmin: false, groups: [$bloggerGroup]);

		$this->accessControlService->method('userExists')->willReturn(true);
		$this->accessControlService->method('authorityFor')->willReturn($authority);

		$reach = $this->effectiveReachForSingleGrant(
			['cms:read'],
			static fn (CollectionData $collection, string $persona): bool => $collection->id === 'blog' && $persona === 'authenticated',
		);

		$this->assertSame(['blog'], $reach['readable']);
		$this->assertSame([], $reach['writable']);
		$this->assertSame([], $reach['deleteOnly']);
	}

	public function testEffectiveReachListsAPublicCollectionAsReadableEvenWithoutTheReadScope(): void
	{
		// Grant carries only mcp:tools (no cms:read at all) — but 'blog' is
		// exposed mcp.access: 'public', so an authenticated caller must read
		// it exactly like an anonymous one would (PersonaContext::
		// canReadCollection()'s "authenticating must never subtract reach").
		$authority = new UserAuthority(isAdmin: false, groups: []);

		$this->accessControlService->method('userExists')->willReturn(true);
		$this->accessControlService->method('authorityFor')->willReturn($authority);

		$reach = $this->effectiveReachForSingleGrant(
			['mcp:tools'],
			static fn (CollectionData $collection, string $persona): bool => $collection->id === 'blog' && $persona === 'public',
		);

		$this->assertFalse($reach['noAccess']);
		$this->assertSame(['blog'], $reach['readable']);
		$this->assertSame([], $reach['writable']);
	}

	public function testEffectiveReachShowsNoAccessWhenUserHasNoGroups(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: []);

		$this->accessControlService->method('userExists')->willReturn(true);
		$this->accessControlService->method('authorityFor')->willReturn($authority);

		$reach = $this->effectiveReachForSingleGrant(['cms:read', 'cms:write']);

		$this->assertFalse($reach['fullAdmin']);
		$this->assertFalse($reach['userMissing']);
		$this->assertTrue($reach['noAccess']);
		$this->assertSame([], $reach['readable']);
		$this->assertSame([], $reach['writable']);
	}

	public function testEffectiveReachShowsFullAdministrativeAccessForAnAdminGrant(): void
	{
		$authority = new UserAuthority(isAdmin: true, groups: []);

		$this->accessControlService->method('userExists')->willReturn(true);
		$this->accessControlService->method('authorityFor')->willReturn($authority);

		$reach = $this->effectiveReachForSingleGrant(['cms:admin']);

		$this->assertTrue($reach['fullAdmin']);
		$this->assertFalse($reach['userMissing']);
		$this->assertFalse($reach['noAccess']);
		$this->assertSame([], $reach['readable']);
		$this->assertSame([], $reach['writable']);
	}

	public function testEffectiveReachShowsUserMissingForADeletedUser(): void
	{
		$this->accessControlService->method('userExists')->willReturn(false);
		$this->accessControlService->expects($this->never())->method('authorityFor');

		$reach = $this->effectiveReachForSingleGrant(['cms:read', 'cms:write']);

		$this->assertFalse($reach['fullAdmin']);
		$this->assertTrue($reach['userMissing']);
		$this->assertFalse($reach['noAccess']);
		$this->assertSame([], $reach['readable']);
		$this->assertSame([], $reach['writable']);
	}
}
