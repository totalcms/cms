<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Resource\Handler;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Resource\Handler\CollectionObjectResource;
use TotalCMS\Domain\Mcp\Tool\Content\GetObjectTool;

final class CollectionObjectResourceTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $getObjectTool;
	private CollectionObjectResource $resource;

	protected function setUp(): void
	{
		$this->getObjectTool = $this->createMock(GetObjectTool::class);

		$this->resource = new CollectionObjectResource(
			$this->getObjectTool,
		);
	}

	// ─── Response shape ───────────────────────────────────────────────────────

	public function testReadReturnsContentsArrayWithCorrectShape(): void
	{
		$object = ['id' => 'hello-world', 'title' => 'Hello World', 'body' => '...markdown...'];

		$this->getObjectTool
			->method('handler')
			->willReturn($object);

		$result = $this->resource->read('blog', 'hello-world');

		$this->assertArrayHasKey('contents', $result);
		$this->assertIsArray($result['contents']);
		$this->assertCount(1, $result['contents']);

		$content = $result['contents'][0];
		$this->assertSame('tcms://blog/hello-world', $content['uri']);
		$this->assertSame('application/json', $content['mimeType']);
		$this->assertArrayHasKey('text', $content);
	}

	public function testReadTextDecodesBackToOriginalFlatMap(): void
	{
		$object = [
			'id'    => 'hello-world',
			'title' => 'Hello World',
			'body'  => '...markdown...',
			'url'   => '/blog/hello-world',
		];

		$this->getObjectTool
			->method('handler')
			->willReturn($object);

		$result  = $this->resource->read('blog', 'hello-world');
		$decoded = json_decode((string)$result['contents'][0]['text'], true);

		$this->assertIsArray($decoded);
		$this->assertSame($object, $decoded);
	}

	// ─── URI assembly ─────────────────────────────────────────────────────────

	public function testReadBuildsUriFromCollectionAndId(): void
	{
		$this->getObjectTool
			->method('handler')
			->willReturn(['id' => 'my-product', 'title' => 'My Product']);

		$result = $this->resource->read('products', 'my-product');

		$this->assertSame('tcms://products/my-product', $result['contents'][0]['uri']);
	}

	public function testReadDifferentCollectionAndIdProduceDifferentUris(): void
	{
		$this->getObjectTool
			->method('handler')
			->willReturn(['id' => 'item-1', 'title' => 'Item 1']);

		$resultA = $this->resource->read('blog', 'post-abc');
		$resultB = $this->resource->read('gallery', 'photo-xyz');

		$this->assertSame('tcms://blog/post-abc', $resultA['contents'][0]['uri']);
		$this->assertSame('tcms://gallery/photo-xyz', $resultB['contents'][0]['uri']);
	}

	// ─── Error propagation ────────────────────────────────────────────────────

	public function testReadDoesNotCatchToolCallExceptionFromTool(): void
	{
		// The wrapper is pure delegation; any ToolCallException thrown by
		// GetObjectTool (collection not found, persona denied, object missing,
		// draft hidden) must propagate without modification.
		$this->getObjectTool
			->method('handler')
			->willThrowException(new ToolCallException('Object "missing" not found in collection "blog".'));

		$this->expectException(ToolCallException::class);

		$this->resource->read('blog', 'missing');
	}

	public function testReadThrowsRuntimeExceptionWhenObjectCannotBeJsonEncoded(): void
	{
		// json_encode returns false for resources, NAN, INF, and malformed UTF-8.
		// A live resource handle is the cleanest way to force the failure branch.
		$handle = fopen('php://memory', 'r');
		$this->assertNotFalse($handle);

		$this->getObjectTool
			->method('handler')
			->willReturn(['id' => 'broken', 'handle' => $handle]);

		try {
			$this->expectException(\RuntimeException::class);
			$this->resource->read('blog', 'broken');
		} finally {
			fclose($handle);
		}
	}
}
