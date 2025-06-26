<?php

use TotalCMS\Domain\Property\Data\DateData;

test('it handles relative date strings', function () {
	// Test relative strings that Chronos supports
	$testCases = [
		'now' => true,
		'today' => true,
		'tomorrow' => true,
		'yesterday' => true,
		'+1 day' => true,
		'-1 day' => true,
		'+1 week' => true,
		'-1 week' => true,
		'+1 month' => true,
		'-1 month' => true,
		'+1 year' => true,
		'-1 year' => true,
		'next monday' => true,
		'last friday' => true,
		'first day of this month' => true,
		'last day of this month' => true,
		'first day of next month' => true,
	];

	foreach ($testCases as $dateString => $shouldParse) {
		$result = DateData::cleanDate($dateString);
		
		if ($shouldParse) {
			expect($result)->not->toBeEmpty("Failed to parse: {$dateString}");
			expect($result)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', "Invalid format for: {$dateString}");
		}
	}
});

test('it handles standard date formats', function () {
	$testCases = [
		'2024-01-15' => true,
		'2024/01/15' => true,
		'15-01-2024' => true,
		'2024-01-15 14:30:00' => true,
		'2024-01-15T14:30:00' => true,
		'2024-01-15T14:30:00Z' => true,
	];

	foreach ($testCases as $dateString => $shouldParse) {
		$result = DateData::cleanDate($dateString);
		
		if ($shouldParse) {
			expect($result)->not->toBeEmpty("Failed to parse: {$dateString}");
			expect($result)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', "Invalid format for: {$dateString}");
		}
	}
});

test('it handles numeric timestamps', function () {
	$timestamp = time();
	$result = DateData::cleanDate((string)$timestamp);
	
	expect($result)->not->toBeEmpty();
	expect($result)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

test('it handles invalid dates gracefully', function () {
	$invalidDates = [
		'not a date',
		'2024-13-40', // Invalid month/day
		'random text',
	];

	foreach ($invalidDates as $invalidDate) {
		$result = DateData::cleanDate($invalidDate);
		// Should return empty string for invalid dates
		expect($result)->toBe('', "Should return empty string for invalid date: {$invalidDate}");
	}
	
	// Empty string should return empty string (explicit no date)
	$result = DateData::cleanDate('');
	expect($result)->toBe('');
});

test('it returns empty string for null', function () {
	$result = DateData::cleanDate(null);
	expect($result)->toBe('');
});

test('it returns empty string for all empty values', function () {
	// PHP's empty() returns true for: "", 0, 0.0, "0", NULL, FALSE, array()
	expect(DateData::cleanDate(''))->toBe('');
	expect(DateData::cleanDate(null))->toBe('');
	expect(DateData::cleanDate('0'))->toBe(''); // empty() considers '0' as empty
});

test('datedata constructor with smart defaults', function () {
	// Test with relative date string
	$dateData = new DateData('tomorrow');
	expect($dateData->date)->not->toBeEmpty();
	expect($dateData->date)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');

	// Test with standard date
	$dateData2 = new DateData('2024-06-15');
	expect($dateData2->date)->not->toBeEmpty();
	expect($dateData2->date)->toContain('2024-06-15');

	// Test with empty string (should preserve empty string - no date)
	$dateData3 = new DateData('');
	expect($dateData3->date)->toBe('');
});

test('default value method', function () {
	$result = DateData::defaultValue('tomorrow', null);
	expect($result)->not->toBeEmpty();
	expect($result)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');

	$result2 = DateData::defaultValue('invalid date', null);
	expect($result2)->toBe('');
});

test('transform and tostring methods', function () {
	$dateData = new DateData('2024-06-15');
	
	expect($dateData->transform())->toBe($dateData->date);
	expect((string)$dateData)->toBe($dateData->date);
	expect($dateData->transform())->toBe((string)$dateData);
});