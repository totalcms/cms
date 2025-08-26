<?php

use TotalCMS\Domain\Property\Data\ListData;

describe('ListData', function (): void {
	test('ListData → creates with empty array', function (): void {
		$list = new ListData();
		
		expect($list->list)->toBe([]);
		expect($list->settings)->toBe([]);
	});

	test('ListData → creates with simple string array', function (): void {
		$items = ['apple', 'banana', 'cherry'];
		$list = new ListData($items);
		
		expect($list->list)->toBe($items);
	});

	test('ListData → creates with settings', function (): void {
		$settings = ['multiple' => true, 'separator' => ','];
		$list = new ListData(['item1', 'item2'], $settings);
		
		expect($list->settings)->toBe($settings);
	});

	test('ListData → filters out empty values', function (): void {
		$items = ['valid', '', 'also_valid', null, 'another'];
		$list = new ListData($items);
		
		expect($list->list)->toBe(['valid', 'also_valid', 'another']);
	});

	test('ListData → removes duplicate values', function (): void {
		$items = ['apple', 'banana', 'apple', 'cherry', 'banana'];
		$list = new ListData($items);
		
		expect($list->list)->toBe(['apple', 'banana', 'cherry']);
	});

	test('ListData → reindexes array values', function (): void {
		$items = ['first', 'second', 'third'];
		$list = new ListData($items);
		
		// Should have sequential numeric keys starting from 0
		expect(array_keys($list->list))->toBe([0, 1, 2]);
	});

	test('ListData → converts all values to strings', function (): void {
		$items = [123, 45.67, true, 'string'];
		$list = new ListData($items);
		
		expect($list->list)->toBe(['123', '45.67', '1', 'string']);
		
		// Verify they are all strings
		foreach ($list->list as $item) {
			expect($item)->toBeString();
		}
	});

	test('ListData → transforms to array correctly', function (): void {
		$items = ['tag1', 'tag2', 'tag3'];
		$list = new ListData($items);
		
		expect($list->transform())->toBe($items);
		expect($list->transform())->toBeArray();
	});

	test('ListData → converts to string with comma separation', function (): void {
		$items = ['red', 'green', 'blue'];
		$list = new ListData($items);
		
		expect((string)$list)->toBe('red,green,blue');
	});

	test('ListData → handles single item', function (): void {
		$list = new ListData(['single']);
		
		expect($list->list)->toBe(['single']);
		expect((string)$list)->toBe('single');
	});

	test('ListData → handles empty list string conversion', function (): void {
		$list = new ListData([]);
		
		expect((string)$list)->toBe('');
	});

	test('ListData → handles associative arrays by converting to list', function (): void {
		// Associative arrays get converted: array_filter + array_unique + array_values
		$list = new ListData(['key' => 'value', 'other' => 'item']);
		
		// After processing: array_values will reindex to [0, 1]
		expect($list->list)->toBe(['value', 'item']);
	});

	test('ListData → handles numeric values', function (): void {
		$items = [1, 2, 3, 4, 5];
		$list = new ListData($items);
		
		expect($list->list)->toBe(['1', '2', '3', '4', '5']);
	});

	test('ListData → handles boolean values conversion', function (): void {
		$items = [true, false];
		$list = new ListData($items);
		
		expect($list->list)->toBe(['1']); // false filters out as empty, true becomes '1'
	});

	test('ListData → handles mixed scalar types', function (): void {
		$items = ['text', 42, 3.14, true];
		$list = new ListData($items);
		
		expect($list->list)->toBe(['text', '42', '3.14', '1']);
	});

	test('ListData → preserves order after deduplication', function (): void {
		$items = ['first', 'second', 'first', 'third', 'second'];
		$list = new ListData($items);
		
		// Should preserve first occurrence order
		expect($list->list)->toBe(['first', 'second', 'third']);
	});

	test('ListData → preserves whitespace-only strings', function (): void {
		$items = ['valid', '   ', 'also_valid', "\t\n", 'another'];
		$list = new ListData($items);
		
		// array_filter with no callback keeps truthy values, whitespace strings are truthy
		expect($list->list)->toBe(['valid', '   ', 'also_valid', "\t\n", 'another']);
	});

	test('ListData → filters out zero values', function (): void {
		$items = [0, '0', 'zero'];
		$list = new ListData($items);
		
		// array_filter() removes falsy values: 0 is falsy, '0' is falsy, 'zero' is truthy
		expect($list->list)->toBe(['zero']);
	});

	test('ListData → transform returns same as list property', function (): void {
		$items = ['item1', 'item2', 'item3'];
		$list = new ListData($items);
		
		expect($list->transform())->toBe($list->list);
	});

	test('ListData → handles very large lists', function (): void {
		$items = array_map(fn($i) => "item_$i", range(1, 1000));
		$list = new ListData($items);
		
		expect(count($list->list))->toBe(1000);
		expect($list->list[0])->toBe('item_1');
		expect($list->list[999])->toBe('item_1000');
	});

	test('ListData → string conversion works with special characters', function (): void {
		$items = ['item,with,comma', 'item with spaces', 'item-with-dashes'];
		$list = new ListData($items);
		
		expect((string)$list)->toBe('item,with,comma,item with spaces,item-with-dashes');
	});
});