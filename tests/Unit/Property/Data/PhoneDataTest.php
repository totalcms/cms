<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Data;

use TotalCMS\Domain\Property\Data\PhoneData;

describe('PhoneData', function (): void {
	test('creates phone data with phone number', function (): void {
		$phone = new PhoneData('555-123-4567');

		expect($phone->phone)->toBe('555-123-4567');
		expect($phone->settings)->toBe([]);
	});

	test('creates phone data with phone number and settings', function (): void {
		$settings = ['format' => 'international', 'required' => true];
		$phone    = new PhoneData('555-123-4567', $settings);

		expect($phone->phone)->toBe('555-123-4567');
		expect($phone->settings)->toBe($settings);
	});

	test('transform returns phone as string', function (): void {
		$phone = new PhoneData('555-123-4567');

		expect($phone->transform())->toBe('555-123-4567');
	});

	test('toString returns phone number', function (): void {
		$phone = new PhoneData('555-123-4567');

		expect((string)$phone)->toBe('555-123-4567');
	});

	test('handles empty phone number', function (): void {
		$phone = new PhoneData('');

		expect($phone->phone)->toBe('');
		expect($phone->transform())->toBe('');
		expect((string)$phone)->toBe('');
	});

	test('handles international phone format', function (): void {
		$phone = new PhoneData('+1-555-123-4567');

		expect($phone->phone)->toBe('+1-555-123-4567');
		expect($phone->transform())->toBe('+1-555-123-4567');
	});

	test('handles phone with extensions', function (): void {
		$phone = new PhoneData('555-123-4567 ext. 123');

		expect($phone->phone)->toBe('555-123-4567 ext. 123');
		expect($phone->transform())->toBe('555-123-4567 ext. 123');
	});

	test('handles numeric phone input', function (): void {
		$phone = new PhoneData('5551234567');

		expect($phone->phone)->toBe('5551234567');
		expect($phone->transform())->toBe('5551234567');
	});

	test('preserves original phone format', function (): void {
		$originalFormats = [
			'(555) 123-4567',
			'555.123.4567',
			'555 123 4567',
			'+1 (555) 123-4567',
		];

		foreach ($originalFormats as $format) {
			$phone = new PhoneData($format);
			expect($phone->phone)->toBe($format);
			expect($phone->transform())->toBe($format);
		}
	});

	test('works with different settings configurations', function (): void {
		$settingsConfigs = [
			[],
			['required'      => true],
			['format'        => 'us', 'validation' => 'strict'],
			['displayFormat' => 'international', 'required' => false, 'placeholder' => '(555) 123-4567'],
		];

		foreach ($settingsConfigs as $settings) {
			$phone = new PhoneData('555-123-4567', $settings);
			expect($phone->settings)->toBe($settings);
		}
	});
});
