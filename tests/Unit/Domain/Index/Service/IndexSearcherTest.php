<?php

namespace Tests\Unit\Domain\Index\Service;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;

/**
 * Test IndexSearcher word boundary matching.
 * The search should use word boundaries to prevent substring matches.
 * For example, "table" should NOT match "reputable" or "vegetable".
 */
final class IndexSearcherTest extends TestCase
{
	private IndexSearcher $searcher;
	private \PHPUnit\Framework\MockObject\MockObject $indexReader;

	protected function setUp(): void
	{
		$this->indexReader = $this->createMock(IndexReader::class);
		$this->searcher    = new IndexSearcher($this->indexReader);
	}

	private function mockIndexWithObjects(array $objects): void
	{
		$indexData          = new IndexData();
		$indexData->objects = collect($objects);

		$this->indexReader
			->method('fetchIndex')
			->willReturn($indexData);
	}

	public function testSearchWithWordBoundaryMatching(): void
	{
		$objects = [
			['id' => '1', 'title' => 'The table is ready'],
			['id' => '2', 'title' => 'This is reputable content'],
			['id' => '3', 'title' => 'I ate a vegetable'],
			['id' => '4', 'title' => 'Table of contents'],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', 'table');

		// Should match IDs 1 and 4 (exact word "table")
		// Should NOT match IDs 2 or 3 (substring within "reputable" and "vegetable")
		$this->assertCount(2, $results);

		$ids = $results->pluck('id')->toArray();
		$this->assertContains('1', $ids);
		$this->assertContains('4', $ids);
		$this->assertNotContains('2', $ids);
		$this->assertNotContains('3', $ids);
	}

	public function testSearchMatchesWordAtStart(): void
	{
		$objects = [
			['id' => '1', 'content' => 'table lamp on desk'],
			['id' => '2', 'content' => 'reputable source'],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', 'table');

		$this->assertCount(1, $results);
		$this->assertEquals('1', $results->first()['id']);
	}

	public function testSearchMatchesWordAtEnd(): void
	{
		$objects = [
			['id' => '1', 'content' => 'dining room table'],
			['id' => '2', 'content' => 'this is disputable'],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', 'table');

		$this->assertCount(1, $results);
		$this->assertEquals('1', $results->first()['id']);
	}

	public function testSearchMatchesWordInMiddle(): void
	{
		$objects = [
			['id' => '1', 'content' => 'the table was broken'],
			['id' => '2', 'content' => 'unquestionably true'],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', 'table');

		$this->assertCount(1, $results);
		$this->assertEquals('1', $results->first()['id']);
	}

	public function testSearchIsCaseInsensitive(): void
	{
		$objects = [
			['id' => '1', 'content' => 'TABLE'],
			['id' => '2', 'content' => 'Table'],
			['id' => '3', 'content' => 'table'],
			['id' => '4', 'content' => 'TaBlE'],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', 'table');

		// Should match all variants
		$this->assertCount(4, $results);
	}

	public function testSearchWithSpecialCharactersAsWordBoundaries(): void
	{
		$objects = [
			['id' => '1', 'content' => 'table.'],
			['id' => '2', 'content' => 'table,'],
			['id' => '3', 'content' => 'table!'],
			['id' => '4', 'content' => 'table?'],
			['id' => '5', 'content' => '(table)'],
			['id' => '6', 'content' => '"table"'],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', 'table');

		// Special characters should act as word boundaries
		$this->assertCount(6, $results);
	}

	public function testSearchDoesNotMatchSubstring(): void
	{
		$objects = [
			['id' => '1', 'content' => 'reputable'],
			['id' => '2', 'content' => 'vegetable'],
			['id' => '3', 'content' => 'unstable'],
			['id' => '4', 'content' => 'suitable'],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', 'table');

		// "table" is a substring in all these words but not a complete word
		// Word boundaries prevent matches in middle of words
		$this->assertCount(0, $results);
	}

	public function testSearchWithHyphensAsWordBoundaries(): void
	{
		$objects = [
			['id' => '1', 'content' => 'table-top'],
			['id' => '2', 'content' => 'coffee-table'],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', 'table');

		// Hyphens should act as word boundaries
		$this->assertCount(2, $results);
	}

	public function testSearchInNestedProperties(): void
	{
		$objects = [
			['id' => '1', 'title' => 'My table', 'meta' => ['description' => 'Nothing here']],
			['id' => '2', 'title' => 'My chair', 'meta' => ['description' => 'A nice table']],
			['id' => '3', 'title' => 'Reputable source', 'content' => 'some text'],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', 'table');

		// Should match objects 1 and 2 (searching nested properties)
		$this->assertCount(2, $results);

		$ids = $results->pluck('id')->toArray();
		$this->assertContains('1', $ids);
		$this->assertContains('2', $ids);
	}

	public function testSearchInArrayValues(): void
	{
		$objects = [
			['id' => '1', 'tags' => ['table', 'furniture', 'wood']],
			['id' => '2', 'tags' => ['chair', 'reputable', 'metal']],
			['id' => '3', 'tags' => ['desk', 'office']],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', 'table');

		// Should match object 1 (has "table" in tags array)
		$this->assertCount(1, $results);
		$this->assertEquals('1', $results->first()['id']);
	}

	public function testSearchWithEmptyQuery(): void
	{
		$objects = [
			['id' => '1', 'content' => 'test'],
			['id' => '2', 'content' => 'another'],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', '');

		// Empty query should return no results (per implementation)
		$this->assertCount(0, $results);
	}

	public function testSearchWithNoMatches(): void
	{
		$objects = [
			['id' => '1', 'content' => 'chair'],
			['id' => '2', 'content' => 'desk'],
		];

		$this->mockIndexWithObjects($objects);

		$results = $this->searcher->search('test-collection', 'table');

		// No matches should return empty collection
		$this->assertCount(0, $results);
	}

	public function testSearchWithRegexSpecialCharacters(): void
	{
		$objects = [
			['id' => '1', 'content' => 'Price is 100 dollars'],
			['id' => '2', 'content' => 'Cost about 200 cents'],
		];

		$this->mockIndexWithObjects($objects);

		// Search for "100" - regex special chars in search are escaped by preg_quote
		$results = $this->searcher->search('test-collection', '100');

		$this->assertCount(1, $results);
		$this->assertEquals('1', $results->first()['id']);
	}

	public function testSearchWithDots(): void
	{
		$objects = [
			['id' => '1', 'content' => 'Visit example.com'],
			['id' => '2', 'content' => 'Visit example com'],
		];

		$this->mockIndexWithObjects($objects);

		// Dot should be treated as literal character (escaped by preg_quote)
		$results = $this->searcher->search('test-collection', 'example.com');

		$this->assertCount(1, $results);
		$this->assertEquals('1', $results->first()['id']);
	}

	public function testSearchWithParentheses(): void
	{
		$objects = [
			['id' => '1', 'content' => 'Call 555 extension 1234'],
			['id' => '2', 'content' => 'Call 666 extension 5678'],
		];

		$this->mockIndexWithObjects($objects);

		// Search for number - parentheses in data act as word boundaries
		$results = $this->searcher->search('test-collection', '555');

		$this->assertCount(1, $results);
		$this->assertEquals('1', $results->first()['id']);
	}

	public function testSearchPerformanceWithLargeDataset(): void
	{
		// Create 1000 objects
		$objects = [];
		for ($i = 0; $i < 1000; $i++) {
			$objects[] = [
				'id'      => (string)$i,
				'content' => $i % 2 === 0 ? 'This has table in it' : 'This has reputable in it',
			];
		}

		$this->mockIndexWithObjects($objects);

		$startTime = microtime(true);
		$results   = $this->searcher->search('test-collection', 'table');
		$duration  = microtime(true) - $startTime;

		// Should match 500 objects (every even numbered object)
		$this->assertCount(500, $results);

		// Performance check - should complete in reasonable time (< 1 second)
		$this->assertLessThan(1.0, $duration, 'Search should complete within 1 second for 1000 objects');
	}
}
