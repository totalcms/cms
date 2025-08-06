<?php

namespace TotalCMS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Utilities\CollectionRefiner;

/**
 * Tests for CollectionRefiner AND logic functionality.
 */
class CollectionRefinerAndLogicTest extends TestCase
{
	private array $testCollection;

	protected function setUp(): void
	{
		$this->testCollection = [
			['id' => '1', 'tags' => ['php', 'web', 'framework'], 'category' => 'tutorial'],
			['id' => '2', 'tags' => ['php', 'database'], 'category' => 'tutorial'],
			['id' => '3', 'tags' => ['javascript', 'web', 'frontend'], 'category' => 'guide'],
			['id' => '4', 'tags' => ['php', 'web', 'framework', 'advanced'], 'category' => 'tutorial'],
			['id' => '5', 'tags' => ['python', 'web'], 'category' => 'tutorial'],
		];
	}

	public function testFilterByArrayRuleOrLogicDefault(): void
	{
		$refiner = new CollectionRefiner($this->testCollection);

		// Test OR logic (default behavior)
		$results = $refiner->filterByArrayRule(
			$this->testCollection,
			'tags',
			['php', 'javascript'],
			'contains'
		);

		// Should return items 1, 2, 3, 4 (contain php OR javascript)
		$this->assertCount(4, $results);
		$ids = array_column($results, 'id');
		sort($ids);
		$this->assertEquals(['1', '2', '3', '4'], $ids);
	}

	public function testFilterByArrayRuleOrLogicExplicit(): void
	{
		$refiner = new CollectionRefiner($this->testCollection);

		// Test explicit OR logic
		$results = $refiner->filterByArrayRule(
			$this->testCollection,
			'tags',
			['php', 'python'],
			'contains',
			'or'
		);

		// Should return items 1, 2, 4, 5 (contain php OR python)
		$this->assertCount(4, $results);
		$this->assertEquals(['1', '2', '4', '5'], array_column($results, 'id'));
	}

	public function testFilterByArrayRuleAndLogic(): void
	{
		$refiner = new CollectionRefiner($this->testCollection);

		// Test AND logic
		$results = $refiner->filterByArrayRule(
			$this->testCollection,
			'tags',
			['php', 'web'],
			'contains',
			'and'
		);

		// Should return items 1, 4 (contain php AND web)
		$this->assertCount(2, $results);
		$this->assertEquals(['1', '4'], array_column($results, 'id'));
	}

	public function testFilterByArrayRuleAndLogicStrictMatch(): void
	{
		$refiner = new CollectionRefiner($this->testCollection);

		// Test AND logic with three requirements
		$results = $refiner->filterByArrayRule(
			$this->testCollection,
			'tags',
			['php', 'web', 'framework'],
			'contains',
			'and'
		);

		// Should return items 1, 4 (contain php AND web AND framework)
		$this->assertCount(2, $results);
		$this->assertEquals(['1', '4'], array_column($results, 'id'));
	}

	public function testFilterByArrayRuleAndLogicNoMatches(): void
	{
		$refiner = new CollectionRefiner($this->testCollection);

		// Test AND logic with impossible combination
		$results = $refiner->filterByArrayRule(
			$this->testCollection,
			'tags',
			['php', 'javascript'], // No item has both
			'contains',
			'and'
		);

		// Should return no items
		$this->assertCount(0, $results);
	}

	public function testFilterWithAndLogicViaFilterMethod(): void
	{
		$refiner = new CollectionRefiner($this->testCollection);

		// Test AND logic via main filter method
		$rules = [
			[
				'property' => 'tags',
				'operator' => 'contains',
				'value'    => ['php', 'web'],
				'logic'    => 'and',
			],
		];

		$results = $refiner->filter($rules);

		// Should return items 1, 4 (contain php AND web)
		$this->assertCount(2, $results);
		$this->assertEquals(['1', '4'], array_column($results, 'id'));
	}

	public function testFilterWithOrLogicViaFilterMethod(): void
	{
		$refiner = new CollectionRefiner($this->testCollection);

		// Test OR logic via main filter method
		$rules = [
			[
				'property' => 'category',
				'operator' => 'equal',
				'value'    => ['tutorial', 'guide'],
				'logic'    => 'or',
			],
		];

		$results = $refiner->filter($rules);

		// Should return all items (all are either tutorial OR guide)
		$this->assertCount(5, $results);
	}

	public function testFilterWithDefaultLogicViaFilterMethod(): void
	{
		$refiner = new CollectionRefiner($this->testCollection);

		// Test default logic (should be OR) via main filter method
		$rules = [
			[
				'property' => 'tags',
				'operator' => 'contains',
				'value'    => ['python', 'javascript'],
				// No logic specified - should default to OR
			],
		];

		$results = $refiner->filter($rules);

		// Should return items 3, 5 (contain python OR javascript)
		$this->assertCount(2, $results);
		$ids = array_column($results, 'id');
		sort($ids);
		$this->assertEquals(['3', '5'], $ids);
	}

	public function testEmptyValuesArray(): void
	{
		$refiner = new CollectionRefiner($this->testCollection);

		// Test with empty values array
		$results = $refiner->filterByArrayRule(
			$this->testCollection,
			'tags',
			[],
			'contains',
			'and'
		);

		// Should return original collection when values are empty
		$this->assertCount(5, $results);
		$this->assertEquals($this->testCollection, $results);
	}

	public function testSingleValueAndLogic(): void
	{
		$refiner = new CollectionRefiner($this->testCollection);

		// Test AND logic with single value (should work same as OR)
		$results = $refiner->filterByArrayRule(
			$this->testCollection,
			'tags',
			['php'],
			'contains',
			'and'
		);

		// Should return items 1, 2, 4 (contain php)
		$this->assertCount(3, $results);
		$this->assertEquals(['1', '2', '4'], array_column($results, 'id'));
	}
}
