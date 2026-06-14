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
