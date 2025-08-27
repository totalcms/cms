<?php

use TotalCMS\Domain\Property\Data\RatingData;

describe('RatingData', function (): void {
	test('RatingData → creates rating data instance with id', function (): void {
		$rating = new RatingData('rating-1');

		expect($rating)->toBeInstanceOf(RatingData::class);
		expect($rating->id)->toBe('rating-1');
	});

	test('RatingData → inherits from PropertyData', function (): void {
		$rating = new RatingData('test-id');

		expect($rating)->toBeInstanceOf(TotalCMS\Domain\Property\Data\PropertyData::class);
	});

	test('RatingData → has settings property from parent', function (): void {
		$rating = new RatingData('test-id', ['min' => 1, 'max' => 5]);

		expect($rating->settings)->toBe(['min' => 1, 'max' => 5]);
	});

	test('RatingData → creates with empty settings by default', function (): void {
		$rating = new RatingData('test-id');

		expect($rating->settings)->toBe([]);
	});

	test('RatingData → is a concrete class not abstract', function (): void {
		$reflection = new ReflectionClass(RatingData::class);

		expect($reflection->isAbstract())->toBe(false);
		expect($reflection->isInstantiable())->toBe(true);
	});

	test('RatingData → transform returns null (default behavior)', function (): void {
		$rating = new RatingData('test-id');

		expect($rating->transform())->toBeNull();
	});

	test('RatingData → toString returns id', function (): void {
		$rating = new RatingData('rating-123');

		expect((string)$rating)->toBe('rating-123');
	});
});
