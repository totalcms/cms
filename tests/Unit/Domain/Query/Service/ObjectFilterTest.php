<?php

namespace Tests\Unit\Domain\Query\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Query\Service\ObjectFilter;

final class ObjectFilterTest extends TestCase
{
	private ObjectFilter $filter;

	protected function setUp(): void
	{
		$this->filter = new ObjectFilter();
	}

	// --- extractFilterOptions ---

	public function testExtractFilterOptionsIncludeOnly(): void
	{
		$result = $this->filter->extractFilterOptions(['include' => 'published:true', 'limit' => '10']);

		$this->assertSame(['include' => 'published:true'], $result);
	}

	public function testExtractFilterOptionsExcludeOnly(): void
	{
		$result = $this->filter->extractFilterOptions(['exclude' => 'draft:true', 'sort' => 'date']);

		$this->assertSame(['exclude' => 'draft:true'], $result);
	}

	public function testExtractFilterOptionsBoth(): void
	{
		$result = $this->filter->extractFilterOptions([
			'include' => 'published:true',
			'exclude' => 'archived:true',
		]);

		$this->assertSame([
			'include' => 'published:true',
			'exclude' => 'archived:true',
		], $result);
	}

	public function testExtractFilterOptionsReturnsEmptyWhenNone(): void
	{
		$result = $this->filter->extractFilterOptions(['limit' => '10', 'sort' => 'date']);

		$this->assertSame([], $result);
	}

	// --- parseFilterString ---

	public function testParseFilterStringFieldValue(): void
	{
		$result = $this->filter->parseFilterString('category:news');

		$this->assertCount(1, $result);
		$this->assertSame('category', $result[0]['field']);
		$this->assertSame('news', $result[0]['value']);
	}

	public function testParseFilterStringMultipleFields(): void
	{
		$result = $this->filter->parseFilterString('category:news,published:true');

		$this->assertCount(2, $result);
		$this->assertSame('category', $result[0]['field']);
		$this->assertSame('news', $result[0]['value']);
		$this->assertSame('published', $result[1]['field']);
		$this->assertTrue($result[1]['value']);
	}

	public function testParseFilterStringDefaultsToTrue(): void
	{
		$result = $this->filter->parseFilterString('featured');

		$this->assertCount(1, $result);
		$this->assertSame('featured', $result[0]['field']);
		$this->assertTrue($result[0]['value']);
	}

	public function testParseFilterStringFalseValue(): void
	{
		$result = $this->filter->parseFilterString('archived:false');

		$this->assertCount(1, $result);
		$this->assertSame('archived', $result[0]['field']);
		$this->assertFalse($result[0]['value']);
	}

	// --- filterObjects ---

	public function testFilterObjectsInclude(): void
	{
		$objects = [
			['id' => '1', 'published' => true],
			['id' => '2', 'published' => false],
			['id' => '3', 'published' => true],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'published:true']);

		$this->assertCount(2, $result);
		$this->assertSame('1', $result[0]['id']);
		$this->assertSame('3', $result[1]['id']);
	}

	public function testFilterObjectsExclude(): void
	{
		$objects = [
			['id' => '1', 'draft' => true],
			['id' => '2', 'draft' => false],
			['id' => '3', 'draft' => true],
		];

		$result = $this->filter->filterObjects($objects, ['exclude' => 'draft:true']);

		$this->assertCount(1, $result);
		$this->assertSame('2', $result[0]['id']);
	}

	public function testFilterObjectsCombinedIncludeExclude(): void
	{
		$objects = [
			['id' => '1', 'published' => true, 'archived' => false],
			['id' => '2', 'published' => true, 'archived' => true],
			['id' => '3', 'published' => false, 'archived' => false],
		];

		$result = $this->filter->filterObjects($objects, [
			'include' => 'published:true',
			'exclude' => 'archived:true',
		]);

		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}

	public function testFilterObjectsNoFiltersReturnsAll(): void
	{
		$objects = [
			['id' => '1'],
			['id' => '2'],
		];

		$result = $this->filter->filterObjects($objects, []);

		$this->assertCount(2, $result);
	}

	// --- matchesFilter ---

	public function testMatchesFilterBooleanField(): void
	{
		$object = ['published' => true];

		$this->assertTrue($this->filter->matchesFilter($object, ['include' => 'published:true']));
		$this->assertFalse($this->filter->matchesFilter($object, ['include' => 'published:false']));
	}

	public function testMatchesFilterStringFieldCaseInsensitive(): void
	{
		$object = ['category' => 'News'];

		$this->assertTrue($this->filter->matchesFilter($object, ['include' => 'category:news']));
		$this->assertTrue($this->filter->matchesFilter($object, ['include' => 'category:NEWS']));
	}

	public function testMatchesFilterArrayFieldCaseInsensitive(): void
	{
		$object = ['tags' => ['News', 'Tech', 'Science']];

		$this->assertTrue($this->filter->matchesFilter($object, ['include' => 'tags:news']));
		$this->assertTrue($this->filter->matchesFilter($object, ['include' => 'tags:TECH']));
		$this->assertFalse($this->filter->matchesFilter($object, ['include' => 'tags:sports']));
	}

	public function testMatchesFilterMissingFieldExcludedFromInclude(): void
	{
		$object = ['id' => '1'];

		$this->assertFalse($this->filter->matchesFilter($object, ['include' => 'published:true']));
	}

	public function testMatchesFilterExcludeTakesPrecedence(): void
	{
		$object = ['published' => true, 'archived' => true];

		// Object matches include but also matches exclude — should be excluded
		$this->assertFalse($this->filter->matchesFilter($object, [
			'include' => 'published:true',
			'exclude' => 'archived:true',
		]));
	}

	public function testMatchesFilterEmptyOptionsReturnsTrue(): void
	{
		$object = ['id' => '1'];

		$this->assertTrue($this->filter->matchesFilter($object, []));
	}

	public function testFilterObjectsReindexesKeys(): void
	{
		$objects = [
			['id' => '1', 'published' => false],
			['id' => '2', 'published' => true],
			['id' => '3', 'published' => false],
			['id' => '4', 'published' => true],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'published:true']);

		$this->assertCount(2, $result);
		$this->assertArrayHasKey(0, $result);
		$this->assertArrayHasKey(1, $result);
		$this->assertSame('2', $result[0]['id']);
		$this->assertSame('4', $result[1]['id']);
	}

	public function testIncludeWithMultipleConditionsRequiresAll(): void
	{
		$objects = [
			['id' => '1', 'published' => true, 'category' => 'news'],
			['id' => '2', 'published' => true, 'category' => 'blog'],
			['id' => '3', 'published' => false, 'category' => 'news'],
		];

		$result = $this->filter->filterObjects($objects, ['include' => 'published:true,category:news']);

		$this->assertCount(1, $result);
		$this->assertSame('1', $result[0]['id']);
	}

	public function testExcludeWithMultipleConditionsExcludesAny(): void
	{
		$objects = [
			['id' => '1', 'draft' => true, 'archived' => false],
			['id' => '2', 'draft' => false, 'archived' => true],
			['id' => '3', 'draft' => false, 'archived' => false],
		];

		$result = $this->filter->filterObjects($objects, ['exclude' => 'draft:true,archived:true']);

		$this->assertCount(1, $result);
		$this->assertSame('3', $result[0]['id']);
	}
}
