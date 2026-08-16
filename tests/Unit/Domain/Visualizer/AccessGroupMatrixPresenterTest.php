<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Visualizer;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Visualizer\Service\AccessGroupMatrixPresenter;

/**
 * Tests for AccessGroupMatrixPresenter::present().
 *
 * Cell-string convention under test:
 *   - ops present: fixed C/R/U/D order; present letter, · for absent.
 *     e.g. ['read','update'] → "·R·U"  (no, wait — C=create,R=read,U=update,D=delete)
 *     Operations map: C=create, R=read, U=update, D=delete
 *     e.g. all four → "CRUD", read+update → "·R·U", none → ""
 *   - all=true (super-admin or all-flag) → "ALL"
 *   - no ops and no all → ""
 *   - Single-op dimensions (access, utils) use "·" for denied, "✓" for granted.
 */
final class AccessGroupMatrixPresenterTest extends TestCase
{
	private function makePresenter(): AccessGroupMatrixPresenter
	{
		return new AccessGroupMatrixPresenter();
	}

	/**
	 * Minimal model: two groups, two collections, one schema, one extension, one util.
	 *
	 * @return list<array{id: string, name: string, isSuperAdmin: bool, dimensions: array<string, array<string, array{ops: list<string>, all: bool}>>}>
	 */
	private function makeModel(): array
	{
		return [
			[
				'id'           => 'editors',
				'name'         => 'Editors',
				'isSuperAdmin' => false,
				'dimensions'   => [
					'collections' => [
						'blog'     => ['ops' => ['read', 'create', 'update', 'delete'], 'all' => true],
						'products' => ['ops' => ['read', 'update'], 'all' => false],
					],
					'collectionsMeta' => [
						'blog'     => ['ops' => [], 'all' => false],
						'products' => ['ops' => [], 'all' => false],
					],
					'schemas' => [
						'post' => ['ops' => ['read'], 'all' => false],
					],
					'extensions' => [
						'vendor/ext-a' => ['ops' => ['access'], 'all' => false],
					],
					'utils' => [
						'cache'     => ['ops' => ['access'], 'all' => false],
						'jumpstart' => ['ops' => [], 'all' => false],
					],
				],
			],
			[
				'id'           => 'admin',
				'name'         => 'Administrators',
				'isSuperAdmin' => true,
				'dimensions'   => [
					'collections' => [
						'blog'     => ['ops' => ['read', 'create', 'update', 'delete'], 'all' => true],
						'products' => ['ops' => ['read', 'create', 'update', 'delete'], 'all' => true],
					],
					'collectionsMeta' => [
						'blog'     => ['ops' => ['read', 'create', 'update', 'delete'], 'all' => true],
						'products' => ['ops' => ['read', 'create', 'update', 'delete'], 'all' => true],
					],
					'schemas' => [
						'post' => ['ops' => ['read', 'create', 'update', 'delete'], 'all' => true],
					],
					'extensions' => [
						'vendor/ext-a' => ['ops' => ['access'], 'all' => true],
					],
					'utils' => [
						'cache'     => ['ops' => ['access'], 'all' => true],
						'jumpstart' => ['ops' => ['access'], 'all' => true],
					],
				],
			],
			[
				'id'           => 'guests',
				'name'         => 'Guests',
				'isSuperAdmin' => false,
				'dimensions'   => [
					'collections' => [
						'blog'     => ['ops' => [], 'all' => false],
						'products' => ['ops' => [], 'all' => false],
					],
					'collectionsMeta' => [
						'blog'     => ['ops' => [], 'all' => false],
						'products' => ['ops' => [], 'all' => false],
					],
					'schemas' => [
						'post' => ['ops' => [], 'all' => false],
					],
					'extensions' => [
						'vendor/ext-a' => ['ops' => [], 'all' => false],
					],
					'utils' => [
						'cache'     => ['ops' => [], 'all' => false],
						'jumpstart' => ['ops' => [], 'all' => false],
					],
				],
			],
		];
	}

	// -----------------------------------------------------------------------
	// Column-group ordering tests
	// -----------------------------------------------------------------------

	public function testColumnGroupsAreInCorrectDimensionOrder(): void
	{
		$result       = $this->makePresenter()->present($this->makeModel());
		$dimensions   = array_column($result['columnGroups'], 'dimension');

		$this->assertSame(
			['schemas', 'collectionsMeta', 'collections', 'mcp', 'utils', 'extensions'],
			$dimensions,
			'Dimension order must be: schemas, collectionsMeta, collections, mcp, utils, extensions',
		);
	}

	public function testColumnGroupLabelsAreHumanReadable(): void
	{
		$result      = $this->makePresenter()->present($this->makeModel());
		$labelsByDim = array_column($result['columnGroups'], 'label', 'dimension');

		$this->assertSame('Collection Objects', $labelsByDim['collections']);
		$this->assertSame('Utils', $labelsByDim['utils']);
		$this->assertSame('Schemas', $labelsByDim['schemas']);
		$this->assertSame('Extensions', $labelsByDim['extensions']);
		$this->assertSame('Collection Settings', $labelsByDim['collectionsMeta']);
		$this->assertSame('AI / MCP', $labelsByDim['mcp']);
	}

	public function testColumnsWithinEachDimensionAreAlphabeticByResourceId(): void
	{
		$result = $this->makePresenter()->present($this->makeModel());

		// Find the collections column-group
		$collectionsGroup = null;
		foreach ($result['columnGroups'] as $cg) {
			if ($cg['dimension'] === 'collections') {
				$collectionsGroup = $cg;
				break;
			}
		}

		$this->assertNotNull($collectionsGroup);
		$colIds = array_column($collectionsGroup['columns'], 'id');
		$this->assertSame(['blog', 'products'], $colIds, 'Collection columns must be alphabetical');
	}

	// -----------------------------------------------------------------------
	// Row ordering tests
	// -----------------------------------------------------------------------

	public function testNonSuperAdminRowsSortAlphabeticallyByName(): void
	{
		$result   = $this->makePresenter()->present($this->makeModel());
		$rowNames = array_column($result['rows'], 'name');

		// Non-super-admin rows: Editors and Guests alphabetically, then Administrators last
		$this->assertSame('Editors', $rowNames[0]);
		$this->assertSame('Guests', $rowNames[1]);
	}

	public function testSuperAdminRowIsLast(): void
	{
		$result   = $this->makePresenter()->present($this->makeModel());
		$rows     = $result['rows'];
		$lastRow  = end($rows);

		$this->assertTrue($lastRow['isSuperAdmin'], 'Super-admin row must sort last');
		$this->assertSame('admin', $lastRow['id']);
	}

	// -----------------------------------------------------------------------
	// Cell string convention tests
	// -----------------------------------------------------------------------

	public function testAllFlagProducesALLCellString(): void
	{
		$result = $this->makePresenter()->present($this->makeModel());

		// Editors' blog collection has all=true → "ALL"
		$editorsRow = null;
		foreach ($result['rows'] as $row) {
			if ($row['id'] === 'editors') {
				$editorsRow = $row;
				break;
			}
		}
		$this->assertNotNull($editorsRow);
		$this->assertSame('ALL', $editorsRow['cells']['collections:blog']);
	}

	public function testPartialCrudProducesPaddedString(): void
	{
		$result = $this->makePresenter()->present($this->makeModel());

		// Editors' products collection: ops = [read, update], all=false
		// Fixed order is C/R/U/D: create=· read=R update=U delete=· → "·RU·"
		$editorsRow = null;
		foreach ($result['rows'] as $row) {
			if ($row['id'] === 'editors') {
				$editorsRow = $row;
				break;
			}
		}
		$this->assertNotNull($editorsRow);
		$this->assertSame('·RU·', $editorsRow['cells']['collections:products']);
	}

	public function testNoOpsProducesEmptyString(): void
	{
		$result = $this->makePresenter()->present($this->makeModel());

		// Guests have no access to blog
		$guestsRow = null;
		foreach ($result['rows'] as $row) {
			if ($row['id'] === 'guests') {
				$guestsRow = $row;
				break;
			}
		}
		$this->assertNotNull($guestsRow);
		$this->assertSame('', $guestsRow['cells']['collections:blog']);
	}

	public function testFullCrudWithoutAllFlagProducesCRUD(): void
	{
		// Build a model where a non-super-admin has all 4 ops but all=false
		$model = [
			[
				'id'           => 'power',
				'name'         => 'Power Users',
				'isSuperAdmin' => false,
				'dimensions'   => [
					'collections' => [
						'news' => ['ops' => ['create', 'read', 'update', 'delete'], 'all' => false],
					],
					'collectionsMeta' => [],
					'schemas'         => [],
					'extensions'      => [],
					'utils'           => [],
				],
			],
		];

		$result    = $this->makePresenter()->present($model);
		$powerRow  = $result['rows'][0];
		$this->assertSame('CRUD', $powerRow['cells']['collections:news']);
	}

	public function testSingleReadOpProducesCorrectPadding(): void
	{
		// Read-only: ops=['read'] → "·R··"
		$model = [
			[
				'id'           => 'readonly',
				'name'         => 'Read Only',
				'isSuperAdmin' => false,
				'dimensions'   => [
					'collections' => [
						'blog' => ['ops' => ['read'], 'all' => false],
					],
					'collectionsMeta' => [],
					'schemas'         => [],
					'extensions'      => [],
					'utils'           => [],
				],
			],
		];

		$result = $this->makePresenter()->present($model);
		$this->assertSame('·R··', $result['rows'][0]['cells']['collections:blog']);
	}

	public function testAccessOpDimensionGrantedProducesCheckmark(): void
	{
		$result = $this->makePresenter()->present($this->makeModel());

		// Editors have access to cache util
		$editorsRow = null;
		foreach ($result['rows'] as $row) {
			if ($row['id'] === 'editors') {
				$editorsRow = $row;
				break;
			}
		}
		$this->assertNotNull($editorsRow);
		$this->assertSame('✓', $editorsRow['cells']['utils:cache']);
	}

	public function testAccessOpDimensionDeniedProducesDot(): void
	{
		$result = $this->makePresenter()->present($this->makeModel());

		// Editors have no access to jumpstart util
		$editorsRow = null;
		foreach ($result['rows'] as $row) {
			if ($row['id'] === 'editors') {
				$editorsRow = $row;
				break;
			}
		}
		$this->assertNotNull($editorsRow);
		$this->assertSame('·', $editorsRow['cells']['utils:jumpstart']);
	}

	public function testSuperAdminUtilWithAllFlagProducesALL(): void
	{
		$result = $this->makePresenter()->present($this->makeModel());

		// Admin (super-admin) has all=true on cache util → "ALL"
		$adminRow = null;
		foreach ($result['rows'] as $row) {
			if ($row['id'] === 'admin') {
				$adminRow = $row;
				break;
			}
		}
		$this->assertNotNull($adminRow);
		$this->assertSame('ALL', $adminRow['cells']['utils:cache']);
	}

	public function testCellsKeyedByDimensionColonResourceId(): void
	{
		$result     = $this->makePresenter()->present($this->makeModel());
		$editorsRow = null;
		foreach ($result['rows'] as $row) {
			if ($row['id'] === 'editors') {
				$editorsRow = $row;
				break;
			}
		}
		$this->assertNotNull($editorsRow);
		$this->assertArrayHasKey('collections:blog', $editorsRow['cells']);
		$this->assertArrayHasKey('schemas:post', $editorsRow['cells']);
		$this->assertArrayHasKey('utils:cache', $editorsRow['cells']);
		$this->assertArrayHasKey('extensions:vendor/ext-a', $editorsRow['cells']);
	}

	public function testEmptyModelReturnsEmptyRowsAndColumnGroups(): void
	{
		$result = $this->makePresenter()->present([]);
		$this->assertSame([], $result['rows']);
		// columnGroups still has the 6 dimension groups, but with no columns
		foreach ($result['columnGroups'] as $cg) {
			$this->assertSame([], $cg['columns']);
		}
	}

	// -----------------------------------------------------------------------
	// mcp dimension formatting (the presenter is generic — it just applies the
	// same CRUD-letter encoding to whatever ops the analyzer hands it; these
	// tests confirm the 'mcp' key is wired through the same CRUD_DIMENSIONS path)
	// -----------------------------------------------------------------------

	public function testMcpDimensionIsAbsentWhenNoGroupHasAnyMcpColumns(): void
	{
		// makeModel() groups carry no 'mcp' key at all in their dimensions.
		$result   = $this->makePresenter()->present($this->makeModel());
		$mcpGroup = null;
		foreach ($result['columnGroups'] as $cg) {
			if ($cg['dimension'] === 'mcp') {
				$mcpGroup = $cg;
			}
		}
		$this->assertNotNull($mcpGroup);
		$this->assertSame([], $mcpGroup['columns'], 'A site with no mcp data must produce zero mcp columns');
	}

	public function testMcpCellWithReadCreateUpdateNeverShowsDeleteLetter(): void
	{
		$model = [
			[
				'id'           => 'writer',
				'name'         => 'Writer',
				'isSuperAdmin' => false,
				'dimensions'   => [
					'collections'     => [],
					'collectionsMeta' => [],
					'schemas'         => [],
					'mcp'             => [
						'blog' => ['ops' => ['read', 'create', 'update'], 'all' => false],
					],
					'extensions' => [],
					'utils'      => [],
				],
			],
		];

		$result = $this->makePresenter()->present($model);
		// Fixed C/R/U/D order: create=C read=R update=U delete=· → "CRU·"
		$this->assertSame('CRU·', $result['rows'][0]['cells']['mcp:blog']);
	}

	public function testMcpCellWithReadOnlyPads(): void
	{
		$model = [
			[
				'id'           => 'reader',
				'name'         => 'Reader',
				'isSuperAdmin' => false,
				'dimensions'   => [
					'collections'     => [],
					'collectionsMeta' => [],
					'schemas'         => [],
					'mcp'             => [
						'blog' => ['ops' => ['read'], 'all' => false],
					],
					'extensions' => [],
					'utils'      => [],
				],
			],
		];

		$result = $this->makePresenter()->present($model);
		$this->assertSame('·R··', $result['rows'][0]['cells']['mcp:blog']);
	}

	public function testMcpSuperAdminAllFlagProducesALL(): void
	{
		$model = [
			[
				'id'           => 'admin',
				'name'         => 'Administrators',
				'isSuperAdmin' => true,
				'dimensions'   => [
					'collections'     => [],
					'collectionsMeta' => [],
					'schemas'         => [],
					'mcp'             => [
						'blog' => ['ops' => ['read', 'create', 'update', 'delete'], 'all' => true],
					],
					'extensions' => [],
					'utils'      => [],
				],
			],
		];

		$result = $this->makePresenter()->present($model);
		$this->assertSame('ALL', $result['rows'][0]['cells']['mcp:blog']);
	}
}
