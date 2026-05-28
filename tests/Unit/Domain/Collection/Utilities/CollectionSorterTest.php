<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Collection\Utilities;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Utilities\CollectionSorter;

final class CollectionSorterTest extends TestCase
{
	// --- isSortableType — canonical answer to "does this form-field type ──────
	//     have a well-defined ordering?" Lives alongside the sort code so the
	//     truth is co-located with the implementation.

	public function testIsSortableTypeReturnsTrueForNumericAndTemporalTypes(): void
	{
		$this->assertTrue(CollectionSorter::isSortableType('number'));
		$this->assertTrue(CollectionSorter::isSortableType('range'));
		$this->assertTrue(CollectionSorter::isSortableType('date'));
		$this->assertTrue(CollectionSorter::isSortableType('datetime'));
	}

	public function testIsSortableTypeReturnsTrueForIdField(): void
	{
		// `sort=id:asc` is the natural deterministic fallback when a collection
		// has no other sortable property — locked in by the Chunk B loose-thread.
		$this->assertTrue(CollectionSorter::isSortableType('id'));
	}

	public function testIsSortableTypeReturnsFalseForFreeTextTypes(): void
	{
		// Sorting `styledtext` lexicographically would order by the start of an
		// HTML tag, not the content — almost never useful. Operators can opt in
		// per-property via mcp.sortable if they really want it.
		$this->assertFalse(CollectionSorter::isSortableType('styledtext'));
		$this->assertFalse(CollectionSorter::isSortableType('textarea'));
	}

	public function testIsSortableTypeReturnsFalseForUnknownType(): void
	{
		$this->assertFalse(CollectionSorter::isSortableType('made-up-type'));
	}

	public function testShuffleReturnsAllItems(): void
	{
		$collection = [
			['id' => '1', 'title' => 'First'],
			['id' => '2', 'title' => 'Second'],
			['id' => '3', 'title' => 'Third'],
		];

		$sorter = new CollectionSorter($collection);
		$result = $sorter->shuffle();

		$this->assertCount(3, $result);
	}

	public function testSortByRulesReturnsEmptyForEmptyCollection(): void
	{
		$sorter = new CollectionSorter([]);
		$result = $sorter->sortByRules([['property' => 'title']]);

		$this->assertSame([], $result);
	}

	public function testSortByRulesReturnsUnchangedForEmptyRules(): void
	{
		$collection = [
			['id' => '1', 'title' => 'First'],
			['id' => '2', 'title' => 'Second'],
		];

		$sorter = new CollectionSorter($collection);
		$result = $sorter->sortByRules([]);

		$this->assertSame($collection, $result);
	}

	public function testSortByRulesReturnsSingleItem(): void
	{
		$collection = [['id' => '1', 'title' => 'Only']];

		$sorter = new CollectionSorter($collection);
		$result = $sorter->sortByRules([['property' => 'title']]);

		$this->assertSame($collection, $result);
	}

	public function testSortByRulesSortsAscending(): void
	{
		$collection = [
			['id' => '2', 'title' => 'Banana'],
			['id' => '1', 'title' => 'Apple'],
			['id' => '3', 'title' => 'Cherry'],
		];

		$sorter = new CollectionSorter($collection);
		$result = $sorter->sortByRules([['property' => 'title']]);

		$this->assertSame('Apple', $result[0]['title']);
		$this->assertSame('Banana', $result[1]['title']);
		$this->assertSame('Cherry', $result[2]['title']);
	}

	public function testSortByRulesSortsDescending(): void
	{
		$collection = [
			['id' => '2', 'title' => 'Banana'],
			['id' => '1', 'title' => 'Apple'],
			['id' => '3', 'title' => 'Cherry'],
		];

		$sorter = new CollectionSorter($collection);
		$result = $sorter->sortByRules([['property' => 'title', 'reverse' => true]]);

		$this->assertSame('Cherry', $result[0]['title']);
		$this->assertSame('Banana', $result[1]['title']);
		$this->assertSame('Apple', $result[2]['title']);
	}

	public function testSortByRulesWithNaturalSort(): void
	{
		$collection = [
			['id' => '1', 'name' => 'item10'],
			['id' => '2', 'name' => 'item2'],
			['id' => '3', 'name' => 'item1'],
		];

		$sorter = new CollectionSorter($collection);
		$result = $sorter->sortByRules([['property' => 'name', 'natural' => true]]);

		$this->assertSame('item1', $result[0]['name']);
		$this->assertSame('item2', $result[1]['name']);
		$this->assertSame('item10', $result[2]['name']);
	}

	public function testSortByRulesWithShuffle(): void
	{
		$collection = [
			['id' => '1', 'title' => 'First'],
			['id' => '2', 'title' => 'Second'],
			['id' => '3', 'title' => 'Third'],
		];

		$sorter = new CollectionSorter($collection);
		$result = $sorter->sortByRules([['shuffle' => true]]);

		$this->assertCount(3, $result);
	}

	public function testSortByRulesHandlesMissingProperty(): void
	{
		$collection = [
			['id' => '1', 'title' => 'Has Title'],
			['id' => '2'],  // No title
			['id' => '3', 'title' => 'Another Title'],
		];

		$sorter = new CollectionSorter($collection);
		$result = $sorter->sortByRules([['property' => 'title']]);

		$this->assertCount(3, $result);
	}

	public function testSortByRulesWithNumericValues(): void
	{
		$collection = [
			['id' => '1', 'count' => 100],
			['id' => '2', 'count' => 5],
			['id' => '3', 'count' => 50],
		];

		$sorter = new CollectionSorter($collection);
		$result = $sorter->sortByRules([['property' => 'count']]);

		$this->assertSame(5, $result[0]['count']);
		$this->assertSame(50, $result[1]['count']);
		$this->assertSame(100, $result[2]['count']);
	}

	public function testSortByRulesSkipsRulesWithoutProperty(): void
	{
		$collection = [
			['id' => '2', 'title' => 'B'],
			['id' => '1', 'title' => 'A'],
		];

		$sorter = new CollectionSorter($collection);
		$result = $sorter->sortByRules([
			['reverse' => true],  // No property, should be skipped
			['property' => 'title'],
		]);

		$this->assertSame('A', $result[0]['title']);
		$this->assertSame('B', $result[1]['title']);
	}

	// --- sortByProperty (static shorthand) ---

	public function testSortByPropertyAscending(): void
	{
		$items = [
			['id' => '2', 'title' => 'Banana'],
			['id' => '1', 'title' => 'Apple'],
			['id' => '3', 'title' => 'Cherry'],
		];

		$result = CollectionSorter::sortByProperty($items, 'title');

		$this->assertSame('Apple', $result[0]['title']);
		$this->assertSame('Banana', $result[1]['title']);
		$this->assertSame('Cherry', $result[2]['title']);
	}

	public function testSortByPropertyDescending(): void
	{
		$items = [
			['id' => '2', 'title' => 'Banana'],
			['id' => '1', 'title' => 'Apple'],
			['id' => '3', 'title' => 'Cherry'],
		];

		$result = CollectionSorter::sortByProperty($items, '-title');

		$this->assertSame('Cherry', $result[0]['title']);
		$this->assertSame('Banana', $result[1]['title']);
		$this->assertSame('Apple', $result[2]['title']);
	}

	public function testSortByPropertyWithEmptyString(): void
	{
		$items = [
			['id' => '2', 'title' => 'Banana'],
			['id' => '1', 'title' => 'Apple'],
		];

		$result = CollectionSorter::sortByProperty($items, '');

		$this->assertSame($items, $result);
	}

	public function testSortByPropertyWithSingleItem(): void
	{
		$items = [['id' => '1', 'title' => 'Only']];

		$result = CollectionSorter::sortByProperty($items, 'title');

		$this->assertSame($items, $result);
	}

	public function testSortByPropertyWithNumericValues(): void
	{
		$items = [
			['id' => '1', 'count' => 100],
			['id' => '2', 'count' => 5],
			['id' => '3', 'count' => 50],
		];

		$result = CollectionSorter::sortByProperty($items, 'count');

		$this->assertSame(5, $result[0]['count']);
		$this->assertSame(50, $result[1]['count']);
		$this->assertSame(100, $result[2]['count']);
	}

	public function testSortByPropertyDescendingNumeric(): void
	{
		$items = [
			['id' => '1', 'count' => 100],
			['id' => '2', 'count' => 5],
			['id' => '3', 'count' => 50],
		];

		$result = CollectionSorter::sortByProperty($items, '-count');

		$this->assertSame(100, $result[0]['count']);
		$this->assertSame(50, $result[1]['count']);
		$this->assertSame(5, $result[2]['count']);
	}

	public function testSortByRulesWithMultipleCriteria(): void
	{
		$collection = [
			['id' => '1', 'category' => 'B', 'title' => 'First'],
			['id' => '2', 'category' => 'A', 'title' => 'Second'],
			['id' => '3', 'category' => 'A', 'title' => 'First'],
		];

		$sorter = new CollectionSorter($collection);
		$result = $sorter->sortByRules([
			['property' => 'category'],
			['property' => 'title'],
		]);

		// First rule (category) is primary, second rule (title) breaks ties
		$this->assertSame('A', $result[0]['category']);
		$this->assertSame('First', $result[0]['title']);   // A, First
		$this->assertSame('A', $result[1]['category']);
		$this->assertSame('Second', $result[1]['title']);  // A, Second
		$this->assertSame('B', $result[2]['category']);     // B, First
	}
}
