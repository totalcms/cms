<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Compat;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\ObjectTitleResolver;
use TotalCMS\Domain\Mcp\Tool\Compat\SearchTool;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Search\Data\SearchResult;
use TotalCMS\Domain\Search\Service\SearchServiceInterface;

final class SearchToolTest extends TestCase
{
	public function testEmptyQueryThrows(): void
	{
		$this->expectException(ToolCallException::class);
		$this->makeTool()->handler('   ');
	}

	public function testReturnsResultsWithCompositeIdTitleAndUrl(): void
	{
		$result = $this->makeTool()->handler('hello');

		$this->assertArrayHasKey('results', $result);
		$this->assertSame('blog:hello-world', $result['results'][0]['id']);
		$this->assertSame('Hello World', $result['results'][0]['title']);
		$this->assertSame('https://x/blog/hello-world', $result['results'][0]['url']);
	}

	public function testNonExposedPropertiesAreStrippedFromResults(): void
	{
		// titleProperty points at 'draft'; if stripping runs before title resolution,
		// 'draft' is absent from the object and the resolver falls through to the
		// 'title' convention key → 'Hello World'. If stripping is skipped the title
		// would be 'SHOULD-NOT-BECOME-TITLE'.
		$tool = $this->makeTool(
			nonExposed: ['draft'],
			objectArray: ['id' => 'hello-world', 'title' => 'Hello World', 'draft' => 'SHOULD-NOT-BECOME-TITLE'],
			titleProperty: 'draft',
		);

		$result = $tool->handler('hello');

		$this->assertArrayHasKey('results', $result);
		$entry = $result['results'][0];

		// Only the three ChatGPT-compat keys must be present.
		$this->assertSame(['id', 'title', 'url'], array_keys($entry));

		// 'draft' was stripped before title resolution; the resolver fell through to
		// the 'title' convention key, so the title must NOT be the draft value.
		$this->assertSame('Hello World', $entry['title']);
	}

	public function testAggregateCapStopsAtDefaultLimit(): void
	{
		// Two collections × 15 hits each = 30 potential results; the DEFAULT_LIMIT
		// of 20 must trigger the `break 2` so only 20 are returned.
		$colA         = new CollectionData();
		$colA->id     = 'posts';
		$colA->schema = 'blog';
		$colA->mcp    = ['access' => 'public'];

		$colB         = new CollectionData();
		$colB->id     = 'news';
		$colB->schema = 'blog';
		$colB->mcp    = ['access' => 'public'];

		$hitsA = [];
		$hitsB = [];
		for ($i = 0; $i < 15; $i++) {
			$hitsA[] = new SearchResult('posts', 'post-' . $i, 1.0);
			$hitsB[] = new SearchResult('news', 'post-' . $i, 1.0);
		}

		$search = $this->createMock(SearchServiceInterface::class);
		$search->method('search')->willReturnOnConsecutiveCalls($hitsA, $hitsB);

		$collections = $this->createMock(CollectionRepository::class);
		$collections->method('listAllCollections')->willReturn([$colA, $colB]);

		$object = $this->createMock(ObjectData::class);
		$object->method('toArray')->willReturn(['id' => 'post-0', 'title' => 'A Post']);
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('existsObject')->willReturn(true);
		$fetcher->method('fetchObject')->willReturn($object);

		$urls = $this->createMock(ObjectUrlBuilder::class);
		$urls->method('buildUrl')->willReturn('https://x/posts/post-0');

		$persona = $this->createMock(PersonaContext::class);
		$persona->method('current')->willReturn(McpPersona::PUBLIC_);
		// Task 10b: SearchTool's per-collection visibility filter also
		// requires canReadCollection() now — stub true so the pre-existing
		// aggregate-cap behavior under test isn't masked by an unconfigured
		// mock defaulting to false (which would zero out every result).
		$persona->method('canReadCollection')->willReturn(true);

		$schemaResolver = $this->createMock(McpSchemaResolver::class);
		$schemaResolver->method('isAccessibleTo')->willReturn(true);
		$schemaResolver->method('nonExposedProperties')->willReturn([]);
		$schemaResolver->method('forCollection')->willReturn(
			['access' => 'public', 'description' => null, 'resource' => true, 'titleProperty' => ''],
		);

		$tool   = new SearchTool($search, $collections, $fetcher, $urls, $persona, $schemaResolver, new ObjectTitleResolver());
		$result = $tool->handler('hello');

		$this->assertCount(20, $result['results']);
	}

	/**
	 * @param list<string>        $nonExposed
	 * @param array<string,mixed> $objectArray
	 */
	private function makeTool(
		array $nonExposed = [],
		array $objectArray = ['id' => 'hello-world', 'title' => 'Hello World', 'draft' => false],
		string $titleProperty = '',
	): SearchTool {
		$collection         = new CollectionData();
		$collection->id     = 'blog';
		$collection->schema = 'blog';
		$collection->mcp    = ['access' => 'public'];

		$search = $this->createMock(SearchServiceInterface::class);
		$search->method('search')->willReturn([new SearchResult('blog', 'hello-world', 1.0)]);

		$collections = $this->createMock(CollectionRepository::class);
		$collections->method('listAllCollections')->willReturn([$collection]);

		// ObjectData has no fromArray(); the production code only calls ->toArray()
		// on the fetched object, so a mock returning the desired array suffices —
		// same idiom as the sibling content-tool tests.
		$object = $this->createMock(ObjectData::class);
		$object->method('toArray')->willReturn($objectArray);
		$fetcher = $this->createMock(ObjectFetcher::class);
		$fetcher->method('existsObject')->willReturn(true);
		$fetcher->method('fetchObject')->willReturn($object);

		$urls = $this->createMock(ObjectUrlBuilder::class);
		$urls->method('buildUrl')->willReturn('https://x/blog/hello-world');

		$persona = $this->createMock(PersonaContext::class);
		$persona->method('current')->willReturn(McpPersona::PUBLIC_);
		// Task 10b: see the aggregate-cap test's identical comment.
		$persona->method('canReadCollection')->willReturn(true);

		$schemaResolver = $this->createMock(McpSchemaResolver::class);
		$schemaResolver->method('isAccessibleTo')->willReturn(true);
		$schemaResolver->method('nonExposedProperties')->willReturn($nonExposed);
		$schemaResolver->method('forCollection')->willReturn(
			['access' => 'public', 'description' => null, 'resource' => true, 'titleProperty' => $titleProperty],
		);

		return new SearchTool(
			$search,
			$collections,
			$fetcher,
			$urls,
			$persona,
			$schemaResolver,
			new ObjectTitleResolver(),
		);
	}
}
