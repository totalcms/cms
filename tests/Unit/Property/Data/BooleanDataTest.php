<?php

namespace Tests\Unit\Property\Data;

use TotalCMS\Domain\Property\Data\BooleanData;

describe('BooleanData', function (): void {
	test('creates boolean data with boolean true', function (): void {
		$boolean = new BooleanData(true);

		expect($boolean->status)->toBe(true);
		expect($boolean->settings)->toBe([]);
	});

	test('creates boolean data with boolean false', function (): void {
		$boolean = new BooleanData(false);

		expect($boolean->status)->toBe(false);
		expect($boolean->settings)->toBe([]);
	});

	test('creates boolean data with default false', function (): void {
		$boolean = new BooleanData();

		expect($boolean->status)->toBe(false);
		expect($boolean->settings)->toBe([]);
	});

	test('creates boolean data with settings', function (): void {
		$settings = ['required' => true, 'label' => 'Enable feature'];
		$boolean  = new BooleanData(true, $settings);

		expect($boolean->status)->toBe(true);
		expect($boolean->settings)->toBe($settings);
	});

	test('converts string "true" to boolean true', function (): void {
		$boolean = new BooleanData('true');

		expect($boolean->status)->toBe(true);
	});

	test('converts string "True" (title case) to boolean true', function (): void {
		$boolean = new BooleanData('True');

		expect($boolean->status)->toBe(true);
	});

	test('converts string "TRUE" (uppercase) to boolean true', function (): void {
		$boolean = new BooleanData('TRUE');

		expect($boolean->status)->toBe(true);
	});

	test('converts string "1" to boolean true', function (): void {
		$boolean = new BooleanData('1');

		expect($boolean->status)->toBe(true);
	});

	test('converts string "false" to boolean false', function (): void {
		$boolean = new BooleanData('false');

		expect($boolean->status)->toBe(false);
	});

	test('converts string "0" to boolean false', function (): void {
		$boolean = new BooleanData('0');

		expect($boolean->status)->toBe(false);
	});

	test('converts empty string to boolean false', function (): void {
		$boolean = new BooleanData('');

		expect($boolean->status)->toBe(false);
	});

	test('converts arbitrary string to boolean false', function (): void {
		$boolean = new BooleanData('arbitrary-string');

		expect($boolean->status)->toBe(false);
	});

	test('converts integer 1 to boolean true', function (): void {
		$boolean = new BooleanData(1);

		expect($boolean->status)->toBe(true);
	});

	test('converts integer 0 to boolean false', function (): void {
		$boolean = new BooleanData(0);

		expect($boolean->status)->toBe(false);
	});

	test('converts positive integer to boolean false', function (): void {
		$boolean = new BooleanData(5);

		expect($boolean->status)->toBe(false);
	});

	test('converts negative integer to boolean false', function (): void {
		$boolean = new BooleanData(-1);

		expect($boolean->status)->toBe(false);
	});

	test('transform returns boolean value', function (): void {
		$trueBoolean  = new BooleanData(true);
		$falseBoolean = new BooleanData(false);

		expect($trueBoolean->transform())->toBe(true);
		expect($falseBoolean->transform())->toBe(false);
	});

	test('toString returns string representation', function (): void {
		$trueBoolean  = new BooleanData(true);
		$falseBoolean = new BooleanData(false);

		expect((string)$trueBoolean)->toBe('true');
		expect((string)$falseBoolean)->toBe('false');
	});

	test('defaultValue returns default when value is null', function (): void {
		$result = BooleanData::defaultValue(null, true);

		expect($result)->toBe(true);
	});

	test('defaultValue returns default when value is null and default is false', function (): void {
		$result = BooleanData::defaultValue(null, false);

		expect($result)->toBe(false);
	});

	test('defaultValue returns original value when not null', function (): void {
		$result = BooleanData::defaultValue(true, false);

		expect($result)->toBe(true);
	});

	test('defaultValue returns original value when not null even if false', function (): void {
		$result = BooleanData::defaultValue(false, true);

		expect($result)->toBe(false);
	});

	test('defaultValue returns value when default is not set', function (): void {
		$result = BooleanData::defaultValue(true, null);

		expect($result)->toBe(true);
	});

	test('defaultValue handles various default types', function (): void {
		// String defaults (boolval behavior)
		expect(BooleanData::defaultValue(null, 'true'))->toBe(true);
		expect(BooleanData::defaultValue(null, 'false'))->toBe(true); // Non-empty string = true
		expect(BooleanData::defaultValue(null, '1'))->toBe(true);
		expect(BooleanData::defaultValue(null, '0'))->toBe(false); // Special case: '0' = false
		expect(BooleanData::defaultValue(null, ''))->toBe(false); // Empty string = false

		// Integer defaults
		expect(BooleanData::defaultValue(null, 1))->toBe(true);
		expect(BooleanData::defaultValue(null, 0))->toBe(false);
	});

	test('handles complex settings configurations', function (): void {
		$complexSettings = [
			'label'       => 'Enable notifications',
			'description' => 'Receive email notifications',
			'required'    => false,
			'default'     => true,
			'validation'  => ['required' => false],
		];

		$boolean = new BooleanData(true, $complexSettings);

		expect($boolean->status)->toBe(true);
		expect($boolean->settings)->toBe($complexSettings);
	});

	test('maintains type consistency', function (): void {
		$inputs = [
			[true, true],
			[false, false],
			['true', true],
			['false', false],
			['1', true],
			['0', false],
			[1, true],
			[0, false],
			['', false],
			['anything', false],
			[2, false],
			[-1, false],
		];

		foreach ($inputs as [$input, $expected]) {
			$boolean = new BooleanData($input);
			expect($boolean->status)->toBe($expected);
			expect($boolean->transform())->toBe($expected);
		}
	});
});
