<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Mcp\Service\McpDescriptionResolver;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class McpSchemaResolverTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $schemaFetcher;
	private McpSchemaResolver $resolver;

	protected function setUp(): void
	{
		$this->schemaFetcher = $this->createMock(SchemaFetcher::class);
		$this->resolver      = new McpSchemaResolver(
			$this->schemaFetcher,
			new McpDescriptionResolver(),
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
}
