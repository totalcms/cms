<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Mcp\Service\CollectionQueryResultFormatter;
use TotalCMS\Domain\Query\Data\QueryResult;

/**
 * Unifies the collection-query result contract shared by QueryCollectionTool
 * and SavedQueryTool: both build the same {items, total, limit, offset,
 * has_more} envelope from a QueryResult, and both declare the same
 * outputSchema shape (item-element schema included) — see
 * docs/planning (shared-envelope) for the rationale.
 */
final class CollectionQueryResultFormatterTest extends TestCase
{
	private CollectionQueryResultFormatter $formatter;

	protected function setUp(): void
	{
		$this->formatter = new CollectionQueryResultFormatter();
	}

	// ─── envelope() ────────────────────────────────────────────────────────

	public function testEnvelopeBuildsTheFullPaginationShapeFromQueryResult(): void
	{
		$result = new QueryResult(items: [['id' => 'ignored-raw-item']], total: 25, limit: 10, offset: 10);
		$items  = [['id' => 'x', 'url' => '/blog/x']];

		$envelope = $this->formatter->envelope($result, $items);

		$this->assertSame(['items', 'total', 'limit', 'offset', 'has_more'], array_keys($envelope));
		$this->assertSame($items, $envelope['items']);
		$this->assertSame(25, $envelope['total']);
		$this->assertSame(10, $envelope['limit']);
		$this->assertSame(10, $envelope['offset']);
		$this->assertTrue($envelope['has_more']);
	}

	public function testEnvelopeReportsHasMoreFalseWhenResultIsComplete(): void
	{
		$result = new QueryResult(items: [], total: 3, limit: 10, offset: 0);

		$envelope = $this->formatter->envelope($result, [['id' => 'a'], ['id' => 'b'], ['id' => 'c']]);

		$this->assertFalse($envelope['has_more']);
		$this->assertSame(3, $envelope['total']);
	}

	public function testEnvelopeUsesTheGivenItemsNotQueryResultItems(): void
	{
		// The caller (tool) has already stripped/rendered/decorated items —
		// the formatter must use exactly what it's handed, not re-derive from
		// $result->items.
		$result = new QueryResult(items: [['id' => 'raw']], total: 1, limit: 10, offset: 0);
		$processed = [['id' => 'raw', 'url' => '/blog/raw', 'title' => 'Rendered']];

		$envelope = $this->formatter->envelope($result, $processed);

		$this->assertSame($processed, $envelope['items']);
	}

	// ─── outputSchema() ────────────────────────────────────────────────────

	public function testOutputSchemaDeclaresTheFullEnvelopeShape(): void
	{
		$schema = $this->formatter->outputSchema('Items description.');

		$this->assertSame('object', $schema['type']);
		$this->assertSame(['items', 'total', 'limit', 'offset', 'has_more'], $schema['required']);
		$this->assertFalse($schema['additionalProperties']);
		$this->assertSame('integer', $schema['properties']['total']['type']);
		$this->assertSame('integer', $schema['properties']['limit']['type']);
		$this->assertSame('integer', $schema['properties']['offset']['type']);
		$this->assertSame('boolean', $schema['properties']['has_more']['type']);
	}

	public function testOutputSchemaParameterizesTheItemsDescription(): void
	{
		$schema = $this->formatter->outputSchema('Matching listings objects, decorated with url.');

		$this->assertSame(
			'Matching listings objects, decorated with url.',
			$schema['properties']['items']['description'],
		);
	}

	public function testOutputSchemaItemElementShapeIsFixedRegardlessOfDescription(): void
	{
		// The per-item schema (id required, url + additionalProperties true)
		// must be identical no matter which caller-supplied description is
		// passed — this is the piece that was previously duplicated
		// character-for-character between QueryCollectionTool and
		// SavedQueryToolFactory and could drift.
		$a = $this->formatter->outputSchema('Description A.');
		$b = $this->formatter->outputSchema('Description B.');

		$this->assertSame($a['properties']['items']['items'], $b['properties']['items']['items']);
		$this->assertSame(['id'], $a['properties']['items']['items']['required']);
		$this->assertSame('string', $a['properties']['items']['items']['properties']['id']['type']);
		$this->assertSame('string', $a['properties']['items']['items']['properties']['url']['type']);
		$this->assertTrue($a['properties']['items']['items']['additionalProperties']);
	}
}
