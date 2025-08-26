<?php

namespace Tests\Unit\Property\Data;

use TotalCMS\Domain\Property\Data\TimeData;

describe('TimeData', function (): void {
	test('creates time data with valid time', function (): void {
		$time = new TimeData('14:30');

		expect($time->time)->toBe('14:30');
		expect($time->settings)->toBe([]);
	});

	test('creates time data with time and settings', function (): void {
		$settings = ['format' => '24h', 'required' => true];
		$time     = new TimeData('09:15', $settings);

		expect($time->time)->toBe('09:15');
		expect($time->settings)->toBe($settings);
	});

	test('creates time data with empty time', function (): void {
		$time = new TimeData('');

		expect($time->time)->toBe('');
		expect($time->settings)->toBe([]);
	});

	test('creates time data with default parameters', function (): void {
		$time = new TimeData();

		expect($time->time)->toBe('');
		expect($time->settings)->toBe([]);
	});

	test('validates valid time formats', function (): void {
		$validTimes = [
			'00:00',
			'12:00',
			'23:59',
			'09:30',
			'15:45',
		];

		foreach ($validTimes as $validTime) {
			$time = new TimeData($validTime);
			expect($time->time)->toBe($validTime);
		}
	});

	test('throws exception for invalid time format', function (): void {
		expect(fn (): \TotalCMS\Domain\Property\Data\TimeData => new TimeData('25:00'))
			->toThrow(\InvalidArgumentException::class, 'Invalid Time');
	});

	test('throws exception for invalid time values', function (): void {
		$invalidTimes = [
			'24:00',
			'12:60',
			'invalid',
			'12:30:45',
			'12',
			'ab:cd',
		];

		foreach ($invalidTimes as $invalidTime) {
			expect(fn (): \TotalCMS\Domain\Property\Data\TimeData => new TimeData($invalidTime))
				->toThrow(\InvalidArgumentException::class, 'Invalid Time');
		}
	});

	test('transform returns time as string', function (): void {
		$time = new TimeData('16:45');

		expect($time->transform())->toBe('16:45');
	});

	test('toString returns time', function (): void {
		$time = new TimeData('08:30');

		expect((string)$time)->toBe('08:30');
	});

	test('transform handles empty time', function (): void {
		$time = new TimeData('');

		expect($time->transform())->toBe('');
		expect((string)$time)->toBe('');
	});

	test('verifies time validation logic', function (): void {
		// Test the internal verifyTime method through constructor behavior

		// Valid times should not throw
		expect(fn (): \TotalCMS\Domain\Property\Data\TimeData => new TimeData('00:00'))->not->toThrow(\InvalidArgumentException::class);
		expect(fn (): \TotalCMS\Domain\Property\Data\TimeData => new TimeData('12:30'))->not->toThrow(\InvalidArgumentException::class);
		expect(fn (): \TotalCMS\Domain\Property\Data\TimeData => new TimeData('23:59'))->not->toThrow(\InvalidArgumentException::class);

		// Invalid times should throw
		expect(fn (): \TotalCMS\Domain\Property\Data\TimeData => new TimeData('24:00'))->toThrow(\InvalidArgumentException::class);
		expect(fn (): \TotalCMS\Domain\Property\Data\TimeData => new TimeData('12:60'))->toThrow(\InvalidArgumentException::class);
		expect(fn (): \TotalCMS\Domain\Property\Data\TimeData => new TimeData('invalid-time'))->toThrow(\InvalidArgumentException::class);
	});

	test('handles edge cases for time validation', function (): void {
		// Test edge cases that might pass strtotime but aren't valid HH:MM format
		$edgeCases = [
			'12:0',    // Single digit minute
			'1:30',    // Single digit hour
			'12:30:00', // With seconds
			'12pm',    // AM/PM format
			'noon',    // Text time
		];

		foreach ($edgeCases as $edgeCase) {
			expect(fn (): \TotalCMS\Domain\Property\Data\TimeData => new TimeData($edgeCase))
				->toThrow(\InvalidArgumentException::class, 'Invalid Time');
		}
	});

	test('preserves settings across operations', function (): void {
		$settings = ['format' => '12h', 'step' => 15, 'min' => '09:00', 'max' => '17:00'];
		$time     = new TimeData('14:30', $settings);

		expect($time->settings)->toBe($settings);

		// Settings should persist through transformations
		$time->transform();
		expect($time->settings)->toBe($settings);
	});
});
