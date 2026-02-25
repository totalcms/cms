<?php

namespace Tests\Unit\Domain\Index\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Query\Service\ObjectFilter;

final class IndexFilterTest extends TestCase
{
	private IndexFilter $filter;

	protected function setUp(): void
	{
		$indexReader  = $this->createMock(IndexReader::class);
		$this->filter = new IndexFilter($indexReader, new ObjectFilter());
	}

	public function testExtractsFilterOptions(): void
	{
		$options = [
			'include' => 'published:true',
			'exclude' => 'draft:true',
			'other'   => 'value',
		];

		$filterOptions = $this->filter->extractFilterOptions($options);

		$this->assertCount(2, $filterOptions);
		$this->assertEquals('published:true', $filterOptions['include']);
		$this->assertEquals('draft:true', $filterOptions['exclude']);
	}

	public function testParsesFilterString(): void
	{
		$filterString = 'published:true,featured:true,status:active';

		$parsed = $this->filter->parseFilterString($filterString);

		$this->assertCount(3, $parsed);
		$this->assertEquals('published', $parsed[0]['field']);
		$this->assertTrue($parsed[0]['value']);
		$this->assertEquals('featured', $parsed[1]['field']);
		$this->assertTrue($parsed[1]['value']);
		$this->assertEquals('status', $parsed[2]['field']);
		$this->assertEquals('active', $parsed[2]['value']);
	}

	public function testParsesFilterStringWithDefaultValue(): void
	{
		$filterString = 'published,featured';

		$parsed = $this->filter->parseFilterString($filterString);

		$this->assertCount(2, $parsed);
		$this->assertEquals('published', $parsed[0]['field']);
		$this->assertTrue($parsed[0]['value']); // Defaults to true
		$this->assertEquals('featured', $parsed[1]['field']);
		$this->assertTrue($parsed[1]['value']); // Defaults to true
	}

	public function testParsesFilterStringWithFalseValue(): void
	{
		$filterString = 'draft:false,deleted:false';

		$parsed = $this->filter->parseFilterString($filterString);

		$this->assertCount(2, $parsed);
		$this->assertEquals('draft', $parsed[0]['field']);
		$this->assertFalse($parsed[0]['value']);
		$this->assertEquals('deleted', $parsed[1]['field']);
		$this->assertFalse($parsed[1]['value']);
	}

	public function testMatchesFilterWithNoFilters(): void
	{
		$object = ['id' => '1', 'published' => true];

		$result = $this->filter->matchesFilter($object, []);

		$this->assertTrue($result);
	}

	public function testMatchesFilterWithInclude(): void
	{
		$object = ['id' => '1', 'published' => true, 'featured' => true];

		$filterOptions = ['include' => 'published:true,featured:true'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertTrue($result);
	}

	public function testDoesNotMatchFilterWithInclude(): void
	{
		$object = ['id' => '1', 'published' => true, 'featured' => false];

		$filterOptions = ['include' => 'published:true,featured:true'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertFalse($result);
	}

	public function testMatchesFilterWithExclude(): void
	{
		$object = ['id' => '1', 'published' => true, 'draft' => false];

		$filterOptions = ['exclude' => 'draft:true,deleted:true'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertTrue($result); // Not excluded
	}

	public function testDoesNotMatchFilterWithExclude(): void
	{
		$object = ['id' => '1', 'published' => false, 'draft' => true];

		$filterOptions = ['exclude' => 'draft:true'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertFalse($result); // Excluded
	}

	public function testExcludeTakesPrecedenceOverInclude(): void
	{
		$object = ['id' => '1', 'published' => true, 'draft' => true];

		$filterOptions = [
			'include' => 'published:true',
			'exclude' => 'draft:true',
		];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertFalse($result); // Excluded even though it matches include
	}

	public function testFiltersArrayOfObjects(): void
	{
		$objects = [
			['id' => '1', 'published' => true, 'draft' => false],
			['id' => '2', 'published' => true, 'draft' => true],
			['id' => '3', 'published' => false, 'draft' => false],
			['id' => '4', 'published' => true, 'draft' => false],
		];

		$filtered = $this->filter->filterObjects($objects, [
			'include' => 'published:true',
			'exclude' => 'draft:true',
		]);

		$this->assertCount(2, $filtered);
		$this->assertEquals('1', $filtered[0]['id']);
		$this->assertEquals('4', $filtered[1]['id']);
	}

	public function testFiltersArrayWithNoFilters(): void
	{
		$objects = [
			['id' => '1', 'published' => true],
			['id' => '2', 'published' => false],
		];

		$filtered = $this->filter->filterObjects($objects, []);

		$this->assertCount(2, $filtered);
		$this->assertEquals($objects, $filtered);
	}

	public function testFiltersArrayReindexesKeys(): void
	{
		$objects = [
			0 => ['id' => '1', 'published' => false],
			1 => ['id' => '2', 'published' => true],
			2 => ['id' => '3', 'published' => false],
			3 => ['id' => '4', 'published' => true],
		];

		$filtered = $this->filter->filterObjects($objects, ['include' => 'published:true']);

		// Should reindex to 0, 1 instead of keeping 1, 3
		$this->assertCount(2, $filtered);
		$this->assertArrayHasKey(0, $filtered);
		$this->assertArrayHasKey(1, $filtered);
		$this->assertEquals('2', $filtered[0]['id']);
		$this->assertEquals('4', $filtered[1]['id']);
	}

	public function testHandlesStringValues(): void
	{
		$object = ['id' => '1', 'status' => 'active', 'category' => 'news'];

		$filterOptions = ['include' => 'status:active,category:news'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertTrue($result);
	}

	public function testHandlesMissingFields(): void
	{
		$object = ['id' => '1', 'published' => true];

		$filterOptions = ['include' => 'published:true,featured:true'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertFalse($result); // Missing 'featured' field
	}

	public function testIncludeWithArrayFieldMatches(): void
	{
		$object = ['id' => '1', 'tags' => ['travel', 'adventure', 'europe']];

		$filterOptions = ['include' => 'tags:travel'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertTrue($result); // 'travel' exists in tags array
	}

	public function testIncludeWithArrayFieldDoesNotMatch(): void
	{
		$object = ['id' => '1', 'tags' => ['food', 'recipe', 'cooking']];

		$filterOptions = ['include' => 'tags:travel'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertFalse($result); // 'travel' not in tags array
	}

	public function testExcludeWithArrayFieldMatches(): void
	{
		$object = ['id' => '1', 'tags' => ['travel', 'archived', 'europe']];

		$filterOptions = ['exclude' => 'tags:archived'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertFalse($result); // Excluded because 'archived' in tags
	}

	public function testExcludeWithArrayFieldDoesNotMatch(): void
	{
		$object = ['id' => '1', 'tags' => ['travel', 'adventure', 'europe']];

		$filterOptions = ['exclude' => 'tags:archived'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertTrue($result); // Not excluded because 'archived' not in tags
	}

	public function testArrayFieldWithMultipleIncludeCriteria(): void
	{
		$object = ['id' => '1', 'published' => true, 'tags' => ['travel', 'featured']];

		$filterOptions = ['include' => 'published:true,tags:travel'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertTrue($result); // Both conditions match
	}

	public function testArrayFieldWithMultipleIncludeCriteriaDoesNotMatch(): void
	{
		$object = ['id' => '1', 'published' => true, 'tags' => ['food', 'recipe']];

		$filterOptions = ['include' => 'published:true,tags:travel'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertFalse($result); // 'travel' not in tags
	}

	public function testFiltersArrayOfObjectsWithArrayFields(): void
	{
		$objects = [
			['id' => '1', 'tags' => ['travel', 'europe']],
			['id' => '2', 'tags' => ['food', 'recipe']],
			['id' => '3', 'tags' => ['travel', 'asia']],
			['id' => '4', 'tags' => ['sports', 'football']],
		];

		$filtered = $this->filter->filterObjects($objects, [
			'include' => 'tags:travel',
		]);

		$this->assertCount(2, $filtered);
		$this->assertEquals('1', $filtered[0]['id']);
		$this->assertEquals('3', $filtered[1]['id']);
	}

	public function testArrayFieldWithEmptyArray(): void
	{
		$object = ['id' => '1', 'tags' => []];

		$filterOptions = ['include' => 'tags:travel'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertFalse($result); // Empty array doesn't contain value
	}

	public function testCaseInsensitiveStringInclude(): void
	{
		$object = ['id' => '1', 'status' => 'Active'];

		$filterOptions = ['include' => 'status:active'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertTrue($result); // Case-insensitive match
	}

	public function testCaseInsensitiveStringExclude(): void
	{
		$object = ['id' => '1', 'status' => 'Draft'];

		$filterOptions = ['exclude' => 'status:draft'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertFalse($result); // Excluded (case-insensitive)
	}

	public function testCaseInsensitiveArrayFieldInclude(): void
	{
		$object = ['id' => '1', 'tags' => ['Travel', 'Adventure', 'Europe']];

		$filterOptions = ['include' => 'tags:travel'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertTrue($result); // Case-insensitive array search
	}

	public function testCaseInsensitiveArrayFieldExclude(): void
	{
		$object = ['id' => '1', 'tags' => ['Travel', 'Archived', 'Europe']];

		$filterOptions = ['exclude' => 'tags:archived'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertFalse($result); // Excluded (case-insensitive array search)
	}

	public function testCaseInsensitiveMixedCase(): void
	{
		$object = ['id' => '1', 'category' => 'Electronics'];

		$filterOptions = ['include' => 'category:ELECTRONICS'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertTrue($result); // Case-insensitive match
	}

	public function testBooleanStillStrictComparison(): void
	{
		$object = ['id' => '1', 'published' => true];

		// Boolean comparison should still be strict
		$filterOptions = ['include' => 'published:true'];

		$result = $this->filter->matchesFilter($object, $filterOptions);

		$this->assertTrue($result); // Boolean strict match
	}
}
