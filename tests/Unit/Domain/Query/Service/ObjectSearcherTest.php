<?php

namespace Tests\Unit\Domain\Query\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Query\Service\ObjectSearcher;

final class ObjectSearcherTest extends TestCase
{
	private ObjectSearcher $searcher;

	protected function setUp(): void
	{
		$this->searcher = new ObjectSearcher();
	}

	public function testAndLogicAllTermsMustMatch(): void
	{
		$items = [
			['id' => '1', 'title' => 'Red table in room'],
			['id' => '2', 'title' => 'Red chair in room'],
			['id' => '3', 'title' => 'Blue table outside'],
		];

		$results = $this->searcher->search($items, 'red table');

		$this->assertCount(1, $results);
		$this->assertSame('1', $results[0]['id']);
	}

	public function testAndLogicWithExplicitAndKeyword(): void
	{
		$items = [
			['id' => '1', 'title' => 'Red table in room'],
			['id' => '2', 'title' => 'Red chair in room'],
		];

		$results = $this->searcher->search($items, 'red and table');

		$this->assertCount(1, $results);
		$this->assertSame('1', $results[0]['id']);
	}

	public function testOrLogicAnyTermMatches(): void
	{
		$items = [
			['id' => '1', 'title' => 'Red table'],
			['id' => '2', 'title' => 'Blue chair'],
			['id' => '3', 'title' => 'Green desk'],
		];

		$results = $this->searcher->search($items, 'red or blue');

		$this->assertCount(2, $results);
		$ids = array_column($results, 'id');
		$this->assertContains('1', $ids);
		$this->assertContains('2', $ids);
	}

	public function testQuotedPhraseSupport(): void
	{
		$items = [
			['id' => '1', 'title' => 'The red table is here'],
			['id' => '2', 'title' => 'Table is red'],
		];

		$results = $this->searcher->search($items, '"red table"');

		$this->assertCount(1, $results);
		$this->assertSame('1', $results[0]['id']);
	}

	public function testEmptyQueryReturnsEmpty(): void
	{
		$items = [
			['id' => '1', 'title' => 'Something'],
		];

		$results = $this->searcher->search($items, '');
		$this->assertSame([], $results);
	}

	public function testWhitespaceOnlyQueryReturnsEmpty(): void
	{
		$items = [
			['id' => '1', 'title' => 'Something'],
		];

		$results = $this->searcher->search($items, '   ');
		$this->assertSame([], $results);
	}

	public function testSearchesNestedArrayValues(): void
	{
		$items = [
			['id' => '1', 'title' => 'Post', 'meta' => ['description' => 'A nice table']],
			['id' => '2', 'title' => 'Post', 'meta' => ['description' => 'A nice chair']],
		];

		$results = $this->searcher->search($items, 'table');

		$this->assertCount(1, $results);
		$this->assertSame('1', $results[0]['id']);
	}

	public function testSearchesArrayFieldValues(): void
	{
		$items = [
			['id' => '1', 'tags' => ['table', 'furniture']],
			['id' => '2', 'tags' => ['chair', 'office']],
		];

		$results = $this->searcher->search($items, 'table');

		$this->assertCount(1, $results);
		$this->assertSame('1', $results[0]['id']);
	}

	public function testWordBoundaryMatchingNoPartialMatches(): void
	{
		$items = [
			['id' => '1', 'title' => 'The table is ready'],
			['id' => '2', 'title' => 'This is reputable'],
			['id' => '3', 'title' => 'A vegetable dish'],
		];

		$results = $this->searcher->search($items, 'table');

		$this->assertCount(1, $results);
		$this->assertSame('1', $results[0]['id']);
	}

	public function testCaseInsensitiveSearch(): void
	{
		$items = [
			['id' => '1', 'title' => 'TABLE'],
			['id' => '2', 'title' => 'Table'],
			['id' => '3', 'title' => 'table'],
		];

		$results = $this->searcher->search($items, 'table');

		$this->assertCount(3, $results);
	}

	public function testSearchWithRegexSpecialCharacters(): void
	{
		$items = [
			['id' => '1', 'content' => 'Price is $100'],
			['id' => '2', 'content' => 'Price is $200'],
		];

		$results = $this->searcher->search($items, '100');

		$this->assertCount(1, $results);
		$this->assertSame('1', $results[0]['id']);
	}

	public function testSearchReturnsReindexedArray(): void
	{
		$items = [
			['id' => '1', 'title' => 'No match'],
			['id' => '2', 'title' => 'Match table here'],
			['id' => '3', 'title' => 'No match either'],
			['id' => '4', 'title' => 'Another table'],
		];

		$results = $this->searcher->search($items, 'table');

		$this->assertCount(2, $results);
		// Keys should be sequential 0, 1
		$this->assertArrayHasKey(0, $results);
		$this->assertArrayHasKey(1, $results);
	}

	public function testSearchSkipsEmptyValues(): void
	{
		$items = [
			['id' => '1', 'title' => '', 'content' => 'table'],
			['id' => '2', 'title' => null, 'content' => 'chair'],
		];

		$results = $this->searcher->search($items, 'table');

		$this->assertCount(1, $results);
		$this->assertSame('1', $results[0]['id']);
	}

	public function testSearchMatchesScalarNumericValues(): void
	{
		$items = [
			['id' => '1', 'price' => 42],
			['id' => '2', 'price' => 99],
		];

		$results = $this->searcher->search($items, '42');

		$this->assertCount(1, $results);
		$this->assertSame('1', $results[0]['id']);
	}

	public function testSearchScoredRanksByTermCoverageWithOrRecall(): void
	{
		$items = [
			// matches animated + hamburger + menu (3); NOT "navigation"
			['id' => 'burger', 'name' => 'Burger', 'subtitle' => 'Animated Menu Hamburgers',
				'tags' => ['nav', 'menu', 'hamburger', 'animation']],
			// matches menu + navigation (2)
			['id' => 'rails', 'name' => 'Rails', 'subtitle' => 'On Page Nav',
				'tags' => ['menu', 'navigation']],
			// matches navigation only (1), via description prose
			['id' => 'glider', 'name' => 'Glider', 'description' => 'easy reading and navigation',
				'tags' => ['slider']],
		];

		$scored = $this->searcher->searchScored($items, 'animated hamburger menu navigation');

		// OR recall: all three are candidates (each matched >= 1 term).
		$this->assertCount(3, $scored);
		$this->assertSame('burger', $scored[0]['item']['id']);
		$this->assertSame(3, $scored[0]['matched']);
		$this->assertSame('rails', $scored[1]['item']['id']);
		$this->assertSame(2, $scored[1]['matched']);
		$this->assertSame('glider', $scored[2]['item']['id']);
		$this->assertSame(1, $scored[2]['matched']);
	}

	public function testSearchScoredReturnsEmptyForEmptyOrStopwordQuery(): void
	{
		$items = [['id' => 'x', 'tags' => ['menu']]];
		$this->assertSame([], $this->searcher->searchScored($items, ''));
		$this->assertSame([], $this->searcher->searchScored($items, 'and or'));
	}

	public function testSearchScoredDropsItemsMatchingNoTerm(): void
	{
		$items = [
			['id' => 'burger', 'tags' => ['hamburger']],
			['id' => 'other', 'tags' => ['slider']],
		];
		$scored = $this->searcher->searchScored($items, 'hamburger');
		$this->assertCount(1, $scored);
		$this->assertSame('burger', $scored[0]['item']['id']);
	}

	public function testSearchScoredAppliesFieldWeights(): void
	{
		// Equal term coverage (1 each) -> weighted score decides order.
		$items = [
			['id' => 'a', 'description' => 'gallery'], // best field weight = 1.0
			['id' => 'b', 'name' => 'gallery'],        // best field weight = 5.0
		];
		$scored = $this->searcher->searchScored($items, 'gallery', ['name' => 5.0, 'description' => 1.0]);
		$this->assertSame('b', $scored[0]['item']['id']);
		$this->assertSame('a', $scored[1]['item']['id']);
	}

	public function testSearchScoredBreaksTiesByIdAscending(): void
	{
		$items = [
			['id' => 'zeta', 'tags' => ['menu']],
			['id' => 'alpha', 'tags' => ['menu']],
		];
		$scored = $this->searcher->searchScored($items, 'menu');
		$this->assertSame('alpha', $scored[0]['item']['id']);
		$this->assertSame('zeta', $scored[1]['item']['id']);
	}
}
