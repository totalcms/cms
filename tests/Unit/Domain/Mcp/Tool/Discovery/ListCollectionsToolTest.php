<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Discovery;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Discovery\ListCollectionsTool;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

final class ListCollectionsToolTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $collections;
	private \PHPUnit\Framework\MockObject\MockObject $resolver;
	private PersonaContext $persona;
	private ListCollectionsTool $tool;

	protected function setUp(): void
	{
		$this->collections = $this->createMock(CollectionRepository::class);
		$this->resolver    = $this->createMock(McpSchemaResolver::class);
		$this->persona     = new PersonaContext();

		$this->tool = new ListCollectionsTool(
			$this->collections,
			$this->resolver,
			$this->persona,
		);
	}

	private function collection(string $id, string $access = 'public', string $url = '', int $totalObjects = 0): CollectionData
	{
		$collection               = new CollectionData();
		$collection->id           = $id;
		$collection->schema       = $id;
		$collection->url          = $url;
		$collection->mcp          = ['access' => $access];
		$collection->totalObjects = $totalObjects;

		return $collection;
	}

	// ─── Registration ────────────────────────────────────────────────────────

	public function testRegisterAddsToolWithExpectedNameAndPublicAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$definition = $registry->get('list_collections');
		$this->assertNotNull($definition);
		$this->assertSame('public', $definition->access);
	}

	public function testInputSchemaTakesNoArguments(): void
	{
		// Discovery is parameterless — the persona context determines the
		// caller-visible set, no argument tweaks that. `properties` is a
		// stdClass so it serialises to `{}` on the wire; empty PHP arrays
		// would become `[]` and break the SDK's JSON Schema validator.
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$schema = $registry->get('list_collections')->inputSchema;

		$this->assertIsArray($schema);
		$this->assertInstanceOf(\stdClass::class, $schema['properties']);
		$this->assertSame(false, $schema['additionalProperties'] ?? null);
		// And confirm it actually serialises to {} not [].
		$this->assertSame('{}', (string)json_encode($schema['properties']));
	}

	public function testStaticDescriptionMentionsSiblingDiscoveryAndContentTools(): void
	{
		// The lean shape points at describe_collection for per-collection
		// detail and the content tools for actual data — agent learns the
		// list → describe → query workflow from the description alone.
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$description = $registry->get('list_collections')->description;

		$this->assertStringContainsString('describe_collection', $description);
		$this->assertStringContainsString('query_collection', $description);
		$this->assertStringContainsString('get_object', $description);
	}

	// ─── Persona filtering ───────────────────────────────────────────────────

	public function testPublicPersonaSeesOnlyAccessibleCollections(): void
	{
		// Same persona policy as content tools: public sees only public-access
		// collections; admin-only ones are invisible.
		$this->persona->set(McpPersona::PUBLIC_);

		$blog    = $this->collection('blog', access: 'public');
		$auth    = $this->collection('auth', access: 'admin');

		$this->collections->method('listAllCollections')->willReturn([$blog, $auth]);
		$this->resolver->method('isAccessibleTo')->willReturnCallback(
			fn (CollectionData $c, string $p): bool => $p === 'admin' || ($c->mcp['access'] ?? '') === 'public',
		);
		$this->resolver->method('forCollection')->willReturn([
			'access' => 'public', 'description' => null, 'resource' => true,
		]);

		$result = $this->tool->handler();

		$this->assertCount(1, $result['collections']);
		$this->assertSame('blog', $result['collections'][0]['id']);
		$this->assertSame(1, $result['total']);
	}

	public function testAdminPersonaSeesEverything(): void
	{
		$this->persona->set(McpPersona::ADMIN);

		$blog = $this->collection('blog', access: 'public');
		$auth = $this->collection('auth', access: 'admin');

		$this->collections->method('listAllCollections')->willReturn([$blog, $auth]);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('forCollection')->willReturn([
			'access' => 'public', 'description' => null, 'resource' => true,
		]);

		$result = $this->tool->handler();

		$this->assertSame(2, $result['total']);
	}

	// ─── Output shape ────────────────────────────────────────────────────────

	public function testItemShapeIsLeanWithMetadataOnly(): void
	{
		// Lean shape: list_collections is the overview layer — describe_collection
		// carries per-property detail. Each item here is {id, name, schema,
		// description, url_pattern, access, total_objects}. name + schema
		// folded in (formerly only in the dropped collection_admin_list) so the
		// agent gets a single canonical list with enough metadata to plan
		// drill-downs without a second round-trip.
		$this->persona->set(McpPersona::ADMIN);

		$blog         = $this->collection('blog', access: 'public', url: '/blog/{id}', totalObjects: 26006);
		$blog->name   = 'Blog Posts';
		$blog->schema = 'blog-pro';
		$this->collections->method('listAllCollections')->willReturn([$blog]);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('forCollection')->willReturn([
			'access' => 'public', 'description' => 'Blog posts', 'resource' => true,
		]);

		$result = $this->tool->handler();
		$item   = $result['collections'][0];

		$this->assertSame('blog', $item['id']);
		$this->assertSame('Blog Posts', $item['name']);
		$this->assertSame('blog-pro', $item['schema']);
		$this->assertSame('Blog posts', $item['description']);
		$this->assertSame('/blog/{id}', $item['url_pattern']);
		$this->assertSame('public', $item['access']);
		$this->assertSame(26006, $item['total_objects']);
		// Lean: no property-level data here. That's describe_collection's job.
		$this->assertArrayNotHasKey('filterable_fields', $item);
		$this->assertArrayNotHasKey('properties', $item);
	}

	public function testNegativeTotalObjectsClampedToZeroForUncalculatedCollections(): void
	{
		// CollectionData uses -1 as a "not calculated yet" sentinel. Surfacing
		// that to the agent would be confusing — clamp to 0 so the agent reads
		// "no objects" rather than a strange negative count.
		$this->persona->set(McpPersona::ADMIN);

		$collection = $this->collection('fresh', totalObjects: -1);
		$this->collections->method('listAllCollections')->willReturn([$collection]);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('forCollection')->willReturn([
			'access' => 'public', 'description' => null, 'resource' => true,
		]);

		$result = $this->tool->handler();

		$this->assertSame(0, $result['collections'][0]['total_objects']);
	}

	public function testCollectionsSortedAlphabeticallyById(): void
	{
		// Stable ordering keeps tools/list responses diff-friendly and lets
		// the agent learn the namespace's shape across calls.
		$this->persona->set(McpPersona::ADMIN);

		$cherry = $this->collection('cherry');
		$apple  = $this->collection('apple');
		$banana = $this->collection('banana');

		$this->collections->method('listAllCollections')->willReturn([$cherry, $apple, $banana]);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('forCollection')->willReturn([
			'access' => 'public', 'description' => null, 'resource' => true,
		]);

		$result = $this->tool->handler();

		$this->assertSame(['apple', 'banana', 'cherry'], array_column($result['collections'], 'id'));
	}

	public function testEmptySiteReturnsEmptyEnvelope(): void
	{
		// Fresh install: no collections registered yet. Honest empty response
		// rather than an error.
		$this->persona->set(McpPersona::PUBLIC_);
		$this->collections->method('listAllCollections')->willReturn([]);

		$result = $this->tool->handler();

		$this->assertSame([], $result['collections']);
		$this->assertSame(0, $result['total']);
	}
}
