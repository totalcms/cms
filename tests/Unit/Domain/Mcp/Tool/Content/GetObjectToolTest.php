<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Content;

use Illuminate\Support\Collection;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Content\GetObjectTool;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Twig\Markdown\TiptapToMarkdownConverter;

final class GetObjectToolTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $objects;
	private \PHPUnit\Framework\MockObject\MockObject $collections;
	private \PHPUnit\Framework\MockObject\MockObject $urls;
	private \PHPUnit\Framework\MockObject\MockObject $resolver;
	private PersonaContext $persona;
	private GetObjectTool $tool;

	protected function setUp(): void
	{
		$this->objects     = $this->createMock(ObjectFetcher::class);
		$this->collections = $this->createMock(CollectionFetcher::class);
		$this->urls        = $this->createMock(ObjectUrlBuilder::class);
		$this->resolver    = $this->createMock(McpSchemaResolver::class);
		$this->persona     = new PersonaContext();

		$this->tool = new GetObjectTool(
			$this->objects,
			$this->collections,
			$this->urls,
			$this->persona,
			$this->resolver,
			new ContentRenderer(new TiptapToMarkdownConverter()),
		);
	}

	private function collection(string $id = 'blog'): CollectionData
	{
		$collection         = new CollectionData();
		$collection->id     = $id;
		$collection->schema = $id;
		$collection->mcp    = ['access' => 'public'];

		return $collection;
	}

	/**
	 * Build an ObjectData stub by reflection — the constructor expects
	 * PropertyData[] which is more setup ceremony than we need for the handler
	 * test surface. Tools only consume `->id` + `->toArray()` so we override
	 * those directly.
	 *
	 * @param array<string,mixed> $data
	 */
	private function objectData(string $id, array $data): ObjectData
	{
		$object             = (new \ReflectionClass(ObjectData::class))->newInstanceWithoutConstructor();
		$object->id         = $id;
		$object->properties = new Collection();

		// toArray() reads $properties; rather than fight the property type
		// system we wrap ObjectData in an anonymous subclass that returns the
		// canned array. Keeps the production code's call shape intact.
		return new class($id, $data) extends ObjectData {
			/** @param array<string,mixed> $data */
			public function __construct(string $id, private readonly array $data)
			{
				parent::__construct($id, []);
			}

			public function toArray(): array
			{
				return array_merge(['id' => $this->id], $this->data);
			}
		};
	}

	// ─── Registration ────────────────────────────────────────────────────────

	public function testRegisterAddsToolWithExpectedNameAndAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$definition = $registry->get('get_object');
		$this->assertNotNull($definition);
		$this->assertSame('public', $definition->access);
	}

	public function testInputSchemaRequiresBothNameAndId(): void
	{
		// Both name and id are mandatory — there's no sensible default for
		// "which object" so the SDK validates before we ever see the call.
		$registry = new ToolRegistry();
		$this->tool->register($registry);
		$schema = $registry->get('get_object')->inputSchema;

		$this->assertEqualsCanonicalizing(['collection', 'id'], $schema['required']);
		$this->assertNotEmpty($schema['properties']['id']['examples']);
	}

	public function testRegistersDescriptionBuilderForPersonaScopedCatalog(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$this->assertNotNull($registry->get('get_object')->descriptionBuilder);
	}

	// ─── Error recovery hints ────────────────────────────────────────────────

	public function testUnknownCollectionThrowsRecoveryHint(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn(null);

		try {
			$this->tool->handler(collection: 'blug', id: 'hello');
			$this->fail('Expected ToolCallException for unknown collection.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('blug', $e->getMessage());
			$this->assertStringContainsString('list_collections', $e->getMessage());
		}
	}

	public function testCollectionInaccessibleToPersonaThrowsRecoveryHint(): void
	{
		$this->persona->set(McpPersona::PUBLIC_);
		$this->collections->method('fetchCollection')->willReturn($this->collection('auth'));
		$this->resolver->method('isAccessibleTo')->willReturn(false);

		try {
			$this->tool->handler(collection: 'auth', id: 'admin@example');
			$this->fail('Expected ToolCallException for inaccessible collection.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('auth', $e->getMessage());
			$this->assertStringContainsString('list_collections', $e->getMessage());
		}
	}

	public function testMissingObjectThrowsToolCallExceptionInsteadOfBubblingFetchError(): void
	{
		// ObjectFetcher::fetchObject() throws \UnexpectedValueException for a
		// missing object. We must convert that to a tool-error so the SDK wraps
		// it as `isError: true` rather than letting a generic exception escape
		// (which surfaces as a 500-ish protocol error to the agent).
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->objects->method('existsObject')->willReturn(false);

		try {
			$this->tool->handler(collection: 'blog', id: 'missing-post');
			$this->fail('Expected ToolCallException for missing object.');
		} catch (ToolCallException $e) {
			$this->assertStringContainsString('missing-post', $e->getMessage());
			$this->assertStringContainsString('blog', $e->getMessage());
		}
	}

	// ─── Draft visibility for public callers ─────────────────────────────────

	public function testPublicPersonaCannotFetchDraftObjectAndGetsNotFoundResponse(): void
	{
		// Draft objects are hidden from the public persona — even if the agent
		// guesses the slug. We respond with the same "not found" message we'd
		// use for a genuinely missing object so we don't leak existence.
		$this->persona->set(McpPersona::PUBLIC_);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->objects->method('existsObject')->willReturn(true);
		$this->objects->method('fetchObject')->willReturn($this->objectData('secret', ['draft' => true, 'title' => 'Secret']));

		try {
			$this->tool->handler(collection: 'blog', id: 'secret');
			$this->fail('Expected ToolCallException for draft hidden from public.');
		} catch (ToolCallException $e) {
			// Same wording as a missing-object error — caller can't tell the
			// difference between "no such id" and "draft id you can't see".
			$this->assertStringContainsString('secret', $e->getMessage());
			$this->assertStringNotContainsString('draft', strtolower($e->getMessage()));
		}
	}

	public function testAdminPersonaCanFetchDraftObject(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$this->collections->method('fetchCollection')->willReturn($this->collection('blog'));
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn([]);
		$this->objects->method('existsObject')->willReturn(true);
		$this->objects->method('fetchObject')->willReturn($this->objectData('secret', ['draft' => true, 'title' => 'Secret']));
		$this->urls->method('buildUrl')->willReturn('/blog/secret');

		$result = $this->tool->handler(collection: 'blog', id: 'secret');

		$this->assertSame('secret', $result['id']);
		$this->assertTrue($result['draft']);
	}

	// ─── Result shaping ──────────────────────────────────────────────────────

	public function testHandlerStripsNonExposedFieldsAndAddsUrl(): void
	{
		$this->persona->set(McpPersona::ADMIN);
		$blog = $this->collection('blog');
		$this->collections->method('fetchCollection')->willReturn($blog);
		$this->resolver->method('isAccessibleTo')->willReturn(true);
		$this->resolver->method('nonExposedProperties')->willReturn(['secret_field']);
		$this->objects->method('existsObject')->willReturn(true);
		$this->objects->method('fetchObject')->willReturn(
			$this->objectData('hello', ['title' => 'Hi', 'secret_field' => 'hidden']),
		);
		$this->urls->expects($this->once())
			->method('buildUrl')
			->with($blog, $this->anything())
			->willReturn('/blog/hello');

		$result = $this->tool->handler(collection: 'blog', id: 'hello');

		$this->assertSame('Hi', $result['title']);
		$this->assertSame('/blog/hello', $result['url']);
		$this->assertArrayNotHasKey('secret_field', $result);
	}
}
