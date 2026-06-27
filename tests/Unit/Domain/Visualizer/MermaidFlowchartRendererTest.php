<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Visualizer;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Visualizer\Service\MermaidFlowchartRenderer;

final class MermaidFlowchartRendererTest extends TestCase
{
	private function sampleGraph(): array
	{
		return [
			'nodes' => [
				'authors::jane' => ['id' => 'authors::jane', 'collection' => 'authors', 'objectId' => 'jane', 'label' => 'Jane', 'focal' => true,  'url' => 'collections/authors/jane'],
				'posts::p1'     => ['id' => 'posts::p1',     'collection' => 'posts',   'objectId' => 'p1',   'label' => 'First',  'focal' => false, 'url' => 'collections/posts/p1'],
			],
			'edges' => [
				['from' => 'posts::p1', 'to' => 'authors::jane', 'direction' => 'inbound', 'via' => 'writer'],
			],
		];
	}

	public function testRendersFlowchartWithSubgraphsFocalAndEdge(): void
	{
		$mermaid = (new MermaidFlowchartRenderer())->render($this->sampleGraph());

		$this->assertStringStartsWith('flowchart LR', $mermaid);
		// A subgraph per collection.
		$this->assertStringContainsString('subgraph sg_authors["authors"]', $mermaid);
		$this->assertStringContainsString('subgraph sg_posts["posts"]', $mermaid);
		// Focal node is marked.
		$this->assertMatchesRegularExpression('/authors__jane\["Jane"\]:::focal/', $mermaid);
		// Edge with the property label, and the focal classDef.
		$this->assertStringContainsString('-->|writer|', $mermaid);
		$this->assertStringContainsString('classDef focal', $mermaid);
	}

	public function testEscStripsNewlinesAndPercent(): void
	{
		$mermaid = (new MermaidFlowchartRenderer())->render([
			'nodes' => [
				'posts::p1' => ['id' => 'posts::p1', 'collection' => 'posts', 'objectId' => 'p1', 'label' => "Evil\nclick X\n%%{init}%%", 'focal' => false, 'url' => ''],
			],
			'edges' => [
				['from' => 'posts::p1', 'to' => 'posts::p1', 'direction' => 'outbound', 'via' => "bad\n%%{init}%%"],
			],
		]);

		// The injection sequence \n%% must not survive: a newline followed by %%
		// would break out of a quoted label and inject a Mermaid directive/statement.
		$this->assertStringNotContainsString("\n%%", $mermaid);
		// No bare % in any label (stripped to prevent %%{init}%% directive injection).
		$this->assertStringNotContainsString('%', $mermaid);
		// The safe part of the label should still be present.
		$this->assertStringContainsString('Evil', $mermaid);
	}

	public function testDeduplicatesEdgesWithSameFromToVia(): void
	{
		$graph = [
			'nodes' => [
				'authors::jane' => ['id' => 'authors::jane', 'collection' => 'authors', 'objectId' => 'jane', 'label' => 'Jane', 'focal' => true,  'url' => ''],
				'posts::p1'     => ['id' => 'posts::p1',     'collection' => 'posts',   'objectId' => 'p1',   'label' => 'First', 'focal' => false, 'url' => ''],
			],
			// Duplicate edges (same from, to, via) — only one arrow should appear.
			'edges' => [
				['from' => 'posts::p1', 'to' => 'authors::jane', 'direction' => 'inbound',  'via' => 'writer'],
				['from' => 'posts::p1', 'to' => 'authors::jane', 'direction' => 'outbound', 'via' => 'writer'],
			],
		];

		$mermaid = (new MermaidFlowchartRenderer())->render($graph);

		// Only one arrow with the "writer" label should appear.
		$this->assertSame(1, substr_count($mermaid, '-->|writer|'));
	}

	public function testSanitizesIdsAndStripsBracketsFromLabels(): void
	{
		$mermaid = (new MermaidFlowchartRenderer())->render([
			'nodes' => [
				'builder-pages::home' => ['id' => 'builder-pages::home', 'collection' => 'builder-pages', 'objectId' => 'home', 'label' => 'Home [draft]', 'focal' => true, 'url' => ''],
			],
			'edges' => [],
		]);

		// Hyphen/colon ids become safe tokens; brackets in labels are neutralized.
		$this->assertStringContainsString('builder_pages__home["Home (draft)"]', $mermaid);
		$this->assertStringNotContainsString('Home [draft]', $mermaid);
	}
}
