<?php

use TotalCMS\Domain\Property\Data\NumberData;

describe('NumberData', function (): void {
	test('NumberData → creates number data with default zero', function (): void {
		$number = new NumberData();

		expect($number->number)->toBe(0.0);
		expect($number->settings)->toBe([]);
	});

	test('NumberData → creates number data with integer', function (): void {
		$number = new NumberData(42);

		expect($number->number)->toBe(42.0);
	});

	test('NumberData → creates number data with float', function (): void {
		$number = new NumberData(3.14);

		expect($number->number)->toBe(3.14);
	});

	test('NumberData → creates number data with string number', function (): void {
		$number = new NumberData('123.45');

		expect($number->number)->toBe(123.45);
	});

	test('NumberData → creates number data with negative number', function (): void {
		$number = new NumberData(-99.99);

		expect($number->number)->toBe(-99.99);
	});

	test('NumberData → creates number data with settings', function (): void {
		$settings = ['min' => 0, 'max' => 100, 'step' => 0.1];
		$number   = new NumberData(50.5, $settings);

		expect($number->number)->toBe(50.5);
		expect($number->settings)->toBe($settings);
	});

	test('NumberData → transforms to float correctly', function (): void {
		$number = new NumberData(7.89);

		expect($number->transform())->toBe(7.89);
		expect($number->transform())->toBeFloat();
	});

	test('NumberData → converts to string with __toString', function (): void {
		$number = new NumberData(12.34);

		expect((string)$number)->toBe('12.34');
	});

	test('NumberData → handles string conversion of integer values', function (): void {
		$number = new NumberData(100);

		expect((string)$number)->toBe('100');
	});

	test('NumberData → handles zero values correctly', function (): void {
		$number = new NumberData(0);

		expect($number->number)->toBe(0.0);
		expect((string)$number)->toBe('0');
		expect($number->transform())->toBe(0.0);
	});

	test('NumberData → converts non-numeric strings to zero', function (): void {
		$number = new NumberData('not-a-number');

		expect($number->number)->toBe(0.0);
	});

	test('NumberData → converts partial numeric strings correctly', function (): void {
		$number = new NumberData('123abc');

		expect($number->number)->toBe(123.0);
	});

	test('NumberData → handles very large numbers', function (): void {
		$largeNumber = 999999999.999999;
		$number      = new NumberData($largeNumber);

		expect($number->number)->toBe($largeNumber);
	});

	test('NumberData → handles very small decimal numbers', function (): void {
		$smallNumber = 0.000001;
		$number      = new NumberData($smallNumber);

		expect($number->number)->toBe($smallNumber);
	});

	test('NumberData → defaultValue returns original value when not null', function (): void {
		$result = NumberData::defaultValue(42.5, 100);

		expect($result)->toBe(42.5);
	});

	test('NumberData → defaultValue uses default when value is null', function (): void {
		$result = NumberData::defaultValue(null, 25.75);

		expect($result)->toBe(25.75);
	});

	test('NumberData → defaultValue returns null when both are null', function (): void {
		$result = NumberData::defaultValue(null, null);

		expect($result)->toBeNull();
	});

	test('NumberData → defaultValue converts default to float when used', function (): void {
		$result = NumberData::defaultValue(null, '15.5');

		expect($result)->toBe(15.5);
		expect($result)->toBeFloat();
	});

	test('NumberData → defaultValue ignores default when value is zero', function (): void {
		$result = NumberData::defaultValue(0, 99);

		expect($result)->toBe(0);
	});

	test('NumberData → defaultValue ignores default when value is false', function (): void {
		$result = NumberData::defaultValue(false, 99);

		expect($result)->toBe(false);
	});
});
