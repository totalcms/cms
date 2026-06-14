<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Visualizer;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Visualizer\Service\MermaidErdRenderer;

final class MermaidErdRendererTest extends TestCase
{
	private function attributeRows(string $mermaid): array
	{
		$rows = [];
		foreach (explode("\n", $mermaid) as $line) {
			$line = trim($line);
			// Attribute rows are `type name` inside an entity block (two tokens,
			// no relationship arrows, not the erDiagram header or an entity line).
			if ($line === '' || $line === 'erDiagram' || str_contains($line, '--') || str_contains($line, '[') || str_contains($line, '{') || $line === '}') {
				continue;
			}
			$rows[] = $line;
		}

		return $rows;
	}

	public function testFieldTokensNeverStartWithADigit(): void
	{
		$renderer = new MermaidErdRenderer();
		$mermaid  = $renderer->render([
			'nodes' => [
				'posts' => ['id' => 'posts', 'kind' => 'collection', 'label' => 'Posts', 'schema' => 'post', 'fields' => [
					['name' => '2023archive', 'type' => '123'],
					['name' => 'title', 'type' => 'string'],
				]],
			],
			'edges' => [],
		]);

		foreach ($this->attributeRows($mermaid) as $row) {
			[$type, $name] = array_pad(preg_split('/\s+/', $row), 2, '');
			$this->assertMatchesRegularExpression('/^[A-Za-z_]/', $type, "type token: {$row}");
			$this->assertMatchesRegularExpression('/^[A-Za-z_]/', $name, "name token: {$row}");
		}

		// The digit-leading values are prefixed, not dropped.
		$this->assertStringContainsString('_123 _2023archive', $mermaid);
	}

	public function testCapsFieldsAndSummarizesTheRest(): void
	{
		$fields = [];
		for ($i = 1; $i <= 15; $i++) {
			$fields[] = ['name' => 'field' . $i, 'type' => 'string'];
		}

		$renderer = new MermaidErdRenderer();
		$mermaid  = $renderer->render([
			'nodes' => ['big' => ['id' => 'big', 'kind' => 'collection', 'label' => 'Big', 'schema' => 'big', 'fields' => $fields]],
			'edges' => [],
		]);

		// 10 shown + a summary row for the remaining 5.
		$this->assertStringContainsString('more fields_5', $mermaid);
		$this->assertStringContainsString('string field10', $mermaid);
		$this->assertStringNotContainsString('string field11', $mermaid);
	}

	public function testSanitizesEntityIdsAndRendersEdges(): void
	{
		$renderer = new MermaidErdRenderer();
		$mermaid  = $renderer->render([
			'nodes' => [
				'builder-pages' => ['id' => 'builder-pages', 'kind' => 'collection', 'label' => 'builder-pages', 'schema' => 'builder-page', 'fields' => []],
				'schema:block'  => ['id' => 'schema:block', 'kind' => 'schema', 'label' => 'block', 'schema' => 'block', 'fields' => []],
			],
			'edges' => [
				['from' => 'builder-pages', 'to' => 'schema:block', 'type' => 'composition', 'via' => 'body'],
			],
		]);

		$this->assertStringStartsWith('erDiagram', $mermaid);
		// Hyphen/colon ids become safe entity tokens with the label in the alias.
		$this->assertStringContainsString('builder_pages["builder-pages"]', $mermaid);
		$this->assertStringContainsString('schema_block["block"]', $mermaid);
		$this->assertStringContainsString('builder_pages ||--|| schema_block', $mermaid);
	}

	public function testExposesEdgeTypesInEmitOrder(): void
	{
		$renderer = new MermaidErdRenderer();
		$renderer->render([
			'nodes' => [
				'a' => ['id' => 'a', 'kind' => 'collection', 'label' => 'A', 'schema' => 'a', 'fields' => []],
				'b' => ['id' => 'b', 'kind' => 'collection', 'label' => 'B', 'schema' => 'b', 'fields' => []],
			],
			'edges' => [
				['from' => 'a', 'to' => 'b', 'type' => 'relational', 'via' => 'x'],
				['from' => 'b', 'to' => 'a', 'type' => 'inheritance', 'via' => ''],
			],
		]);

		// Matches the order relationship lines are emitted, for frontend styling.
		$this->assertSame(['relational', 'inheritance'], $renderer->edgeTypes());
	}
}
