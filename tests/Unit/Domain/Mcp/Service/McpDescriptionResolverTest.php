<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Mcp\Service\McpDescriptionResolver;

final class McpDescriptionResolverTest extends TestCase
{
	private McpDescriptionResolver $resolver;

	protected function setUp(): void
	{
		$this->resolver = new McpDescriptionResolver();
	}

	private function collection(?string $mcpDescription, string $generalDescription): CollectionData
	{
		$collection              = new CollectionData();
		$collection->id          = 'blog';
		$collection->schema      = 'blog';
		$collection->description = $generalDescription;
		if ($mcpDescription !== null) {
			$collection->mcp = ['description' => $mcpDescription];
		}

		return $collection;
	}

	// ─── Collection-level fallback chain ──────────────────────────────────────

	public function testCollectionMcpDescriptionWinsWhenSet(): void
	{
		$result = $this->resolver->forCollection($this->collection('AI-targeted text', 'General text'));

		$this->assertSame('AI-targeted text', $result);
	}

	public function testCollectionFallsBackToGeneralDescription(): void
	{
		$result = $this->resolver->forCollection($this->collection(null, 'General text'));

		$this->assertSame('General text', $result);
	}

	public function testCollectionFallsBackToGeneralWhenMcpIsEmptyString(): void
	{
		// Empty string in mcp.description shouldn't outweigh a populated
		// general description — treat empty as "not set" for fallback purposes.
		$result = $this->resolver->forCollection($this->collection('', 'General text'));

		$this->assertSame('General text', $result);
	}

	public function testCollectionReturnsNullWhenBothEmpty(): void
	{
		$result = $this->resolver->forCollection($this->collection(null, ''));

		$this->assertNull($result);
	}

	public function testCollectionTrimsWhitespaceFromMcpDescription(): void
	{
		// Whitespace-only mcp.description should fall back, not stick.
		$result = $this->resolver->forCollection($this->collection('   ', 'General text'));

		$this->assertSame('General text', $result);
	}

	// ─── Property-level fallback chain ────────────────────────────────────────

	public function testPropertyMcpDescriptionWinsWhenSet(): void
	{
		$property = [
			'field' => 'text',
			'label' => 'Title',
			'help'  => 'Enter the post title',
			'mcp'   => ['description' => 'The post title shown in listings'],
		];

		$this->assertSame('The post title shown in listings', $this->resolver->forProperty($property));
	}

	public function testPropertyFallsBackToHelp(): void
	{
		$property = [
			'field' => 'text',
			'label' => 'Title',
			'help'  => 'Enter the post title',
		];

		$this->assertSame('Enter the post title', $this->resolver->forProperty($property));
	}

	public function testPropertyFallsBackToLabelWhenHelpEmpty(): void
	{
		$property = [
			'field' => 'text',
			'label' => 'Title',
			'help'  => '',
		];

		$this->assertSame('Title', $this->resolver->forProperty($property));
	}

	public function testPropertyReturnsNullWhenAllEmpty(): void
	{
		$property = [
			'field' => 'text',
		];

		$this->assertNull($this->resolver->forProperty($property));
	}

	public function testPropertyHandlesMissingMcpKey(): void
	{
		// No mcp key at all — fall through to help.
		$property = [
			'field' => 'text',
			'help'  => 'Help text',
		];

		$this->assertSame('Help text', $this->resolver->forProperty($property));
	}

	public function testPropertyHandlesNonArrayMcpKey(): void
	{
		// Defensive: malformed mcp value (e.g., a string) should not crash —
		// the resolver should ignore it and fall through.
		$property = [
			'field' => 'text',
			'help'  => 'Help text',
			'mcp'   => 'invalid',
		];

		$this->assertSame('Help text', $this->resolver->forProperty($property));
	}
}
