<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tools\Discovery;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;
use TotalCMS\Domain\Mcp\Tools\Discovery\DescribeCollectionTool;

final class DescribeCollectionToolTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $collections;
	private \PHPUnit\Framework\MockObject\MockObject $resolver;
	private PersonaContext $persona;
	private DescribeCollectionTool $tool;

	protected function setUp(): void
	{
		$this->collections = $this->createMock(CollectionFetcher::class);
		$this->resolver    = $this->createMock(McpSchemaResolver::class);
		$this->persona     = new PersonaContext();

		$this->tool = new DescribeCollectionTool(
			$this->collections,
			$this->resolver,
			$this->persona,
		);
	}

	private function collection(string $id, string $url = '', int $totalObjects = 0): CollectionData
	{
		$collection               = new CollectionData();
		$collection->id           = $id;
		$collection->schema       = $id;
		$collection->url          = $url;
		$collection->mcp          = ['access' => 'public'];
		$collection->totalObjects = $totalObjects;

		return $collection;
	}

	// ─── Registration ────────────────────────────────────────────────────────

	public function testRegisterAddsToolWithExpectedNameAndPublicAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$definition = $registry->get('describe_collection');
		$this->assertNotNull($definition);
		$this->assertSame('public', $definition->access);
	}

	public function testInputSchemaRequiresCollection(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$schema = $registry->get('describe_collection')->inputSchema;

		$this->assertSame(['collection'], $schema['required']);
		$this->assertNotEmpty($schema['properties']['collection']['examples']);
	}

	public function testDescriptionPositionsToolAsTheDetailLayer(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$description = $registry->get('describe_collection')->description;

		// The agent should learn the list → describe → query workflow from the
		// description text alone.
		$this->assertStringContainsString('list_collections', $description);
		$this->assertStringContainsString('properties', strtolower($description));
	}

	// ─── Error recovery hints ────────────────────────────────────────────────

	public function testUnknownCollectionThrowsRecoveryHintPointingAtListCollections(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn(null);

		try {
			$this->tool->handler(collection: 'blug');
			$this->fail('Expected ToolCallException for unknown collection.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('blug', $e->getMessage());
			$this->assertStringContainsString('list_collections', $e->getMessage());
		}
	}

	public function testInaccessibleCollectionThrowsRecoveryHint(): void
	{
		// Public persona asking to describe an admin-only collection: same
		// closed-error pattern as the content tools. Don't leak existence
		// of admin-only collections by giving different wording.
		$this->persona->set(McpPersona::PUBLIC_);
		$this->collections->method('fetchCollection')->willReturn($this->collection('auth'));
		$this->resolver->method('isAccessibleTo')->willReturn(false);

		try {
			$this->tool->handler(collection: 'auth');
			$this->fail('Expected ToolCallException for inaccessible collection.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('auth', $e->getMessage());
			$this->assertStringContainsString('list_collections', $e->getMessage());
		}
	}

	// ─── Output shape ────────────────────────────────────────────────────────

	public function testReturnsCollectionMetadataPlusPropertyDetail(): void
	{
		// The detail layer: id, description, url_pattern, access, total_objects
		// AND a `properties` array of every exposed property with flags.
		$this->persona->set(McpPersona::ADMIN);

		$blog = $this->collection('blog', url: '/blog/{id}', totalObjects: 26006);
		$this->collections->method('fetchCollection')->willReturn($blog);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('forCollection')->willReturn([
			'access' => 'public', 'description' => 'Blog posts', 'resource' => true,
		]);
		$this->resolver->method('describeProperties')->willReturn([
			['name' => 'title',   'type' => 'text',       'description' => 'Post title',  'indexed' => true,  'filterable' => true,  'sortable' => false],
			['name' => 'date',    'type' => 'date',       'description' => null,           'indexed' => true,  'filterable' => true,  'sortable' => true],
			['name' => 'content', 'type' => 'styledtext', 'description' => 'Body content', 'indexed' => false, 'filterable' => false, 'sortable' => false],
		]);

		$result = $this->tool->handler(collection: 'blog');

		$this->assertSame('blog', $result['id']);
		$this->assertSame('Blog posts', $result['description']);
		$this->assertSame('/blog/{id}', $result['url_pattern']);
		$this->assertSame('public', $result['access']);
		$this->assertSame(26006, $result['total_objects']);
		$this->assertCount(3, $result['properties']);

		// Non-indexed properties (like `content`) appear in the list so the
		// agent learns they exist on objects, but indexed:false tells the
		// agent they won't show up in query/search results — fetch via
		// get_object instead.
		$content = $result['properties'][2];
		$this->assertSame('content', $content['name']);
		$this->assertFalse($content['indexed']);
		$this->assertFalse($content['filterable']);
		$this->assertFalse($content['sortable']);
	}

	public function testReturnsEmptyPropertiesArrayForCollectionWithNoExposedProperties(): void
	{
		// Edge case: every property has mcp.expose:false. describeProperties
		// returns []; the agent should still get a valid response (just
		// nothing actionable).
		$this->persona->set(McpPersona::ADMIN);

		$this->collections->method('fetchCollection')->willReturn($this->collection('locked'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('forCollection')->willReturn([
			'access' => 'admin', 'description' => null, 'resource' => true,
		]);
		$this->resolver->method('describeProperties')->willReturn([]);

		$result = $this->tool->handler(collection: 'locked');

		$this->assertSame([], $result['properties']);
	}

	public function testNegativeTotalObjectsClampedToZero(): void
	{
		// Same sentinel-clamp as list_collections — CollectionData::$totalObjects
		// of -1 means "not calculated"; surface 0 to the agent.
		$this->persona->set(McpPersona::ADMIN);

		$collection = $this->collection('fresh', totalObjects: -1);
		$this->collections->method('fetchCollection')->willReturn($collection);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('forCollection')->willReturn([
			'access' => 'public', 'description' => null, 'resource' => true,
		]);
		$this->resolver->method('describeProperties')->willReturn([]);

		$result = $this->tool->handler(collection: 'fresh');

		$this->assertSame(0, $result['total_objects']);
	}
}
