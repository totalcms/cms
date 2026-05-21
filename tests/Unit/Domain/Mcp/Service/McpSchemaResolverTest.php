<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpDescriptionResolver;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class McpSchemaResolverTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $collectionRepository;
	private McpSchemaResolver $resolver;

	protected function setUp(): void
	{
		$this->schemaFetcher        = $this->createMock(SchemaFetcher::class);
		$this->collectionRepository = $this->createMock(CollectionRepository::class);
		$this->resolver             = new McpSchemaResolver(
			$this->schemaFetcher,
			new McpDescriptionResolver(),
			$this->collectionRepository,
		);
	}

	private function collection(array $mcp = []): CollectionData
	{
		$collection         = new CollectionData();
		$collection->id     = 'blog';
		$collection->schema = 'blog';
		$collection->mcp    = $mcp;

		return $collection;
	}

	private function schemaWithProperties(array $properties): SchemaData
	{
		$schema             = new SchemaData();
		$schema->id         = 'blog';
		$schema->properties = $properties;

		return $schema;
	}

	// ─── Collection-level config resolution ───────────────────────────────────

	public function testForCollectionDefaultsAccessToAdmin(): void
	{
		$result = $this->resolver->forCollection($this->collection([]));

		$this->assertSame('admin', $result['access']);
	}

	public function testForCollectionHonorsStoredAccess(): void
	{
		$result = $this->resolver->forCollection($this->collection(['access' => 'public']));

		$this->assertSame('public', $result['access']);
	}

	public function testForCollectionRejectsInvalidAccessValueAsAdmin(): void
	{
		// Defensive: a corrupted/garbage access value falls back to the
		// safest interpretation (admin = deny anonymous).
		$result = $this->resolver->forCollection($this->collection(['access' => 'nonsense']));

		$this->assertSame('admin', $result['access']);
	}

	public function testForCollectionDefaultsResourceToTrue(): void
	{
		$result = $this->resolver->forCollection($this->collection([]));

		$this->assertTrue($result['resource']);
	}

	public function testForCollectionHonorsExplicitResourceFalse(): void
	{
		$result = $this->resolver->forCollection($this->collection(['resource' => false]));

		$this->assertFalse($result['resource']);
	}

	// ─── Persona access policy ────────────────────────────────────────────────

	public function testAdminAccessibleByAdminPersona(): void
	{
		$this->assertTrue($this->resolver->isAccessibleTo($this->collection(['access' => 'admin']), 'admin'));
	}

	public function testAdminNotAccessibleByPublicPersona(): void
	{
		$this->assertFalse($this->resolver->isAccessibleTo($this->collection(['access' => 'admin']), 'public'));
	}

	public function testPublicAccessibleByPublicPersona(): void
	{
		$this->assertTrue($this->resolver->isAccessibleTo($this->collection(['access' => 'public']), 'public'));
	}

	public function testPublicAccessibleByAdminPersona(): void
	{
		// Admin sees everything regardless of collection access.
		$this->assertTrue($this->resolver->isAccessibleTo($this->collection(['access' => 'public']), 'admin'));
	}

	public function testAuthenticatedSeesPublicCollections(): void
	{
		$this->assertTrue($this->resolver->isAccessibleTo($this->collection(['access' => 'public']), 'authenticated'));
	}

	// ─── filterableFields() — field-type inference ───────────────────────────

	public function testFilterableFieldsInfersFilterableForTextField(): void
	{
		$schema = $this->schemaWithProperties([
			'title' => ['field' => 'text', 'label' => 'Title'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$fields = $this->resolver->filterableFields($this->collection());

		$this->assertCount(1, $fields);
		$this->assertSame('title', $fields[0]['name']);
		$this->assertSame('text', $fields[0]['type']);
		$this->assertTrue($fields[0]['filterable']);
		$this->assertFalse($fields[0]['sortable']);
	}

	public function testFilterableFieldsInfersFilterableAndSortableForIdField(): void
	{
		// The `id` form-field type (used by every collection's slug-like identifier
		// — see resources/schemas/blog.json) is the natural key for filtering and
		// sorting. Treat it as filterable + sortable by default so the catalog
		// surfaces it without operators having to set explicit mcp flags.
		$schema = $this->schemaWithProperties([
			'id' => ['field' => 'id', 'label' => 'Blog Post ID'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$fields = $this->resolver->filterableFields($this->collection());

		$this->assertCount(1, $fields);
		$this->assertSame('id', $fields[0]['name']);
		$this->assertTrue($fields[0]['filterable']);
		$this->assertTrue($fields[0]['sortable']);
	}

	public function testFilterableFieldsInfersSortableForNumberAndDate(): void
	{
		$schema = $this->schemaWithProperties([
			'price' => ['field' => 'number', 'label' => 'Price'],
			'date'  => ['field' => 'date', 'label' => 'Date'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$fields = $this->resolver->filterableFields($this->collection());

		$this->assertTrue($fields[0]['filterable']);
		$this->assertTrue($fields[0]['sortable']);
		$this->assertTrue($fields[1]['filterable']);
		$this->assertTrue($fields[1]['sortable']);
	}

	public function testFilterableFieldsRespectsExplicitMcpFlags(): void
	{
		// Operator-set values override type-inferred defaults.
		$schema = $this->schemaWithProperties([
			'title' => [
				'field' => 'text',
				'mcp'   => ['filterable' => false, 'sortable' => true],
			],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$fields = $this->resolver->filterableFields($this->collection());

		$this->assertFalse($fields[0]['filterable']);
		$this->assertTrue($fields[0]['sortable']);
	}

	public function testFilterableFieldsStripsNonExposedFields(): void
	{
		// expose:false → field shouldn't appear in AI metadata at all.
		$schema = $this->schemaWithProperties([
			'title'          => ['field' => 'text'],
			'internal_notes' => ['field' => 'textarea', 'mcp' => ['expose' => false]],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$fields = $this->resolver->filterableFields($this->collection());

		$this->assertCount(1, $fields);
		$this->assertSame('title', $fields[0]['name']);
	}

	public function testFilterableFieldsIncludesDescriptionViaFallbackChain(): void
	{
		$schema = $this->schemaWithProperties([
			'a' => ['field' => 'text', 'mcp' => ['description' => 'mcp-desc']],
			'b' => ['field' => 'text', 'help' => 'help-desc'],
			'c' => ['field' => 'text', 'label' => 'C Label'],
			'd' => ['field' => 'text'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$fields = $this->resolver->filterableFields($this->collection());

		$this->assertSame('mcp-desc',   $fields[0]['description']);
		$this->assertSame('help-desc',  $fields[1]['description']);
		$this->assertSame('C Label',    $fields[2]['description']);
		$this->assertNull($fields[3]['description']);
	}

	// ─── isPropertyExposed ────────────────────────────────────────────────────

	public function testIsPropertyExposedDefaultsTrue(): void
	{
		$schema = $this->schemaWithProperties([
			'title' => ['field' => 'text'],
		]);

		$this->assertTrue($this->resolver->isPropertyExposed($schema, 'title'));
	}

	public function testIsPropertyExposedFalseWhenMcpExposeFalse(): void
	{
		$schema = $this->schemaWithProperties([
			'cost' => ['field' => 'number', 'mcp' => ['expose' => false]],
		]);

		$this->assertFalse($this->resolver->isPropertyExposed($schema, 'cost'));
	}

	public function testIsPropertyExposedTrueForUnknownProperty(): void
	{
		// Defensive: querying a property the schema doesn't define returns
		// true (default permissive — caller should validate property exists
		// before exposing decisions to logic).
		$schema = $this->schemaWithProperties([]);

		$this->assertTrue($this->resolver->isPropertyExposed($schema, 'missing'));
	}

	// ─── nonExposedFields() — list of fields to strip from MCP responses ──────

	public function testNonExposedFieldsReturnsEmptyListWhenAllExposed(): void
	{
		// When every property is exposed (or omits mcp.expose), tools have
		// nothing to strip and the helper returns an empty list.
		$schema = $this->schemaWithProperties([
			'title' => ['field' => 'text'],
			'body'  => ['field' => 'styledtext'],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->assertSame([], $this->resolver->nonExposedFields($this->collection()));
	}

	public function testNonExposedFieldsListsPropertiesWithMcpExposeFalse(): void
	{
		// Content tools (query_collection, get_object, search_collection)
		// strip these field names from each returned item.
		$schema = $this->schemaWithProperties([
			'title'    => ['field' => 'text'],
			'secret'   => ['field' => 'text', 'mcp' => ['expose' => false]],
			'admin_id' => ['field' => 'text', 'mcp' => ['expose' => false]],
		]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn($schema);

		$this->assertSame(['secret', 'admin_id'], $this->resolver->nonExposedFields($this->collection()));
	}

	// ─── renderCatalog() — dynamic description field catalog ──────────────────

	public function testRenderCatalogReturnsEmptyStringWhenNoCollectionsVisible(): void
	{
		// Fresh-install case: zero collections registered. Content tools should
		// emit a description with no catalog block at all (the static base
		// description carries the load).
		$this->collectionRepository->method('listAllCollections')->willReturn([]);

		$this->assertSame('', $this->resolver->renderCatalog(McpPersona::ADMIN));
	}

	public function testRenderCatalogFiltersByPersonaAccess(): void
	{
		// Public persona must NOT see admin-only collections in the catalog —
		// otherwise the AI agent gets pointed at tools it'll be refused from.
		$adminOnly = $this->collection(['access' => 'admin']);
		$adminOnly->id = 'auth';

		$publicBlog = $this->collection(['access' => 'public']);
		$publicBlog->id = 'blog';

		$this->collectionRepository->method('listAllCollections')->willReturn([$adminOnly, $publicBlog]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			$this->schemaWithProperties(['title' => ['field' => 'text']]),
		);

		$publicCatalog = $this->resolver->renderCatalog(McpPersona::PUBLIC_);
		$this->assertStringContainsString('blog', $publicCatalog);
		$this->assertStringNotContainsString('auth', $publicCatalog);

		// Admin persona sees both, regardless of per-collection access.
		$adminCatalog = $this->resolver->renderCatalog(McpPersona::ADMIN);
		$this->assertStringContainsString('blog', $adminCatalog);
		$this->assertStringContainsString('auth', $adminCatalog);
	}

	public function testRenderCatalogLineFormatIncludesFieldsWithTypes(): void
	{
		// Each collection line follows: `- <id> — <field> (<type>[, sortable])`
		// AI agent reads this to know what include/exclude values to compose.
		$collection     = $this->collection(['access' => 'public']);
		$collection->id = 'products';

		$this->collectionRepository->method('listAllCollections')->willReturn([$collection]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			$this->schemaWithProperties([
				'featured' => ['field' => 'toggle'],
				'price'    => ['field' => 'number'],
				'tags'     => ['field' => 'text', 'mcp' => ['filterable' => false, 'sortable' => false]],
			]),
		);

		$catalog = $this->resolver->renderCatalog(McpPersona::ADMIN);

		// Field metadata: toggle = filterable boolean, number = filterable + sortable.
		// Non-filterable, non-sortable fields are omitted from the line — they
		// aren't actionable for query composition.
		$this->assertStringContainsString('- products', $catalog);
		$this->assertStringContainsString('featured (toggle)', $catalog);
		$this->assertStringContainsString('price (number, sortable)', $catalog);
		$this->assertStringNotContainsString('tags', $catalog);
	}

	public function testRenderCatalogCapsAtThirtyCollectionsByDefault(): void
	{
		// Plan: cap at ~30 to keep tool descriptions inside MCP host context
		// budgets. Overflow rolls into the closing pointer at list_collections.
		$collections = [];
		for ($i = 1; $i <= 35; $i++) {
			$c     = $this->collection(['access' => 'public']);
			$c->id = sprintf('col%02d', $i);
			$collections[] = $c;
		}
		$this->collectionRepository->method('listAllCollections')->willReturn($collections);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			$this->schemaWithProperties(['title' => ['field' => 'text']]),
		);

		$catalog = $this->resolver->renderCatalog(McpPersona::ADMIN);

		// First 30 listed; "Plus 5 more" overflow line follows.
		$this->assertStringContainsString('- col01', $catalog);
		$this->assertStringContainsString('- col30', $catalog);
		$this->assertStringNotContainsString('- col31', $catalog);
		$this->assertStringContainsString('Plus 5 more', $catalog);
		$this->assertStringContainsString('list_collections', $catalog);
	}

	public function testRenderCatalogOrdersCollectionsAlphabetically(): void
	{
		// Stable order so tools/list responses are diff-friendly across runs
		// (same input → same description string).
		$banana          = $this->collection(['access' => 'public']);
		$banana->id      = 'banana';
		$apple           = $this->collection(['access' => 'public']);
		$apple->id       = 'apple';
		$cherry          = $this->collection(['access' => 'public']);
		$cherry->id      = 'cherry';

		$this->collectionRepository->method('listAllCollections')->willReturn([$banana, $cherry, $apple]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			$this->schemaWithProperties(['title' => ['field' => 'text']]),
		);

		$catalog = $this->resolver->renderCatalog(McpPersona::ADMIN);

		$applePos  = strpos($catalog, '- apple');
		$bananaPos = strpos($catalog, '- banana');
		$cherryPos = strpos($catalog, '- cherry');

		$this->assertNotFalse($applePos);
		$this->assertNotFalse($bananaPos);
		$this->assertNotFalse($cherryPos);
		$this->assertLessThan($bananaPos, $applePos);
		$this->assertLessThan($cherryPos, $bananaPos);
	}

	public function testRenderCatalogOmitsFieldDetailWhenNoFilterableFields(): void
	{
		// A collection whose schema has no filterable/sortable fields still
		// gets a catalog line — empty fields list is honest, not a bug.
		$collection     = $this->collection(['access' => 'public']);
		$collection->id = 'plain';

		$this->collectionRepository->method('listAllCollections')->willReturn([$collection]);
		$this->schemaFetcher->method('fetchSchemaForCollection')->willReturn(
			$this->schemaWithProperties([
				'note' => ['field' => 'text', 'mcp' => ['filterable' => false, 'sortable' => false]],
			]),
		);

		$catalog = $this->resolver->renderCatalog(McpPersona::ADMIN);

		$this->assertStringContainsString('- plain', $catalog);
		$this->assertStringContainsString('(no filterable fields)', $catalog);
	}
}
