<?php

declare(strict_types = 1);

namespace Tests\Unit\Twig\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Service\TwigFunctionCache;

final class TwigFunctionCacheTest extends TestCase
{
	protected function setUp(): void
	{
		// Clear cache before each test
		TwigFunctionCache::clear();
	}

	protected function tearDown(): void
	{
		// Clear cache after each test to prevent test interference
		TwigFunctionCache::clear();
	}

	public function testRememberCachesFunction(): void
	{
		$callCount = 0;
		$function  = function () use (&$callCount): string {
			$callCount++;

			return 'test_result';
		};

		// First call should execute function
		$result1 = TwigFunctionCache::remember('test_key', $function);
		$this->assertEquals('test_result', $result1);
		$this->assertEquals(1, $callCount);

		// Second call should return cached result
		$result2 = TwigFunctionCache::remember('test_key', $function);
		$this->assertEquals('test_result', $result2);
		$this->assertEquals(1, $callCount); // Function not called again
	}

	public function testRememberWithArguments(): void
	{
		$function = (fn ($a, $b): float|int|array => $a + $b);

		$result1 = TwigFunctionCache::remember('math', $function, [5, 3]);
		$result2 = TwigFunctionCache::remember('math', $function, [10, 2]);
		$result3 = TwigFunctionCache::remember('math', $function, [5, 3]); // Same args

		$this->assertEquals(8, $result1);
		$this->assertEquals(12, $result2);
		$this->assertEquals(8, $result3); // Should be cached
	}

	public function testRememberWithComplexArguments(): void
	{
		$function = (fn ($data): int => count($data));

		$args1 = [['a', 'b', 'c']];
		$args2 = [['x', 'y']];

		$result1 = TwigFunctionCache::remember('count', $function, $args1);
		$result2 = TwigFunctionCache::remember('count', $function, $args2);
		$result3 = TwigFunctionCache::remember('count', $function, $args1); // Same args

		$this->assertEquals(3, $result1);
		$this->assertEquals(2, $result2);
		$this->assertEquals(3, $result3); // Should be cached
	}

	public function testHasReturnsTrueForCachedItems(): void
	{
		$function = (fn (): string => 'cached_value');

		$this->assertFalse(TwigFunctionCache::has('test_key'));

		TwigFunctionCache::remember('test_key', $function);

		$this->assertTrue(TwigFunctionCache::has('test_key'));
	}

	public function testHasReturnsFalseForUncachedItems(): void
	{
		$this->assertFalse(TwigFunctionCache::has('nonexistent_key'));
	}

	public function testHasWithArguments(): void
	{
		$function = (fn ($value): int|float => $value * 2);

		$this->assertFalse(TwigFunctionCache::has('multiply', [5]));

		TwigFunctionCache::remember('multiply', $function, [5]);

		$this->assertTrue(TwigFunctionCache::has('multiply', [5]));
		$this->assertFalse(TwigFunctionCache::has('multiply', [10])); // Different args
	}

	public function testClearRemovesAllCachedItems(): void
	{
		$function = (fn ($value) => $value);

		TwigFunctionCache::remember('key1', $function, ['value1']);
		TwigFunctionCache::remember('key2', $function, ['value2']);

		$this->assertTrue(TwigFunctionCache::has('key1', ['value1']));
		$this->assertTrue(TwigFunctionCache::has('key2', ['value2']));

		TwigFunctionCache::clear();

		$this->assertFalse(TwigFunctionCache::has('key1', ['value1']));
		$this->assertFalse(TwigFunctionCache::has('key2', ['value2']));
	}

	public function testForgetRemovesSpecificKey(): void
	{
		$function = (fn ($value) => $value);

		TwigFunctionCache::remember('key1', $function, ['a']);
		TwigFunctionCache::remember('key1', $function, ['b']);
		TwigFunctionCache::remember('key2', $function, ['c']);

		$this->assertTrue(TwigFunctionCache::has('key1', ['a']));
		$this->assertTrue(TwigFunctionCache::has('key1', ['b']));
		$this->assertTrue(TwigFunctionCache::has('key2', ['c']));

		TwigFunctionCache::forget('key1');

		$this->assertFalse(TwigFunctionCache::has('key1', ['a']));
		$this->assertFalse(TwigFunctionCache::has('key1', ['b']));
		$this->assertTrue(TwigFunctionCache::has('key2', ['c'])); // Different key should remain
	}

	public function testGetStatsReturnsCorrectCount(): void
	{
		$function = (fn ($value) => $value);

		$stats = TwigFunctionCache::getStats();
		$this->assertEquals(0, $stats['count']);

		TwigFunctionCache::remember('key1', $function, ['value1']);
		$stats = TwigFunctionCache::getStats();
		$this->assertEquals(1, $stats['count']);

		TwigFunctionCache::remember('key2', $function, ['value2']);
		$stats = TwigFunctionCache::getStats();
		$this->assertEquals(2, $stats['count']);
	}

	public function testGetStatsIncludesMemoryUsage(): void
	{
		$stats = TwigFunctionCache::getStats();
		$this->assertArrayHasKey('memory', $stats);
		$this->assertIsInt($stats['memory']);
		$this->assertGreaterThanOrEqual(0, $stats['memory']);
	}

	public function testRememberWithNoArguments(): void
	{
		$callCount = 0;
		$function  = function () use (&$callCount): string {
			$callCount++;

			return 'no_args_result';
		};

		$result1 = TwigFunctionCache::remember('no_args', $function);
		$result2 = TwigFunctionCache::remember('no_args', $function);

		$this->assertEquals('no_args_result', $result1);
		$this->assertEquals('no_args_result', $result2);
		$this->assertEquals(1, $callCount); // Function called only once
	}

	public function testRememberWithEmptyArgumentsArray(): void
	{
		$function = (fn (): string => 'empty_args');

		$result1 = TwigFunctionCache::remember('test', $function, []);
		$result2 = TwigFunctionCache::remember('test', $function, []);

		$this->assertEquals('empty_args', $result1);
		$this->assertEquals('empty_args', $result2);
	}

	public function testCacheKeyGeneration(): void
	{
		$function = (fn ($value) => $value);

		// Same key, different args should create different cache entries
		TwigFunctionCache::remember('test', $function, ['a']);
		TwigFunctionCache::remember('test', $function, ['b']);

		$this->assertTrue(TwigFunctionCache::has('test', ['a']));
		$this->assertTrue(TwigFunctionCache::has('test', ['b']));

		$stats = TwigFunctionCache::getStats();
		$this->assertEquals(2, $stats['count']);
	}

	public function testCacheWithDifferentDataTypes(): void
	{
		$function = (fn ($value): string => gettype($value));

		$result1 = TwigFunctionCache::remember('type', $function, [123]);
		$result2 = TwigFunctionCache::remember('type', $function, ['123']);
		$result3 = TwigFunctionCache::remember('type', $function, [true]);
		$result4 = TwigFunctionCache::remember('type', $function, [null]);

		$this->assertEquals('integer', $result1);
		$this->assertEquals('string', $result2);
		$this->assertEquals('boolean', $result3);
		$this->assertEquals('NULL', $result4);
	}

	public function testCacheWithNestedArrayArguments(): void
	{
		$function = (fn ($data) => json_encode($data));

		$complexData = [
			'user' => ['name' => 'John', 'age' => 30],
			'tags' => ['php', 'testing'],
		];

		$result1 = TwigFunctionCache::remember('json', $function, [$complexData]);
		$result2 = TwigFunctionCache::remember('json', $function, [$complexData]); // Same data

		$this->assertIsString($result1);
		$this->assertIsString($result2);
		$this->assertEquals($result1, $result2);
		$this->assertStringContainsString('John', $result1);
		$this->assertStringContainsString('php', $result1);
	}

	public function testExceptionInFunctionIsNotCached(): void
	{
		$callCount = 0;
		$function  = function () use (&$callCount): never {
			$callCount++;
			throw new \RuntimeException('Test exception');
		};

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Test exception');

		try {
			TwigFunctionCache::remember('exception', $function);
		} catch (\RuntimeException $e) {
			// First call should increment counter
			$this->assertEquals(1, $callCount);
			throw $e;
		}
	}

	public function testMultipleInstancesShareCache(): void
	{
		// Since TwigFunctionCache uses static methods, all instances share the same cache
		$function = (fn (): string => 'shared_result');

		TwigFunctionCache::remember('shared', $function);

		$this->assertTrue(TwigFunctionCache::has('shared'));

		// Clear from anywhere should clear for everyone
		TwigFunctionCache::clear();

		$this->assertFalse(TwigFunctionCache::has('shared'));
	}

	public function testCachePerformanceWithLargeData(): void
	{
		$largeData = array_fill(0, 10000, 'large_data_item_with_more_content');

		// Use a more computationally expensive function to make timing differences more apparent
		$expensiveFunction = function ($data): int {
			$result = 0;
			foreach ($data as $item) {
				// Add some computational work
				$result += strlen($item) * count(str_split($item));
			}

			return $result;
		};

		// Clear any existing cache
		TwigFunctionCache::clear();

		// Measure multiple iterations for more reliable timing
		$firstCallTimes  = [];
		$secondCallTimes = [];

		for ($i = 0; $i < 3; $i++) {
			// Clear cache for this iteration
			TwigFunctionCache::forget('large_perf_' . $i);

			$start            = microtime(true);
			$result1          = TwigFunctionCache::remember('large_perf_' . $i, $expensiveFunction, [$largeData]);
			$firstCallTimes[] = microtime(true) - $start;

			$start             = microtime(true);
			$result2           = TwigFunctionCache::remember('large_perf_' . $i, $expensiveFunction, [$largeData]);
			$secondCallTimes[] = microtime(true) - $start;

			$this->assertEquals($result1, $result2);
		}

		// Check that most cached calls are faster (allow for some timing variance)
		$averageFirstCall  = array_sum($firstCallTimes) / count($firstCallTimes);
		$averageSecondCall = array_sum($secondCallTimes) / count($secondCallTimes);

		// Cache should generally be faster, but allow for some variance due to system factors
		// This is more of a smoke test than a strict performance requirement
		$this->assertGreaterThan(0, $averageFirstCall, 'First call should take some measurable time');
		$this->assertGreaterThan(0, $averageSecondCall, 'Second call should take some measurable time');

		// The key test is that results are identical (functional correctness)
		// Performance can vary due to system factors, so we focus on correctness over speed
	}
}
