<?php

use TotalCMS\Domain\Admin\FormField\FormField;

/**
 * propertyOptions results are sorted by label by default. The shared sorter
 * must handle both option shapes (plain strings + {value,label} dicts),
 * sort case-insensitively in natural order, and leave grouped optgroup
 * structures (string keys) untouched.
 */
describe('FormField::sortOptionsByLabel', function (): void {
	$sort = function (array $options): array {
		$method = new ReflectionMethod(FormField::class, 'sortOptionsByLabel');

		return $method->invoke(null, $options);
	};

	test('sorts a plain string list case-insensitively', function () use ($sort): void {
		expect($sort(['Banana', 'apple', 'Cherry', 'date']))
			->toBe(['apple', 'Banana', 'Cherry', 'date']);
	});

	test('sorts {value,label} dicts by their label', function () use ($sort): void {
		$sorted = $sort([
			['value' => 'b', 'label' => 'Beta'],
			['value' => 'a', 'label' => 'alpha'],
			['value' => 'g', 'label' => 'Gamma'],
		]);

		expect(array_column($sorted, 'label'))->toBe(['alpha', 'Beta', 'Gamma']);
	});

	test('uses natural order (Item 2 before Item 10)', function () use ($sort): void {
		expect($sort(['Item 10', 'Item 2', 'Item 1']))
			->toBe(['Item 1', 'Item 2', 'Item 10']);
	});

	test('leaves grouped optgroup structures untouched', function () use ($sort): void {
		$grouped = [
			'Group B' => [['value' => 'y', 'label' => 'Y']],
			'Group A' => [['value' => 'x', 'label' => 'X']],
		];

		expect($sort($grouped))->toBe($grouped);
	});
});
