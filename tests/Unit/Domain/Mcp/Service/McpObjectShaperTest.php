<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpObjectShaper;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;

// The per-item transform every read tool applies before returning an object:
// drop properties the schema marks mcp.expose:false, render styledtext in
// the agent's format, decorate with the public url. It was written out in
// six tools and a resource handler.
final class McpObjectShaperTest extends TestCase
{
	private function shaper(): McpObjectShaper
	{
		$resolver = $this->createStub(McpSchemaResolver::class);
		$resolver->method('nonExposedProperties')->willReturn(['secret']);
		$resolver->method('renderableProperties')->willReturn(['body']);

		$renderer = $this->createStub(ContentRenderer::class);
		$renderer->method('render')->willReturnCallback(static fn (mixed $value, string $format): string => "[{$format}]{$value}");

		$urls = $this->createStub(ObjectUrlBuilder::class);
		$urls->method('buildUrl')->willReturn('/blog/hello');

		return new McpObjectShaper($resolver, $renderer, $urls);
	}

	public function testShapeStripsRendersAndDecorates(): void
	{
		$shaped = $this->shaper()->shape(['id' => 'hello', 'secret' => 'x', 'body' => 'Hi'], new CollectionData(), 'markdown');

		$this->assertSame(['id' => 'hello', 'body' => '[markdown]Hi', 'url' => '/blog/hello'], $shaped);
	}

	public function testWithoutAFormatContentIsLeftAsStored(): void
	{
		$shaped = $this->shaper()->shape(['id' => 'hello', 'body' => 'Hi'], new CollectionData(), null);

		$this->assertSame('Hi', $shaped['body']);
		$this->assertSame('/blog/hello', $shaped['url']);
	}

	public function testStripOnlyRemovesNonExposedProperties(): void
	{
		$this->assertSame(['id' => 'hello', 'body' => 'Hi'], $this->shaper()->strip(['id' => 'hello', 'secret' => 'x', 'body' => 'Hi'], new CollectionData()));
	}
}
