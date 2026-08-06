<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;

/**
 * Access-group shapes mirror tests/tcms-data-fixtures/.system/access-groups.json
 * so the test intent ("a viewer-shaped authority", "a blogger-shaped authority")
 * matches the fixture's own naming.
 */
final class ToolRequirementVisibilityTest extends TestCase
{
	private function viewerGroup(): AccessGroupData
	{
		return new AccessGroupData([
			'id'          => 'viewer',
			'description' => 'Read-only access to view content',
			'permissions' => [
				'collectionsMeta' => ['operations' => ['read'], 'all' => true, 'allowed' => []],
				'collections'     => ['operations' => ['read'], 'all' => true, 'allowed' => []],
				'schemas'         => ['operations' => ['read'], 'all' => true, 'allowed' => []],
				'utils'           => ['all' => false, 'allowed' => []],
			],
		]);
	}

	private function bloggerGroup(): AccessGroupData
	{
		return new AccessGroupData([
			'id'          => 'blogger',
			'description' => 'Blog content creators',
			'permissions' => [
				'collectionsMeta' => ['operations' => ['read'], 'all' => false, 'allowed' => ['blog']],
				'collections'     => ['operations' => ['create', 'read', 'update', 'delete'], 'all' => false, 'allowed' => ['blog']],
				'schemas'         => ['operations' => ['read'], 'all' => false, 'allowed' => ['blog']],
				'utils'           => ['all' => false, 'allowed' => []],
			],
		]);
	}

	private function limitedBloggerGroup(): AccessGroupData
	{
		return new AccessGroupData([
			'id'          => 'limited-blogger',
			'description' => 'Read-only blog access',
			'permissions' => [
				'collectionsMeta' => ['operations' => ['read'], 'all' => false, 'allowed' => ['blog']],
				'collections'     => ['operations' => ['read'], 'all' => false, 'allowed' => ['blog']],
				'schemas'         => ['operations' => ['read'], 'all' => false, 'allowed' => []],
				'utils'           => ['all' => false, 'allowed' => []],
			],
		]);
	}

	// ── requiredScope() mapping ────────────────────────────────────────────

	public function testRequiredScopeObjectsReadIsCmsRead(): void
	{
		$req = new ToolRequirement('objects', 'read');

		$this->assertSame('cms:read', $req->requiredScope());
	}

	public function testRequiredScopeObjectsCreateIsCmsWrite(): void
	{
		$req = new ToolRequirement('objects', 'create');

		$this->assertSame('cms:write', $req->requiredScope());
	}

	public function testRequiredScopeObjectsUpdateIsCmsWrite(): void
	{
		$req = new ToolRequirement('objects', 'update');

		$this->assertSame('cms:write', $req->requiredScope());
	}

	public function testRequiredScopeObjectsDeleteIsCmsWrite(): void
	{
		$req = new ToolRequirement('objects', 'delete');

		$this->assertSame('cms:write', $req->requiredScope());
	}

	public function testRequiredScopeSchemasIsCmsAdmin(): void
	{
		$req = new ToolRequirement('schemas', 'read');

		$this->assertSame('cms:admin', $req->requiredScope());
	}

	public function testRequiredScopeCollectionsMetaIsCmsAdmin(): void
	{
		$req = new ToolRequirement('collections-meta', 'read');

		$this->assertSame('cms:admin', $req->requiredScope());
	}

	public function testRequiredScopeCacheIsCmsAdmin(): void
	{
		$req = new ToolRequirement('cache', 'read');

		$this->assertSame('cms:admin', $req->requiredScope());
	}

	public function testRequiredScopeSiteIsCmsAdmin(): void
	{
		$req = new ToolRequirement('site', 'read');

		$this->assertSame('cms:admin', $req->requiredScope());
	}

	// ── isSatisfiedFor() per domain ────────────────────────────────────────

	public function testIsSatisfiedForObjectsAllowsWhenGroupGrantsOp(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);
		$req       = new ToolRequirement('objects', 'update');

		$this->assertTrue($req->isSatisfiedFor($authority, 'blog'));
	}

	public function testIsSatisfiedForObjectsDeniesWhenGroupLacksOp(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [$this->viewerGroup()]);
		$req       = new ToolRequirement('objects', 'update');

		$this->assertFalse($req->isSatisfiedFor($authority, 'blog'));
	}

	public function testIsSatisfiedForObjectsDeniesWrongCollection(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);
		$req       = new ToolRequirement('objects', 'update');

		$this->assertFalse($req->isSatisfiedFor($authority, 'products'));
	}

	public function testIsSatisfiedForCollectionsMetaAllowsWhenGroupGrantsOp(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);
		$req       = new ToolRequirement('collections-meta', 'read');

		$this->assertTrue($req->isSatisfiedFor($authority, 'blog'));
	}

	public function testIsSatisfiedForCollectionsMetaDeniesUnknownCollection(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);
		$req       = new ToolRequirement('collections-meta', 'read');

		$this->assertFalse($req->isSatisfiedFor($authority, 'products'));
	}

	public function testIsSatisfiedForSchemasAllowsWhenGroupGrantsOp(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);
		$req       = new ToolRequirement('schemas', 'read');

		$this->assertTrue($req->isSatisfiedFor($authority, 'blog'));
	}

	public function testIsSatisfiedForSchemasDeniesWithoutGrant(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);
		$req       = new ToolRequirement('schemas', 'read');

		$this->assertFalse($req->isSatisfiedFor($authority, 'products'));
	}

	public function testIsSatisfiedForCacheAllowsWhenUtilGranted(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [
			new AccessGroupData([
				'id'          => 'cache-ops',
				'permissions' => ['utils' => ['all' => false, 'allowed' => ['cache']]],
			]),
		]);
		$req = new ToolRequirement('cache', 'read');

		$this->assertTrue($req->isSatisfiedFor($authority, 'cache'));
	}

	public function testIsSatisfiedForCacheDeniesWithoutUtilGrant(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [$this->viewerGroup()]);
		$req       = new ToolRequirement('cache', 'read');

		$this->assertFalse($req->isSatisfiedFor($authority, 'cache'));
	}

	public function testIsSatisfiedForSiteAllowsOnlyAdmin(): void
	{
		$admin    = new UserAuthority(isAdmin: true, groups: []);
		$nonAdmin = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);
		$req      = new ToolRequirement('site', 'read');

		$this->assertTrue($req->isSatisfiedFor($admin, 'site'));
		$this->assertFalse($req->isSatisfiedFor($nonAdmin, 'site'));
	}

	// ── isSatisfiedForAny() ─────────────────────────────────────────────────

	public function testIsSatisfiedForAnyTrueForAdminRegardlessOfDomain(): void
	{
		$admin = new UserAuthority(isAdmin: true, groups: []);

		$this->assertTrue((new ToolRequirement('objects', 'update'))->isSatisfiedForAny($admin));
		$this->assertTrue((new ToolRequirement('schemas', 'delete'))->isSatisfiedForAny($admin));
		$this->assertTrue((new ToolRequirement('site', 'read'))->isSatisfiedForAny($admin));
	}

	public function testIsSatisfiedForAnyFalseForViewerOnUpdateRequirement(): void
	{
		$viewer = new UserAuthority(isAdmin: false, groups: [$this->viewerGroup()]);
		$req    = new ToolRequirement('objects', 'update');

		$this->assertFalse($req->isSatisfiedForAny($viewer));
	}

	public function testIsSatisfiedForAnyTrueForBloggerOnUpdateRequirement(): void
	{
		$blogger = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);
		$req     = new ToolRequirement('objects', 'update');

		$this->assertTrue($req->isSatisfiedForAny($blogger));
	}

	public function testIsSatisfiedForAnyFalseForLimitedBloggerOnUpdateRequirement(): void
	{
		// limited-blogger can read 'blog' but not write it — enumerable, denied.
		$limited = new UserAuthority(isAdmin: false, groups: [$this->limitedBloggerGroup()]);
		$req     = new ToolRequirement('objects', 'update');

		$this->assertFalse($req->isSatisfiedForAny($limited));
	}

	public function testIsSatisfiedForAnyTrueForLimitedBloggerOnReadRequirement(): void
	{
		$limited = new UserAuthority(isAdmin: false, groups: [$this->limitedBloggerGroup()]);
		$req     = new ToolRequirement('objects', 'read');

		$this->assertTrue($req->isSatisfiedForAny($limited));
	}

	public function testIsSatisfiedForAnyTrueForViewerOnReadRequirement(): void
	{
		// viewer has collections.all=true + operations=['read'] — the bulk
		// canCollectionsOperation('read') check is true even though nothing
		// enumerates individual collection ids.
		$viewer = new UserAuthority(isAdmin: false, groups: [$this->viewerGroup()]);
		$req    = new ToolRequirement('objects', 'read');

		$this->assertTrue($req->isSatisfiedForAny($viewer));
	}

	public function testIsSatisfiedForAnyCollectionsMetaFalseWithoutAdminDomainGrants(): void
	{
		// A group with no schemas/collectionsMeta/utils grants at all.
		$noGrants = new UserAuthority(isAdmin: false, groups: [
			new AccessGroupData([
				'id'          => 'content-only',
				'permissions' => [
					'collectionsMeta' => ['operations' => [], 'all' => false, 'allowed' => []],
					'schemas'         => ['operations' => [], 'all' => false, 'allowed' => []],
					'utils'           => ['all' => false, 'allowed' => []],
				],
			]),
		]);
		$req = new ToolRequirement('collections-meta', 'read');

		$this->assertFalse($req->isSatisfiedForAny($noGrants));
	}

	public function testIsSatisfiedForAnyCollectionsMetaTrueWithAdminDomainGrants(): void
	{
		$blogger = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);
		$req     = new ToolRequirement('collections-meta', 'read');

		$this->assertTrue($req->isSatisfiedForAny($blogger));
	}

	public function testIsSatisfiedForAnySchemasTrueWithAdminDomainGrants(): void
	{
		$blogger = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);
		$req     = new ToolRequirement('schemas', 'read');

		$this->assertTrue($req->isSatisfiedForAny($blogger));
	}

	public function testIsSatisfiedForAnyCacheTrueWithUtilsGrant(): void
	{
		$authority = new UserAuthority(isAdmin: false, groups: [
			new AccessGroupData([
				'id'          => 'cache-ops',
				'permissions' => ['utils' => ['all' => false, 'allowed' => ['cache']]],
			]),
		]);
		$req = new ToolRequirement('cache', 'read');

		$this->assertTrue($req->isSatisfiedForAny($authority));
	}

	public function testIsSatisfiedForAnyCacheFalseWithoutAdminDomainGrants(): void
	{
		$viewer = new UserAuthority(isAdmin: false, groups: [$this->viewerGroup()]);
		$req    = new ToolRequirement('cache', 'read');

		$this->assertFalse($req->isSatisfiedForAny($viewer));
	}

	public function testIsSatisfiedForAnySiteFalseForNonAdmin(): void
	{
		$blogger = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);
		$req     = new ToolRequirement('site', 'read');

		$this->assertFalse($req->isSatisfiedForAny($blogger));
	}

	// ── McpToolDefinition::isVisibleTo() integration ────────────────────────

	/**
	 * $access defaults to 'admin' — these tests exercise the $requires OR
	 * branch specifically, which only matters when the tool's static $access
	 * wouldn't otherwise make it visible to AUTHENTICATED (i.e. it's not
	 * already 'public' or 'authenticated').
	 */
	private function toolWithRequirement(ToolRequirement $requires, string $access = 'admin'): McpToolDefinition
	{
		return new McpToolDefinition(
			name: 'update_blog',
			description: 'desc',
			access: $access,
			handler: static fn (): array => [],
			requires: $requires,
		);
	}

	public function testToolInvisibleToAuthenticatedWithViewerAuthority(): void
	{
		$tool      = $this->toolWithRequirement(new ToolRequirement('objects', 'update', 'collection'));
		$authority = new UserAuthority(isAdmin: false, groups: [$this->viewerGroup()]);

		$this->assertFalse($tool->isVisibleTo(McpPersona::AUTHENTICATED, $authority));
	}

	public function testToolVisibleToAuthenticatedWithBloggerAuthority(): void
	{
		$tool      = $this->toolWithRequirement(new ToolRequirement('objects', 'update', 'collection'));
		$authority = new UserAuthority(isAdmin: false, groups: [$this->bloggerGroup()]);

		$this->assertTrue($tool->isVisibleTo(McpPersona::AUTHENTICATED, $authority));
	}

	public function testToolAlwaysVisibleToAdminRegardlessOfAuthority(): void
	{
		$tool      = $this->toolWithRequirement(new ToolRequirement('objects', 'update', 'collection'));
		$authority = new UserAuthority(isAdmin: false, groups: [$this->viewerGroup()]);

		$this->assertTrue($tool->isVisibleTo(McpPersona::ADMIN, $authority));
		$this->assertTrue($tool->isVisibleTo(McpPersona::ADMIN, null));
	}

	public function testToolInvisibleToAuthenticatedWhenAuthorityIsNull(): void
	{
		$tool = $this->toolWithRequirement(new ToolRequirement('objects', 'update', 'collection'));

		$this->assertFalse($tool->isVisibleTo(McpPersona::AUTHENTICATED, null));
	}

	public function testToolWithoutRequirementFallsBackToExistingAccessRule(): void
	{
		$tool = new McpToolDefinition(
			name: 'plain',
			description: 'desc',
			access: 'authenticated',
			handler: static fn (): array => [],
		);

		$this->assertTrue($tool->isVisibleTo(McpPersona::AUTHENTICATED));
		$this->assertTrue($tool->isVisibleTo(McpPersona::AUTHENTICATED, null));
	}

	public function testPublicAccessToolWithRequirementIsInvisibleToPublicPersona(): void
	{
		// Task 7 fix round 1, finding #4: access:'public' + $requires used to
		// be fail-open — a PUBLIC_ caller has neither scopes nor a resolved
		// UserAuthority to check a requirement against, so a tool that
		// mistakenly combined the two would be registered for anonymous
		// callers and dispatched with zero enforcement (McpServerFactory::
		// guardHandler()'s enforcement body only runs for AUTHENTICATED).
		// $requires === null is now a hard second condition on the PUBLIC_
		// branch — a requirement-bearing tool is never visible to PUBLIC_
		// regardless of $access or how permissive $authority would be.
		$tool      = $this->toolWithRequirement(new ToolRequirement('objects', 'update', 'collection'), access: 'public');
		$authority = new UserAuthority(isAdmin: false, groups: [$this->viewerGroup()]);

		$this->assertFalse($tool->isVisibleTo(McpPersona::PUBLIC_, $authority));
		$this->assertFalse($tool->isVisibleTo(McpPersona::PUBLIC_, null));
	}

	public function testPublicAccessToolWithoutRequirementStillVisibleToPublicPersona(): void
	{
		// Regression guard for the fix above: a plain access:'public' tool
		// with no $requires must be unaffected.
		$tool = new McpToolDefinition(
			name: 'plain_public',
			description: 'desc',
			access: 'public',
			handler: static fn (): array => [],
		);

		$this->assertTrue($tool->isVisibleTo(McpPersona::PUBLIC_));
	}
}
