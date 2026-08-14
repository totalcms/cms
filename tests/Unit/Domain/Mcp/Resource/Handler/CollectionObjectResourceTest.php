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

	public function testReadReturnsFlatResourceContent(): void
	{
		$object = ['id' => 'hello-world', 'title' => 'Hello World', 'body' => '...markdown...'];

		$this->getObjectTool
			->method('handler')
			->willReturn($object);

		$result = $this->resource->read('blog', 'hello-world');

		// Flat {text, mimeType}. The absence of 'contents' is the assertion that
		// matters: returning a pre-built ReadResourceResult made it the *text* of
		// the SDK's own envelope, burying the payload two levels deep for every
		// client. The old tests asserted the envelope and so locked the bug in.
		$this->assertArrayNotHasKey('contents', $result);
		$this->assertArrayHasKey('text', $result);
		$this->assertArrayHasKey('mimeType', $result);
		$this->assertSame('application/json', $result['mimeType']);
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
		$decoded = json_decode((string)$result['text'], true);

		$this->assertIsArray($decoded);
		$this->assertSame($object, $decoded);
	}

	// ─── URI assembly ─────────────────────────────────────────────────────────

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
