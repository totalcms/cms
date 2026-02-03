<?php

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

// ===== Sample Data =====

function sampleProducts(): array
{
	return [
		['id' => '001', 'name' => 'Widget', 'price' => 9.99, 'category' => 'tools', 'brand' => 'Acme', 'sku' => 'W-001'],
		['id' => '002', 'name' => 'Gadget', 'price' => 24.99, 'category' => 'electronics', 'brand' => 'Acme', 'sku' => 'G-002'],
		['id' => '003', 'name' => 'Doohickey', 'price' => 14.50, 'category' => 'tools', 'brand' => 'BetaCo', 'sku' => 'D-003'],
		['id' => '004', 'name' => 'Thingamajig', 'price' => 49.99, 'category' => 'electronics', 'brand' => 'BetaCo', 'sku' => 'T-004'],
		['id' => '005', 'name' => 'Gizmo', 'price' => 5.00, 'category' => 'accessories', 'brand' => 'Acme', 'sku' => 'G-005'],
	];
}

// ===== filterCollection =====

test('filterCollection filters items by equality', function (): void {
	$items = [
		['name' => 'Alice', 'status' => 'active'],
		['name' => 'Bob', 'status' => 'inactive'],
		['name' => 'Charlie', 'status' => 'active'],
	];
	$result = TotalCMSTwigFilters::filterCollection($items, [
		['property' => 'status', 'operator' => 'equal', 'value' => 'active'],
	]);
	expect($result)->toHaveCount(2);
	expect(array_column($result, 'name'))->toBe(['Alice', 'Charlie']);
});

test('filterCollection returns empty for null collection', function (): void {
	$result = TotalCMSTwigFilters::filterCollection(null, [
		['property' => 'status', 'operator' => 'equal', 'value' => 'active'],
	]);
	expect($result)->toBe([]);
});

test('filterCollection returns collection when rules are empty', function (): void {
	$items = [
		['name' => 'Alice'],
		['name' => 'Bob'],
	];
	$result = TotalCMSTwigFilters::filterCollection($items, []);
	expect($result)->toHaveCount(2);
});

test('filterCollection returns empty for empty collection', function (): void {
	$result = TotalCMSTwigFilters::filterCollection([], [
		['property' => 'status', 'operator' => 'equal', 'value' => 'active'],
	]);
	expect($result)->toBe([]);
});

test('filterCollection filters with contains operator', function (): void {
	$items = [
		['name' => 'Alice', 'tags' => ['php', 'javascript']],
		['name' => 'Bob', 'tags' => ['python', 'go']],
		['name' => 'Charlie', 'tags' => ['php', 'rust']],
	];
	$result = TotalCMSTwigFilters::filterCollection($items, [
		['property' => 'tags', 'operator' => 'contains', 'value' => 'php'],
	]);
	expect($result)->toHaveCount(2);
});

test('filterCollection filters with gt operator', function (): void {
	$items = [
		['name' => 'Cheap', 'price' => 5],
		['name' => 'Medium', 'price' => 25],
		['name' => 'Expensive', 'price' => 100],
	];
	$result = TotalCMSTwigFilters::filterCollection($items, [
		['property' => 'price', 'operator' => 'gt', 'value' => 20],
	]);
	expect($result)->toHaveCount(2);
});

// ===== sortCollection =====

test('sortCollection sorts items by property ascending', function (): void {
	$items = [
		['name' => 'Charlie', 'age' => 30],
		['name' => 'Alice', 'age' => 25],
		['name' => 'Bob', 'age' => 35],
	];
	$result = TotalCMSTwigFilters::sortCollection($items, [
		['property' => 'name'],
	]);
	$names = array_column($result, 'name');
	expect($names)->toBe(['Alice', 'Bob', 'Charlie']);
});

test('sortCollection sorts items by property descending', function (): void {
	$items = [
		['name' => 'Charlie', 'age' => 30],
		['name' => 'Alice', 'age' => 25],
		['name' => 'Bob', 'age' => 35],
	];
	$result = TotalCMSTwigFilters::sortCollection($items, [
		['property' => 'age', 'reverse' => true],
	]);
	$names = array_column($result, 'name');
	expect($names)->toBe(['Bob', 'Charlie', 'Alice']);
});

test('sortCollection returns empty for null collection', function (): void {
	$result = TotalCMSTwigFilters::sortCollection(null, [
		['property' => 'name'],
	]);
	expect($result)->toBe([]);
});

test('sortCollection returns collection when rules are empty', function (): void {
	$items = [
		['name' => 'Bob'],
		['name' => 'Alice'],
	];
	$result = TotalCMSTwigFilters::sortCollection($items, []);
	expect($result)->toHaveCount(2);
});

test('sortCollection returns empty for empty collection', function (): void {
	$result = TotalCMSTwigFilters::sortCollection([], [
		['property' => 'name'],
	]);
	expect($result)->toBe([]);
});

// ===== sum =====

test('sum calculates total of numeric property', function (): void {
	$total = TotalCMSTwigFilters::sum(sampleProducts(), 'price');
	expect($total)->toBe(104.47);
});

test('sum returns 0 for null collection', function (): void {
	expect(TotalCMSTwigFilters::sum(null, 'price'))->toBe(0.0);
});

test('sum returns 0 for empty collection', function (): void {
	expect(TotalCMSTwigFilters::sum([], 'price'))->toBe(0.0);
});

test('sum skips non-numeric values', function (): void {
	$items = [
		['val' => 10],
		['val' => 'not a number'],
		['val' => 20],
		['val' => null],
		['val' => 30],
	];
	expect(TotalCMSTwigFilters::sum($items, 'val'))->toBe(60.0);
});

test('sum skips items missing the property', function (): void {
	$items = [
		['price' => 10],
		['name'  => 'no price'],
		['price' => 20],
	];
	expect(TotalCMSTwigFilters::sum($items, 'price'))->toBe(30.0);
});

test('sum handles string numeric values', function (): void {
	$items = [
		['amt' => '100.50'],
		['amt' => '200.25'],
	];
	expect(TotalCMSTwigFilters::sum($items, 'amt'))->toBe(300.75);
});

test('sum skips non-array items', function (): void {
	$items = [
		['price' => 10],
		'not an array',
		['price' => 20],
	];
	expect(TotalCMSTwigFilters::sum($items, 'price'))->toBe(30.0);
});

// ===== avg =====

test('avg calculates average of numeric property', function (): void {
	$items = [
		['rating' => 4],
		['rating' => 5],
		['rating' => 3],
	];
	expect(TotalCMSTwigFilters::avg($items, 'rating'))->toBe(4.0);
});

test('avg returns 0 for null collection', function (): void {
	expect(TotalCMSTwigFilters::avg(null, 'rating'))->toBe(0.0);
});

test('avg returns 0 for empty collection', function (): void {
	expect(TotalCMSTwigFilters::avg([], 'rating'))->toBe(0.0);
});

test('avg skips non-numeric values and averages correctly', function (): void {
	$items = [
		['val' => 10],
		['val' => 'bad'],
		['val' => 20],
	];
	// Only 2 numeric values, average of 10 and 20 = 15
	expect(TotalCMSTwigFilters::avg($items, 'val'))->toBe(15.0);
});

test('avg skips items missing the property', function (): void {
	$items = [
		['score' => 80],
		['name'  => 'no score'],
		['score' => 100],
	];
	expect(TotalCMSTwigFilters::avg($items, 'score'))->toBe(90.0);
});

test('avg handles single item', function (): void {
	$items = [['val' => 42]];
	expect(TotalCMSTwigFilters::avg($items, 'val'))->toBe(42.0);
});

// ===== min =====

test('min finds minimum value', function (): void {
	expect(TotalCMSTwigFilters::min(sampleProducts(), 'price'))->toBe(5.0);
});

test('min returns null for null collection', function (): void {
	expect(TotalCMSTwigFilters::min(null, 'price'))->toBeNull();
});

test('min returns null for empty collection', function (): void {
	expect(TotalCMSTwigFilters::min([], 'price'))->toBeNull();
});

test('min returns null when no valid numeric values exist', function (): void {
	$items = [
		['val' => 'not a number'],
		['val'  => null],
		['name' => 'no val'],
	];
	expect(TotalCMSTwigFilters::min($items, 'val'))->toBeNull();
});

test('min skips non-numeric values', function (): void {
	$items = [
		['val' => 50],
		['val' => 'bad'],
		['val' => 10],
		['val' => 30],
	];
	expect(TotalCMSTwigFilters::min($items, 'val'))->toBe(10.0);
});

test('min handles negative values', function (): void {
	$items = [
		['val' => -5],
		['val' => 10],
		['val' => -20],
	];
	expect(TotalCMSTwigFilters::min($items, 'val'))->toBe(-20.0);
});

test('min handles single item', function (): void {
	$items = [['val' => 42]];
	expect(TotalCMSTwigFilters::min($items, 'val'))->toBe(42.0);
});

// ===== max =====

test('max finds maximum value', function (): void {
	expect(TotalCMSTwigFilters::max(sampleProducts(), 'price'))->toBe(49.99);
});

test('max returns null for null collection', function (): void {
	expect(TotalCMSTwigFilters::max(null, 'price'))->toBeNull();
});

test('max returns null for empty collection', function (): void {
	expect(TotalCMSTwigFilters::max([], 'price'))->toBeNull();
});

test('max returns null when no valid numeric values exist', function (): void {
	$items = [
		['val' => 'not a number'],
		['val' => null],
	];
	expect(TotalCMSTwigFilters::max($items, 'val'))->toBeNull();
});

test('max skips non-numeric values', function (): void {
	$items = [
		['val' => 50],
		['val' => 'bad'],
		['val' => 10],
		['val' => 100],
	];
	expect(TotalCMSTwigFilters::max($items, 'val'))->toBe(100.0);
});

test('max handles negative values', function (): void {
	$items = [
		['val' => -5],
		['val' => -10],
		['val' => -1],
	];
	expect(TotalCMSTwigFilters::max($items, 'val'))->toBe(-1.0);
});

// ===== pluck =====

test('pluck extracts property values', function (): void {
	$result = TotalCMSTwigFilters::pluck(sampleProducts(), 'name');
	expect($result)->toBe(['Widget', 'Gadget', 'Doohickey', 'Thingamajig', 'Gizmo']);
});

test('pluck returns empty array for null collection', function (): void {
	expect(TotalCMSTwigFilters::pluck(null, 'name'))->toBe([]);
});

test('pluck returns empty array for empty collection', function (): void {
	expect(TotalCMSTwigFilters::pluck([], 'name'))->toBe([]);
});

test('pluck skips items missing the property', function (): void {
	$items = [
		['name' => 'Alice', 'email' => 'alice@test.com'],
		['name' => 'Bob'],
		['name' => 'Charlie', 'email' => 'charlie@test.com'],
	];
	$result = TotalCMSTwigFilters::pluck($items, 'email');
	expect($result)->toBe(['alice@test.com', 'charlie@test.com']);
});

test('pluck includes null and empty values when property exists', function (): void {
	$items = [
		['val' => 'hello'],
		['val' => ''],
		['val' => null],
		['val' => 0],
	];
	$result = TotalCMSTwigFilters::pluck($items, 'val');
	expect($result)->toBe(['hello', '', null, 0]);
});

test('pluck extracts numeric values', function (): void {
	$result = TotalCMSTwigFilters::pluck(sampleProducts(), 'price');
	expect($result)->toBe([9.99, 24.99, 14.50, 49.99, 5.00]);
});

test('pluck skips non-array items', function (): void {
	$items = [
		['name' => 'Alice'],
		'not an array',
		['name' => 'Bob'],
	];
	$result = TotalCMSTwigFilters::pluck($items, 'name');
	expect($result)->toBe(['Alice', 'Bob']);
});

// ===== keyBy =====

test('keyBy creates lookup table by id (default)', function (): void {
	$result = TotalCMSTwigFilters::keyBy(sampleProducts());
	expect($result)->toHaveKeys(['001', '002', '003', '004', '005']);
	expect($result['003']['name'])->toBe('Doohickey');
});

test('keyBy creates lookup table by custom property', function (): void {
	$result = TotalCMSTwigFilters::keyBy(sampleProducts(), 'sku');
	expect($result)->toHaveKeys(['W-001', 'G-002', 'D-003', 'T-004', 'G-005']);
	expect($result['T-004']['name'])->toBe('Thingamajig');
});

test('keyBy returns empty array for null collection', function (): void {
	expect(TotalCMSTwigFilters::keyBy(null))->toBe([]);
});

test('keyBy returns empty array for empty collection', function (): void {
	expect(TotalCMSTwigFilters::keyBy([]))->toBe([]);
});

test('keyBy skips items with null or empty key values', function (): void {
	$items = [
		['id' => '001', 'name' => 'First'],
		['id'   => '', 'name' => 'Empty ID'],
		['id'   => null, 'name' => 'Null ID'],
		['name' => 'No ID'],
		['id'   => '002', 'name' => 'Second'],
	];
	$result = TotalCMSTwigFilters::keyBy($items);
	expect($result)->toHaveCount(2);
	expect($result)->toHaveKeys(['001', '002']);
});

test('keyBy last item wins for duplicate keys', function (): void {
	$items = [
		['id' => '001', 'name' => 'First'],
		['id' => '001', 'name' => 'Duplicate'],
	];
	$result = TotalCMSTwigFilters::keyBy($items);
	expect($result)->toHaveCount(1);
	expect($result['001']['name'])->toBe('Duplicate');
});

test('keyBy converts numeric keys to strings', function (): void {
	$items = [
		['id' => 42, 'name' => 'Numeric ID'],
	];
	$result = TotalCMSTwigFilters::keyBy($items);
	expect($result)->toHaveKey('42');
});

test('keyBy skips non-array items', function (): void {
	$items = [
		['id' => '001', 'name' => 'Valid'],
		'not an array',
		['id' => '002', 'name' => 'Also valid'],
	];
	$result = TotalCMSTwigFilters::keyBy($items);
	expect($result)->toHaveCount(2);
});

// ===== groupBy =====

test('groupBy groups items by property', function (): void {
	$result = TotalCMSTwigFilters::groupBy(sampleProducts(), 'category');
	expect($result)->toHaveKeys(['tools', 'electronics', 'accessories']);
	expect($result['tools'])->toHaveCount(2);
	expect($result['electronics'])->toHaveCount(2);
	expect($result['accessories'])->toHaveCount(1);
});

test('groupBy groups items by brand', function (): void {
	$result = TotalCMSTwigFilters::groupBy(sampleProducts(), 'brand');
	expect($result)->toHaveKeys(['Acme', 'BetaCo']);
	expect($result['Acme'])->toHaveCount(3);
	expect($result['BetaCo'])->toHaveCount(2);
});

test('groupBy returns empty array for null collection', function (): void {
	expect(TotalCMSTwigFilters::groupBy(null, 'category'))->toBe([]);
});

test('groupBy returns empty array for empty collection', function (): void {
	expect(TotalCMSTwigFilters::groupBy([], 'category'))->toBe([]);
});

test('groupBy puts items with missing property under _ungrouped', function (): void {
	$items = [
		['name' => 'Alice', 'role' => 'admin'],
		['name' => 'Bob'],
		['name' => 'Charlie', 'role' => 'user'],
		['name' => 'Diana', 'role' => ''],
	];
	$result = TotalCMSTwigFilters::groupBy($items, 'role');
	expect($result)->toHaveKeys(['admin', 'user', '_ungrouped']);
	expect($result['_ungrouped'])->toHaveCount(2); // Bob (missing) and Diana (empty)
});

test('groupBy preserves item data in groups', function (): void {
	$result    = TotalCMSTwigFilters::groupBy(sampleProducts(), 'category');
	$toolNames = array_column($result['tools'], 'name');
	expect($toolNames)->toBe(['Widget', 'Doohickey']);
});

test('groupBy skips non-array items', function (): void {
	$items = [
		['category' => 'a', 'name' => 'First'],
		'not an array',
		['category' => 'a', 'name' => 'Second'],
	];
	$result = TotalCMSTwigFilters::groupBy($items, 'category');
	expect($result['a'])->toHaveCount(2);
});

// ===== countBy =====

test('countBy counts items by property', function (): void {
	$result = TotalCMSTwigFilters::countBy(sampleProducts(), 'category');
	expect($result)->toBe([
		'tools'       => 2,
		'electronics' => 2,
		'accessories' => 1,
	]);
});

test('countBy counts items by brand', function (): void {
	$result = TotalCMSTwigFilters::countBy(sampleProducts(), 'brand');
	expect($result)->toBe([
		'Acme'   => 3,
		'BetaCo' => 2,
	]);
});

test('countBy returns empty array for null collection', function (): void {
	expect(TotalCMSTwigFilters::countBy(null, 'category'))->toBe([]);
});

test('countBy returns empty array for empty collection', function (): void {
	expect(TotalCMSTwigFilters::countBy([], 'category'))->toBe([]);
});

test('countBy puts items with missing property under _ungrouped', function (): void {
	$items = [
		['status' => 'active'],
		['status' => 'active'],
		['name'   => 'no status'],
		['status' => 'inactive'],
		['status' => ''],
	];
	$result = TotalCMSTwigFilters::countBy($items, 'status');
	expect($result)->toBe([
		'active'     => 2,
		'_ungrouped' => 2,
		'inactive'   => 1,
	]);
});

test('countBy skips non-array items', function (): void {
	$items = [
		['type' => 'a'],
		'not an array',
		['type' => 'a'],
		['type' => 'b'],
	];
	$result = TotalCMSTwigFilters::countBy($items, 'type');
	expect($result)->toBe(['a' => 2, 'b' => 1]);
});

// ===== manualSort =====

test('manualSort orders items by explicit value order', function (): void {
	$items = [
		['name' => 'Alice', 'role' => 'developer'],
		['name' => 'Bob', 'role' => 'ceo'],
		['name' => 'Charlie', 'role' => 'cfo'],
		['name' => 'Diana', 'role' => 'developer'],
	];

	$result = TotalCMSTwigFilters::manualSort($items, [
		'property' => 'role',
		'order'    => ['ceo', 'cfo', 'cmo'],
	]);

	$names = array_column($result, 'name');
	expect($names[0])->toBe('Bob');      // ceo first
	expect($names[1])->toBe('Charlie');  // cfo second
	// developers at end (remainder)
	expect(count($result))->toBe(4);
});

test('manualSort applies remainder sort to non-matching items', function (): void {
	$items = [
		['name' => 'Zoe', 'role' => 'developer'],
		['name' => 'Bob', 'role' => 'ceo'],
		['name' => 'Alice', 'role' => 'developer'],
	];

	$result = TotalCMSTwigFilters::manualSort($items, [
		'property'  => 'role',
		'order'     => ['ceo'],
		'remainder' => ['property' => 'name'],
	]);

	$names = array_column($result, 'name');
	expect($names)->toBe(['Bob', 'Alice', 'Zoe']);
});

test('manualSort sub-sorts items with same value by remainder', function (): void {
	$items = [
		['name' => 'Zoe', 'role' => 'vp'],
		['name' => 'Bob', 'role' => 'ceo'],
		['name' => 'Alice', 'role' => 'vp'],
	];

	$result = TotalCMSTwigFilters::manualSort($items, [
		'property'  => 'role',
		'order'     => ['ceo', 'vp'],
		'remainder' => ['property' => 'name'],
	]);

	$names = array_column($result, 'name');
	expect($names)->toBe(['Bob', 'Alice', 'Zoe']);
});

test('manualSort excludes remainder when excludeRemainder is true', function (): void {
	$items = [
		['name' => 'Alice', 'role' => 'developer'],
		['name' => 'Bob', 'role' => 'ceo'],
		['name' => 'Charlie', 'role' => 'cfo'],
	];

	$result = TotalCMSTwigFilters::manualSort($items, [
		'property'         => 'role',
		'order'            => ['ceo', 'cfo'],
		'excludeRemainder' => true,
	]);

	expect($result)->toHaveCount(2);
	$names = array_column($result, 'name');
	expect($names)->toBe(['Bob', 'Charlie']);
});

test('manualSort handles null collection', function (): void {
	$result = TotalCMSTwigFilters::manualSort(null, [
		'property' => 'role',
		'order'    => ['ceo'],
	]);
	expect($result)->toBe([]);
});

test('manualSort handles empty collection', function (): void {
	$result = TotalCMSTwigFilters::manualSort([], [
		'property' => 'role',
		'order'    => ['ceo'],
	]);
	expect($result)->toBe([]);
});

test('manualSort with no order applies only remainder sort', function (): void {
	$items = [
		['name' => 'Charlie'],
		['name' => 'Alice'],
		['name' => 'Bob'],
	];

	$result = TotalCMSTwigFilters::manualSort($items, [
		'property'  => 'role',
		'remainder' => ['property' => 'name'],
	]);

	$names = array_column($result, 'name');
	expect($names)->toBe(['Alice', 'Bob', 'Charlie']);
});

test('manualSort with empty order returns original collection', function (): void {
	$items = [
		['name' => 'Charlie'],
		['name' => 'Alice'],
	];

	$result = TotalCMSTwigFilters::manualSort($items, [
		'property' => 'role',
		'order'    => [],
	]);

	expect($result)->toHaveCount(2);
});

test('manualSort orders by id property', function (): void {
	$items = [
		['id' => 'charlie', 'name' => 'Charlie'],
		['id' => 'alice', 'name' => 'Alice'],
		['id' => 'bob', 'name' => 'Bob'],
		['id' => 'diana', 'name' => 'Diana'],
	];

	$result = TotalCMSTwigFilters::manualSort($items, [
		'property'         => 'id',
		'order'            => ['bob', 'alice'],
		'excludeRemainder' => true,
	]);

	expect($result)->toHaveCount(2);
	$names = array_column($result, 'name');
	expect($names)->toBe(['Bob', 'Alice']);
});

test('manualSort handles items with missing property value', function (): void {
	$items = [
		['name' => 'Alice', 'role' => 'developer'],
		['name' => 'Bob', 'role' => 'ceo'],
		['name' => 'Charlie'], // no role
	];

	$result = TotalCMSTwigFilters::manualSort($items, [
		'property'  => 'role',
		'order'     => ['ceo'],
		'remainder' => ['property' => 'name'],
	]);

	$names = array_column($result, 'name');
	expect($names[0])->toBe('Bob'); // ceo first
	// remainder sorted by name (Alice, Charlie)
	expect(in_array('Alice', $names))->toBeTrue();
	expect(in_array('Charlie', $names))->toBeTrue();
});
