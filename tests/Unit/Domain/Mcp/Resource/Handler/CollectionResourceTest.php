<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Resource\Handler;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Resource\Handler\CollectionResource;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\PersonaContext;

final class CollectionResourceTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $collectionFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $indexReader;
	private \PHPUnit\Framework\MockObject\MockObject $schemaResolver;
	private \PHPUnit\Framework\MockObject\MockObject $urlBuilder;
	private PersonaContext $personaContext;
	private CollectionResource $resource;

	protected function setUp(): void
	{
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->indexReader       = $this->createMock(IndexReader::class);
		$this->schemaResolver    = $this->createMock(McpSchemaResolver::class);
		$this->urlBuilder        = $this->createMock(ObjectUrlBuilder::class);
		$this->personaContext    = new PersonaContext();

		$this->resource = new CollectionResource(
			$this->collectionFetcher,
			$this->indexReader,
			$this->schemaResolver,
			$this->urlBuilder,
			$this->personaContext,
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

	// ─── Response shape ───────────────────────────────────────────────────────

	public function testReadReturnsContentsArrayWithCorrectShape(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$blog = $this->collection();
		$this->collectionFetcher->method('fetchCollection')->willReturn($blog);
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([['id' => 'a']]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/a');

		$result = $this->resource->read('blog');

		$this->assertArrayHasKey('contents', $result);
		$this->assertIsArray($result['contents']);
		$this->assertCount(1, $result['contents']);

		$content = $result['contents'][0];
		$this->assertSame('tcms://blog/', $content['uri']);
		$this->assertSame('application/json', $content['mimeType']);
		$this->assertArrayHasKey('text', $content);
	}

	public function testReadDecodedJsonHasRequiredKeys(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([['id' => 'a']]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/a');

		$result  = $this->resource->read('blog');
		$payload = json_decode($result['contents'][0]['text'], true);

		$this->assertIsArray($payload);
		$this->assertArrayHasKey('items', $payload);
		$this->assertArrayHasKey('total', $payload);
		$this->assertArrayHasKey('truncated', $payload);
	}

	// ─── Error paths ─────────────────────────────────────────────────────────

	public function testReadThrowsWhenCollectionNotFound(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);

		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessageMatches('/unknown/');

		$this->resource->read('unknown');
	}

	public function testReadThrowsWhenCollectionInaccessibleToPersona(): void
	{
		$this->personaContext->set(McpPersona::PUBLIC_);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection('auth'));
		$this->schemaResolver->method('isAccessibleTo')->willReturn(false);

		$this->expectException(ToolCallException::class);

		$this->resource->read('auth');
	}

	// ─── Truncation ───────────────────────────────────────────────────────────

	public function testReadCapsItemsAtFifty(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$manyItems = [];
		for ($i = 1; $i <= 60; $i++) {
			$manyItems[] = ['id' => "item-{$i}"];
		}
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData($manyItems));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/x');

		$result  = $this->resource->read('blog');
		$payload = json_decode($result['contents'][0]['text'], true);

		$this->assertCount(50, $payload['items']);
	}

	public function testReadSetsTruncatedTrueAndEmitsHintWhenOver50(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$manyItems = [];
		for ($i = 1; $i <= 55; $i++) {
			$manyItems[] = ['id' => "item-{$i}"];
		}
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData($manyItems));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/x');

		$result  = $this->resource->read('blog');
		$payload = json_decode($result['contents'][0]['text'], true);

		$this->assertTrue($payload['truncated']);
		$this->assertArrayHasKey('hint', $payload);
		$this->assertStringContainsString('query_collection', $payload['hint']);
		$this->assertStringContainsString('blog', $payload['hint']);
	}

	public function testReadSetsTruncatedFalseAndNoHintWhenAtOrUnder50(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$items = [];
		for ($i = 1; $i <= 10; $i++) {
			$items[] = ['id' => "item-{$i}"];
		}
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData($items));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/x');

		$result  = $this->resource->read('blog');
		$payload = json_decode($result['contents'][0]['text'], true);

		$this->assertFalse($payload['truncated']);
		$this->assertArrayNotHasKey('hint', $payload);
	}

	// ─── Draft filtering ─────────────────────────────────────────────────────

	public function testPublicPersonaStripsDraftItems(): void
	{
		$this->personaContext->set(McpPersona::PUBLIC_);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'published', 'draft' => false],
			['id' => 'is-draft', 'draft' => true],
			['id' => 'also-published'],
		]));
		$this->urlBuilder->method('buildUrl')->willReturnCallback(
			static fn (CollectionData $c, array $obj): string => '/blog/' . $obj['id'],
		);

		$result  = $this->resource->read('blog');
		$payload = json_decode($result['contents'][0]['text'], true);

		$this->assertSame(2, $payload['total']);
		$this->assertCount(2, $payload['items']);

		$ids = array_column($payload['items'], 'id');
		$this->assertContains('published', $ids);
		$this->assertContains('also-published', $ids);
		$this->assertNotContains('is-draft', $ids);
	}

	public function testAdminPersonaRetainsDraftItems(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'published', 'draft' => false],
			['id' => 'is-draft', 'draft' => true],
		]));
		$this->urlBuilder->method('buildUrl')->willReturnCallback(
			static fn (CollectionData $c, array $obj): string => '/blog/' . $obj['id'],
		);

		$result  = $this->resource->read('blog');
		$payload = json_decode($result['contents'][0]['text'], true);

		$this->assertSame(2, $payload['total']);
		$this->assertCount(2, $payload['items']);

		$ids = array_column($payload['items'], 'id');
		$this->assertContains('published', $ids);
		$this->assertContains('is-draft', $ids);
	}

	public function testAuthenticatedPersonaRetainsDraftItems(): void
	{
		// Forward-compat: AUTHENTICATED is reserved for Phase 4 OAuth. For draft
		// visibility it should behave like ADMIN, NOT like PUBLIC_ — drafts are
		// kept unless explicitly excluded by the caller.
		$this->personaContext->set(McpPersona::AUTHENTICATED);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'published', 'draft' => false],
			['id' => 'is-draft', 'draft' => true],
		]));
		$this->urlBuilder->method('buildUrl')->willReturnCallback(
			static fn (CollectionData $c, array $obj): string => '/blog/' . $obj['id'],
		);

		$result  = $this->resource->read('blog');
		$payload = json_decode($result['contents'][0]['text'], true);

		$this->assertSame(2, $payload['total']);
		$ids = array_column($payload['items'], 'id');
		$this->assertContains('is-draft', $ids);
	}

	public function testPublicPersonaTotalReflectsPostFilterCount(): void
	{
		// `total` is intentionally what the caller can see, not the raw index size.
		// A public caller hitting a collection of 5 items (2 drafts) sees total: 3.
		$this->personaContext->set(McpPersona::PUBLIC_);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'a', 'draft' => false],
			['id' => 'b', 'draft' => true],
			['id' => 'c', 'draft' => false],
			['id' => 'd', 'draft' => true],
			['id' => 'e', 'draft' => false],
		]));
		$this->urlBuilder->method('buildUrl')->willReturn('/x');

		$result  = $this->resource->read('blog');
		$payload = json_decode($result['contents'][0]['text'], true);

		$this->assertSame(3, $payload['total'], 'total should be the post-filter count for public callers');
		$this->assertCount(3, $payload['items']);
	}

	// ─── Field stripping ─────────────────────────────────────────────────────

	public function testNonExposedPropertiesAreStrippedFromItems(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->collection());
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn(['secret', 'internal_notes']);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'a', 'title' => 'Hello', 'secret' => 'private-data', 'internal_notes' => 'admin-only'],
		]));
		$this->urlBuilder->method('buildUrl')->willReturn('/blog/a');

		$result  = $this->resource->read('blog');
		$payload = json_decode($result['contents'][0]['text'], true);

		$item = $payload['items'][0];
		$this->assertArrayHasKey('title', $item);
		$this->assertArrayNotHasKey('secret', $item);
		$this->assertArrayNotHasKey('internal_notes', $item);
	}

	// ─── URL decoration ───────────────────────────────────────────────────────

	public function testEachItemHasUrlKeyFromUrlBuilder(): void
	{
		$this->personaContext->set(McpPersona::ADMIN);
		$blog = $this->collection();
		$this->collectionFetcher->method('fetchCollection')->willReturn($blog);
		$this->schemaResolver->method('isAccessibleTo')->willReturn(true);
		$this->schemaResolver->method('nonExposedProperties')->willReturn([]);

		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([
			['id' => 'post-1'],
			['id' => 'post-2'],
		]));
		$this->urlBuilder->expects($this->exactly(2))
			->method('buildUrl')
			->willReturnCallback(
				static fn (CollectionData $c, array $obj): string => '/blog/' . $obj['id'],
			);

		$result  = $this->resource->read('blog');
		$payload = json_decode($result['contents'][0]['text'], true);

		$this->assertSame('/blog/post-1', $payload['items'][0]['url']);
		$this->assertSame('/blog/post-2', $payload['items'][1]['url']);
	}
}
