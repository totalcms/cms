<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Visualizer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Visualizer\Service\AccessGroupAnalyzer;

final class AccessGroupAnalyzerTest extends TestCase
{
	private CollectionLister&MockObject $collectionLister;
	private SchemaLister&MockObject $schemaLister;
	private ExtensionManager&MockObject $extensionManager;
	private AccessGroupLister&MockObject $accessGroupLister;
	private McpSchemaResolver&MockObject $mcpSchemaResolver;

	protected function setUp(): void
	{
		$this->collectionLister  = $this->createMock(CollectionLister::class);
		$this->schemaLister      = $this->createMock(SchemaLister::class);
		$this->extensionManager  = $this->createMock(ExtensionManager::class);
		$this->accessGroupLister = $this->createMock(AccessGroupLister::class);
		$this->mcpSchemaResolver = $this->createMock(McpSchemaResolver::class);

		// Mirror McpSchemaResolver::forCollection()'s real default: mcp.access
		// defaults to 'admin' (not exposed) when the collection carries none.
		$this->mcpSchemaResolver->method('forCollection')->willReturnCallback(
			static fn (CollectionData $c): array => [
				'access'        => (string)($c->mcp['access'] ?? 'admin'),
				'description'   => null,
				'resource'      => true,
				'titleProperty' => '',
			],
		);
	}

	private function makeAnalyzer(): AccessGroupAnalyzer
	{
		return new AccessGroupAnalyzer(
			$this->collectionLister,
			$this->schemaLister,
			$this->extensionManager,
			$this->accessGroupLister,
			$this->mcpSchemaResolver,
		);
	}

	/** @return CollectionData */
	private function makeCollection(string $id, ?string $mcpAccess = null): CollectionData
	{
		$c     = new CollectionData();
		$c->id = $id;
		if ($mcpAccess !== null) {
			$c->mcp = ['access' => $mcpAccess];
		}

		return $c;
	}

	private function makeSchema(string $id): SchemaData
	{
		$s     = new SchemaData();
		$s->id = $id;

		return $s;
	}

	/** @param array<string,mixed> $data */
	private function makeGroup(array $data): AccessGroupData
	{
		return new AccessGroupData($data);
	}

	private function setupResources(): void
	{
		$this->collectionLister->method('listAllCollections')->willReturn([
			$this->makeCollection('blog'),
			$this->makeCollection('products'),
		]);

		$this->schemaLister->method('listAllSchemas')->willReturn([
			$this->makeSchema('post'),
			$this->makeSchema('product'),
		]);

		$this->extensionManager->method('listExtensionsWithAdminSurface')->willReturn([
			'vendor/ext-a' => 'Extension A',
			'vendor/ext-b' => 'Extension B',
		]);
	}

	public function testCollectionsAllTrueGrantsFullOpsToEveryCollection(): void
	{
		$this->setupResources();

		$group = $this->makeGroup([
			'id'          => 'editors',
			'description' => 'Editors',
			'permissions' => [
				'collections' => [
					'all'        => true,
					'allowed'    => [],
					'operations' => ['read', 'create', 'update', 'delete'],
				],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);

		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$result = $this->makeAnalyzer()->analyze();

		$this->assertCount(1, $result);
		$entry = $result[0];
		$this->assertSame('editors', $entry['id']);
		$this->assertFalse($entry['isSuperAdmin']);

		$collections = $entry['dimensions']['collections'];
		$this->assertArrayHasKey('blog', $collections);
		$this->assertArrayHasKey('products', $collections);
		$this->assertTrue($collections['blog']['all']);
		$this->assertEqualsCanonicalizing(['read', 'create', 'update', 'delete'], $collections['blog']['ops']);
		$this->assertEqualsCanonicalizing(['read', 'create', 'update', 'delete'], $collections['products']['ops']);
	}

	public function testCollectionsAllowedListLimitsToSpecificCollection(): void
	{
		$this->setupResources();

		$group = $this->makeGroup([
			'id'          => 'readers',
			'description' => 'Readers',
			'permissions' => [
				'collections' => [
					'all'        => false,
					'allowed'    => ['blog'],
					'operations' => ['read', 'update'],
				],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);

		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$result      = $this->makeAnalyzer()->analyze();
		$collections = $result[0]['dimensions']['collections'];

		// blog is in the allowed list → gets the operations
		$this->assertEqualsCanonicalizing(['read', 'update'], $collections['blog']['ops']);
		$this->assertFalse($collections['blog']['all']);

		// products is not in the allowed list → empty ops
		$this->assertSame([], $collections['products']['ops']);
		$this->assertFalse($collections['products']['all']);
	}

	public function testUngrantedUtilStillAppearsWithEmptyOps(): void
	{
		$this->setupResources();

		$group = $this->makeGroup([
			'id'          => 'minimal',
			'description' => 'Minimal',
			'permissions' => [
				'collections'     => ['all' => false, 'allowed' => [], 'operations' => []],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);

		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$result = $this->makeAnalyzer()->analyze();
		$utils  = $result[0]['dimensions']['utils'];

		// Every util page must be present (the full canonical list)
		$this->assertArrayHasKey('cache', $utils);
		$this->assertArrayHasKey('jumpstart', $utils);
		$this->assertArrayHasKey('import', $utils);
		$this->assertArrayHasKey('log-analyzer', $utils);

		// None are granted
		foreach ($utils as $util => $entry) {
			$this->assertSame([], $entry['ops'], "Expected no ops for util: {$util}");
			$this->assertFalse($entry['all'], "Expected all=false for util: {$util}");
		}
	}

	public function testSuperAdminGroupResolvesToFullAccessOnAllResources(): void
	{
		$this->setupResources();

		$group = $this->makeGroup([
			'id'          => 'admin',
			'description' => 'Administrators',
			'permissions' => [
				'collections'     => ['all' => false, 'allowed' => [], 'operations' => []],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);

		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$result = $this->makeAnalyzer()->analyze();
		$entry  = $result[0];

		// The built-in 'admin' group ID is the super-admin sentinel
		$this->assertTrue($entry['isSuperAdmin']);

		$allOps = ['read', 'create', 'update', 'delete'];

		foreach (['blog', 'products'] as $collId) {
			$this->assertEqualsCanonicalizing($allOps, $entry['dimensions']['collections'][$collId]['ops']);
			$this->assertTrue($entry['dimensions']['collections'][$collId]['all']);
		}

		foreach (['post', 'product'] as $schemaId) {
			$this->assertEqualsCanonicalizing($allOps, $entry['dimensions']['schemas'][$schemaId]['ops']);
			$this->assertTrue($entry['dimensions']['schemas'][$schemaId]['all']);
		}

		foreach (array_keys($entry['dimensions']['utils']) as $utilId) {
			$this->assertTrue($entry['dimensions']['utils'][$utilId]['all']);
		}

		foreach (['vendor/ext-a', 'vendor/ext-b'] as $extId) {
			$this->assertTrue($entry['dimensions']['extensions'][$extId]['all']);
		}
	}

	public function testDimensionsResolvedIndependently(): void
	{
		$this->setupResources();

		$group = $this->makeGroup([
			'id'          => 'mixed',
			'description' => 'Mixed',
			'permissions' => [
				'collections' => [
					'all'        => true,
					'allowed'    => [],
					'operations' => ['read'],
				],
				'collectionsMeta' => [
					'all'        => false,
					'allowed'    => ['blog'],
					'operations' => ['read', 'update'],
				],
				'schemas' => [
					'all'        => false,
					'allowed'    => ['post'],
					'operations' => ['read'],
				],
				'utils'      => ['all' => true, 'allowed' => []],
				'extensions' => ['all' => true, 'allowed' => []],
			],
		]);

		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$result = $this->makeAnalyzer()->analyze();
		$dims   = $result[0]['dimensions'];

		// collections: all=true → every collection gets read
		$this->assertEqualsCanonicalizing(['read'], $dims['collections']['blog']['ops']);
		$this->assertTrue($dims['collections']['blog']['all']);

		// collectionsMeta: allowed=['blog'] → blog gets ops, products empty
		$this->assertEqualsCanonicalizing(['read', 'update'], $dims['collectionsMeta']['blog']['ops']);
		$this->assertSame([], $dims['collectionsMeta']['products']['ops']);

		// schemas: only post allowed
		$this->assertEqualsCanonicalizing(['read'], $dims['schemas']['post']['ops']);
		$this->assertSame([], $dims['schemas']['product']['ops']);

		// utils: all=true → regular utils are granted; super-admin-only utils remain hard-denied
		foreach ($dims['utils'] as $util => $entry) {
			if (in_array($util, AccessControlService::SUPER_ADMIN_ONLY_UTILS, true)) {
				$this->assertFalse($entry['all'], "super-admin-only util {$util} must be all=false even when utils.all=true");
			} else {
				$this->assertTrue($entry['all'], "util {$util} should be all=true");
			}
		}

		// extensions: all=true
		$this->assertTrue($dims['extensions']['vendor/ext-a']['all']);
	}

	/**
	 * I2: A non-super-admin group with utils.all=true must still have
	 * jumpstart (and every other SUPER_ADMIN_ONLY_UTILS entry) hard-denied.
	 */
	public function testSuperAdminOnlyUtilsDeniedForNonSuperAdminEvenWithAllTrue(): void
	{
		$this->setupResources();

		$group = $this->makeGroup([
			'id'          => 'privileged',
			'description' => 'Privileged',
			'permissions' => [
				'collections'     => ['all' => false, 'allowed' => [], 'operations' => []],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => true, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);

		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$result = $this->makeAnalyzer()->analyze();
		$utils  = $result[0]['dimensions']['utils'];

		foreach (AccessControlService::SUPER_ADMIN_ONLY_UTILS as $id) {
			$this->assertArrayHasKey($id, $utils, "Super-admin-only util '{$id}' must appear in the utils dimension");
			$this->assertSame([], $utils[$id]['ops'], "Super-admin-only util '{$id}' must be hard-denied (no ops) for non-super-admin");
			$this->assertFalse($utils[$id]['all'], "Super-admin-only util '{$id}' must have all=false for non-super-admin");
		}

		// Regular utils with all=true ARE granted
		$this->assertNotSame([], $utils['cache']['ops'], 'Regular util cache must be granted when all=true');
	}

	/**
	 * I2: The super-admin (admin) row must have jumpstart GRANTED.
	 */
	public function testSuperAdminGroupGrantsSuperAdminOnlyUtils(): void
	{
		$this->setupResources();

		$group = $this->makeGroup([
			'id'          => 'admin',
			'description' => 'Administrators',
			'permissions' => [
				'collections'     => ['all' => false, 'allowed' => [], 'operations' => []],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);

		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$result = $this->makeAnalyzer()->analyze();
		$utils  = $result[0]['dimensions']['utils'];

		$this->assertTrue($result[0]['isSuperAdmin']);

		foreach (AccessControlService::SUPER_ADMIN_ONLY_UTILS as $id) {
			$this->assertArrayHasKey($id, $utils, "Super-admin-only util '{$id}' must appear for admin");
			$this->assertTrue($utils[$id]['all'], "Super-admin-only util '{$id}' must be fully granted for super-admin");
		}
	}

	/**
	 * M3: Every entry in AccessControlService::SUPER_ADMIN_ONLY_UTILS must be
	 * present as a column in the analyzer's utils dimension, so the super-admin-only
	 * set can't silently drift from the matrix view.
	 */
	public function testSuperAdminOnlyUtilsAreSubsetOfAnalyzerUtilColumns(): void
	{
		$this->setupResources();

		$group = $this->makeGroup([
			'id'          => 'any',
			'description' => 'Any',
			'permissions' => [
				'collections'     => ['all' => false, 'allowed' => [], 'operations' => []],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);

		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$result    = $this->makeAnalyzer()->analyze();
		$utilKeys  = array_keys($result[0]['dimensions']['utils']);

		foreach (AccessControlService::SUPER_ADMIN_ONLY_UTILS as $id) {
			$this->assertContains(
				$id,
				$utilKeys,
				"AccessControlService::SUPER_ADMIN_ONLY_UTILS entry '{$id}' must be a column in the analyzer utils dimension",
			);
		}
	}

	public function testEveryCollectionAppearsForEveryGroup(): void
	{
		$this->setupResources();

		$group = $this->makeGroup([
			'id'          => 'partial',
			'description' => 'Partial',
			'permissions' => [
				'collections'     => ['all' => false, 'allowed' => ['blog'], 'operations' => ['read']],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);

		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$result      = $this->makeAnalyzer()->analyze();
		$collections = $result[0]['dimensions']['collections'];

		// Both collections appear, even though only 'blog' is granted
		$this->assertArrayHasKey('blog', $collections);
		$this->assertArrayHasKey('products', $collections);
	}

	// -----------------------------------------------------------------------
	// mcp (AI / MCP reach) dimension tests
	// -----------------------------------------------------------------------

	/**
	 * A group with a read-only grant on an MCP-exposed ('authenticated') collection
	 * shows 'read' and NOT 'create'/'update'.
	 */
	public function testReadOnlyGrantOnExposedCollectionShowsReadOnly(): void
	{
		$this->collectionLister->method('listAllCollections')->willReturn([
			$this->makeCollection('blog', 'authenticated'),
		]);
		$this->schemaLister->method('listAllSchemas')->willReturn([]);
		$this->extensionManager->method('listExtensionsWithAdminSurface')->willReturn([]);

		$group = $this->makeGroup([
			'id'          => 'readers',
			'description' => 'Readers',
			'permissions' => [
				'collections'     => ['all' => false, 'allowed' => ['blog'], 'operations' => ['read']],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);
		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$mcp = $this->makeAnalyzer()->analyze()[0]['dimensions']['mcp'];

		$this->assertArrayHasKey('blog', $mcp);
		$this->assertSame(['read'], $mcp['blog']['ops']);
		$this->assertFalse($mcp['blog']['all']);
	}

	/**
	 * A group with create+update grants on a collection left at the DEFAULT
	 * mcp.access ('admin', i.e. not exposed) never sees that collection in
	 * the mcp dimension at all — it's absent from the column set entirely,
	 * not merely blank.
	 */
	public function testCollectionAtDefaultMcpAccessIsAbsentFromMcpDimension(): void
	{
		$this->collectionLister->method('listAllCollections')->willReturn([
			$this->makeCollection('secrets'), // no mcp access set → defaults to 'admin'
		]);
		$this->schemaLister->method('listAllSchemas')->willReturn([]);
		$this->extensionManager->method('listExtensionsWithAdminSurface')->willReturn([]);

		$group = $this->makeGroup([
			'id'          => 'editors',
			'description' => 'Editors',
			'permissions' => [
				'collections'     => ['all' => true, 'allowed' => [], 'operations' => ['read', 'create', 'update', 'delete']],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);
		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$mcp = $this->makeAnalyzer()->analyze()[0]['dimensions']['mcp'];

		$this->assertArrayNotHasKey('secrets', $mcp);
		$this->assertSame([], $mcp);
	}

	/**
	 * A public-exposed collection shows 'read' for a group with NO explicit
	 * grant on it — public exposure alone is enough for the read op.
	 */
	public function testPublicExposedCollectionShowsReadWithNoExplicitGrant(): void
	{
		$this->collectionLister->method('listAllCollections')->willReturn([
			$this->makeCollection('news', 'public'),
		]);
		$this->schemaLister->method('listAllSchemas')->willReturn([]);
		$this->extensionManager->method('listExtensionsWithAdminSurface')->willReturn([]);

		$group = $this->makeGroup([
			'id'          => 'no-grants',
			'description' => 'No Grants',
			'permissions' => [
				'collections'     => ['all' => false, 'allowed' => [], 'operations' => []],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);
		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$mcp = $this->makeAnalyzer()->analyze()[0]['dimensions']['mcp'];

		$this->assertArrayHasKey('news', $mcp);
		$this->assertSame(['read'], $mcp['news']['ops']);
		$this->assertFalse($mcp['news']['all']);
	}

	/**
	 * 'delete' must never appear in a non-super-admin group's mcp ops, even
	 * with a full collections.all grant across all four operations — MCP
	 * ships no delete tool.
	 */
	public function testDeleteNeverAppearsInMcpOpsForNonSuperAdmin(): void
	{
		$this->collectionLister->method('listAllCollections')->willReturn([
			$this->makeCollection('blog', 'public'),
			$this->makeCollection('team', 'authenticated'),
		]);
		$this->schemaLister->method('listAllSchemas')->willReturn([]);
		$this->extensionManager->method('listExtensionsWithAdminSurface')->willReturn([]);

		$group = $this->makeGroup([
			'id'          => 'power',
			'description' => 'Power Users',
			'permissions' => [
				'collections'     => ['all' => true, 'allowed' => [], 'operations' => ['read', 'create', 'update', 'delete']],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);
		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$mcp = $this->makeAnalyzer()->analyze()[0]['dimensions']['mcp'];

		foreach ($mcp as $id => $entry) {
			$this->assertNotContains('delete', $entry['ops'], "'delete' must never appear for mcp:{$id}");
			$this->assertFalse($entry['all'], "mcp:{$id} 'all' must never be true for a non-super-admin group");
		}
		$this->assertSame(['read', 'create', 'update'], $mcp['blog']['ops']);
		$this->assertSame(['read', 'create', 'update'], $mcp['team']['ops']);
	}

	/**
	 * A site with no MCP-exposed collections produces an empty mcp dimension
	 * for every group — this is what lets the presenter's empty-column-group
	 * skip hide the whole dimension from the template.
	 */
	public function testNoMcpExposedCollectionsProducesEmptyMcpDimensionForEveryGroup(): void
	{
		$this->setupResources(); // blog + products, neither has mcp access set

		$group = $this->makeGroup([
			'id'          => 'editors',
			'description' => 'Editors',
			'permissions' => [
				'collections'     => ['all' => true, 'allowed' => [], 'operations' => ['read', 'create', 'update', 'delete']],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);
		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$mcp = $this->makeAnalyzer()->analyze()[0]['dimensions']['mcp'];

		$this->assertSame([], $mcp);
	}

	/**
	 * The super-admin row gets full access ('ALL' sentinel via all=true) on
	 * every MCP-exposed collection, consistent with every other dimension's
	 * super-admin treatment.
	 */
	public function testSuperAdminGetsFullAccessOnMcpExposedCollections(): void
	{
		$this->collectionLister->method('listAllCollections')->willReturn([
			$this->makeCollection('blog', 'public'),
			$this->makeCollection('team', 'authenticated'),
			$this->makeCollection('secrets'), // not exposed — must not appear at all
		]);
		$this->schemaLister->method('listAllSchemas')->willReturn([]);
		$this->extensionManager->method('listExtensionsWithAdminSurface')->willReturn([]);

		$group = $this->makeGroup([
			'id'          => 'admin',
			'description' => 'Administrators',
			'permissions' => [
				'collections'     => ['all' => false, 'allowed' => [], 'operations' => []],
				'collectionsMeta' => ['all' => false, 'allowed' => [], 'operations' => []],
				'schemas'         => ['all' => false, 'allowed' => [], 'operations' => []],
				'utils'           => ['all' => false, 'allowed' => []],
				'extensions'      => ['all' => false, 'allowed' => []],
			],
		]);
		$this->accessGroupLister->method('listAll')->willReturn([$group]);

		$entry = $this->makeAnalyzer()->analyze()[0];
		$this->assertTrue($entry['isSuperAdmin']);
		$mcp = $entry['dimensions']['mcp'];

		$this->assertArrayNotHasKey('secrets', $mcp);
		$this->assertTrue($mcp['blog']['all']);
		$this->assertTrue($mcp['team']['all']);
		$this->assertEqualsCanonicalizing(['read', 'create', 'update', 'delete'], $mcp['blog']['ops']);
	}
}
