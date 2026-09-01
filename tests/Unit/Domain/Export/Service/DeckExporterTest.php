<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Export\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Export\Service\DeckExporter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\DeckData;

/**
 * Deck rows leave Total CMS through here. A deck is a ragged structure — items
 * need not share the same keys — so the interesting behaviour is what happens
 * to the columns, which is the same ground where the card exporter was losing
 * data.
 */
final class DeckExporterTest extends TestCase
{
	private ObjectFetcher $objectFetcher;
	private DeckExporter $exporter;

	protected function setUp(): void
	{
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->exporter      = new DeckExporter($this->objectFetcher);
	}

	/** @param array<string,mixed> $objectData */
	private function objectWithDeck(array $objectData, bool $isDeck = true): ObjectData
	{
		$object             = $this->createMock(ObjectData::class);
		$object->properties = collect([
			'items' => $isDeck ? $this->createMock(DeckData::class) : 'not-a-deck',
		]);
		$object->method('toArray')->willReturn($objectData);

		return $object;
	}

	// ── Reading the deck off an object ───────────────────────────────────────

	public function testReturnsTheDeckItems(): void
	{
		$items = [['title' => 'One'], ['title' => 'Two']];
		$this->objectFetcher->method('fetchObject')->willReturn($this->objectWithDeck(['items' => $items]));

		$this->assertSame($items, $this->exporter->fetchDeckItems('blog', 'post-1', 'items'));
	}

	public function testRefusesAPropertyThatIsNotADeck(): void
	{
		// Exporting a non-deck property would produce a file shaped like
		// nothing in particular rather than an empty or partial deck.
		$this->objectFetcher->method('fetchObject')
			->willReturn($this->objectWithDeck(['items' => 'plain text'], isDeck: false));

		$this->expectException(\InvalidArgumentException::class);

		$this->exporter->fetchDeckItems('blog', 'post-1', 'items');
	}

	public function testAnEmptyDeckReadsAsAnEmptyList(): void
	{
		$this->objectFetcher->method('fetchObject')->willReturn($this->objectWithDeck([]));

		$this->assertSame([], $this->exporter->fetchDeckItems('blog', 'post-1', 'items'));
	}

	// ── CSV ──────────────────────────────────────────────────────────────────

	public function testCsvHeadersAreTheUnionOfEveryItemsKeys(): void
	{
		// Decks are ragged: one row having a field the others lack must not
		// drop that column, or exporting loses whichever fields the first item
		// happened not to use.
		$csv = $this->exporter->toCsv([
			['title' => 'One'],
			['title' => 'Two', 'subtitle' => 'Second'],
			['note' => 'Third'],
		]);

		$rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

		$this->assertSame(['title', 'subtitle', 'note'], $rows[0]);
		// A row without a column gets an empty cell, so every row has the same
		// width and the file stays parseable.
		$this->assertSame(['One', '', ''], $rows[1]);
		$this->assertSame(['Two', 'Second', ''], $rows[2]);
		$this->assertSame(['', '', 'Third'], $rows[3]);
	}

	public function testCsvWritesBooleansAsTrueAndFalse(): void
	{
		// Not PHP's cast, which would render false as an empty cell —
		// indistinguishable from a missing value, and the bug the card
		// exporter had.
		$csv  = $this->exporter->toCsv([['featured' => true, 'archived' => false]]);
		$rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

		$this->assertSame(['true', 'false'], $rows[1]);
	}

	public function testCsvJsonEncodesANestedValue(): void
	{
		$csv  = $this->exporter->toCsv([['image' => ['file' => 'hero.jpg', 'alt' => 'Hero']]]);
		$rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

		$this->assertSame(['{"file":"hero.jpg","alt":"Hero"}'], $rows[1]);
	}

	public function testCsvEscapesNewlinesSoEachItemStaysOneRecord(): void
	{
		// A literal newline inside a value would otherwise split one deck item
		// across two CSV records.
		$csv = $this->exporter->toCsv([['body' => "Line one\nLine two"]]);

		$this->assertStringNotContainsString("Line one\nLine two", $csv);
		$this->assertStringContainsString('Line one\nLine two', $csv);
	}

	public function testAnEmptyDeckProducesAnEmptyCsv(): void
	{
		$this->assertSame('', trim($this->exporter->toCsv([])));
	}

	// ── JSON ─────────────────────────────────────────────────────────────────

	public function testJsonRoundTripsTheItemsUnchanged(): void
	{
		$items = [['title' => 'One', 'featured' => true, 'image' => ['file' => 'a.jpg']]];

		$decoded = json_decode($this->exporter->toJson($items), true);

		// JSON keeps the types CSV has to flatten, which is why both formats
		// exist.
		$this->assertSame($items, $decoded);
		$this->assertTrue($decoded[0]['featured']);
	}

	public function testJsonIsReadable(): void
	{
		$this->assertStringContainsString("\n", $this->exporter->toJson([['title' => 'One']]));
	}
}
