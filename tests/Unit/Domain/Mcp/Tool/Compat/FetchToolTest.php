<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Compat;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\ObjectTitleResolver;
use TotalCMS\Domain\Mcp\Tool\Compat\FetchTool;
use TotalCMS\Domain\Mcp\Tool\Content\GetObjectTool;

final class FetchToolTest extends TestCase
{
	public function testMalformedIdThrowsNotFound(): void
	{
		$this->expectException(ToolCallException::class);
		$this->makeTool(['id' => 'x'])->handler('no-colon');
	}

	public function testReturnsFetchDocument(): void
	{
		$object = [
			'id'      => 'hello-world',
			'title'   => 'Hello World',
			'content' => "# Heading\n\nBody text.",
			'url'     => 'https://x/blog/hello-world',
			'tags'    => 'a,b',
		];
		$result = $this->makeTool($object)->handler('blog:hello-world');

		$this->assertSame('blog:hello-world', $result['id']);
		$this->assertSame('Hello World', $result['title']);
		$this->assertStringContainsString('Body text.', $result['text']);
		$this->assertSame('https://x/blog/hello-world', $result['url']);
		$this->assertSame('a,b', $result['metadata']['tags']);
	}

	public function testFallbackToMetadataDumpWhenNoRenderableBody(): void
	{
		$object = [
			'id'          => 'hello-world',
			'title'       => 'Hello World',
			'url'         => 'https://x/blog/hello-world',
			'description' => 'A short blurb.',
		];
		$result = $this->makeTool($object)->handler('blog:hello-world');

		$this->assertNotSame('', $result['text']);
		$this->assertStringContainsString('A short blurb.', $result['text']);
	}

	public function testMetadataExcludesConsumedFields(): void
	{
		$object = [
			'id'      => 'hello-world',
			'title'   => 'Hello World',
			'content' => 'Body text.',
			'url'     => 'https://x/blog/hello-world',
			'tags'    => 'a,b',
		];
		$result = $this->makeTool($object)->handler('blog:hello-world');

		$this->assertArrayNotHasKey('id', $result['metadata']);
		$this->assertArrayNotHasKey('url', $result['metadata']);
		$this->assertArrayNotHasKey('content', $result['metadata']);
		$this->assertArrayHasKey('tags', $result['metadata']);
	}

	/**
	 * @param array<string,mixed> $object
	 */
	private function makeTool(array $object): FetchTool
	{
		$collection         = new CollectionData();
		$collection->id     = 'blog';
		$collection->schema = 'blog';

		$getObject = $this->createMock(GetObjectTool::class);
		$getObject->method('handler')->willReturn($object);

		$collectionFetcher = $this->createMock(CollectionFetcher::class);
		$collectionFetcher->method('fetchCollection')->willReturn($collection);

		$schemaResolver = $this->createMock(McpSchemaResolver::class);
		$schemaResolver->method('renderableProperties')->willReturn(['content']);
		$schemaResolver->method('forCollection')->willReturn(
			['access' => 'public', 'description' => null, 'resource' => true, 'titleProperty' => ''],
		);

		return new FetchTool($getObject, $collectionFetcher, $schemaResolver, new ObjectTitleResolver());
	}
}
