<?php

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

test('date relative filter', function () {
	// Test with a known date to get predictable relative results
	$result = TotalCMSTwigFilters::dateRelative('2024-01-01');
	expect($result)->toBeString();
	expect($result)->not->toBeEmpty();
});

test('date format filter', function () {
	$date = '2024-06-15 14:30:00';

	// Test default format
	$result = TotalCMSTwigFilters::dateFormat($date);
	expect($result)->toContain('2024-06-15');

	// Test custom format
	$result2 = TotalCMSTwigFilters::dateFormat($date, 'Y-m-d');
	expect($result2)->toBe('2024-06-15');

	// Test another custom format
	$result3 = TotalCMSTwigFilters::dateFormat($date, 'F j, Y');
	expect($result3)->toBe('June 15, 2024');
});

test('date add filter', function () {
	$date = '2024-06-15';

	$result = TotalCMSTwigFilters::dateAdd($date, '+1 day');
	expect($result)->toContain('2024-06-16');

	$result2 = TotalCMSTwigFilters::dateAdd($date, '+1 month');
	expect($result2)->toContain('2024-07-15');

	$result3 = TotalCMSTwigFilters::dateAdd($date, '+1 year');
	expect($result3)->toContain('2025-06-15');
});

test('date subtract filter', function () {
	$date = '2024-06-15';

	$result = TotalCMSTwigFilters::dateSubtract($date, '1 day');
	expect($result)->toContain('2024-06-14');

	$result2 = TotalCMSTwigFilters::dateSubtract($date, '-1 day'); // Should handle negative sign
	expect($result2)->toContain('2024-06-14');

	$result3 = TotalCMSTwigFilters::dateSubtract($date, '1 month');
	expect($result3)->toContain('2024-05-15');
});

test('date diff filter', function () {
	$date1 = '2024-06-15';
	$date2 = '2024-06-16';

	$result = TotalCMSTwigFilters::dateDiff($date1, $date2);
	expect($result)->toBeString();
	expect($result)->not->toBeEmpty();
});

test('date start of filter', function () {
	$date = '2024-06-15 14:30:45';

	// Start of day
	$result = TotalCMSTwigFilters::dateStartOf($date, 'day');
	expect($result)->toContain('2024-06-15T00:00:00');

	// Start of month
	$result2 = TotalCMSTwigFilters::dateStartOf($date, 'month');
	expect($result2)->toContain('2024-06-01T00:00:00');

	// Start of year
	$result3 = TotalCMSTwigFilters::dateStartOf($date, 'year');
	expect($result3)->toContain('2024-01-01T00:00:00');
});

test('date end of filter', function () {
	$date = '2024-06-15 14:30:45';

	// End of day
	$result = TotalCMSTwigFilters::dateEndOf($date, 'day');
	expect($result)->toContain('2024-06-15T23:59:59');

	// End of month
	$result2 = TotalCMSTwigFilters::dateEndOf($date, 'month');
	expect($result2)->toContain('2024-06-30T23:59:59');

	// End of year
	$result3 = TotalCMSTwigFilters::dateEndOf($date, 'year');
	expect($result3)->toContain('2024-12-31T23:59:59');
});

test('date is weekend filter', function () {
	// Saturday (2024-06-15 is a Saturday)
	$saturday = '2024-06-15';
	$result   = TotalCMSTwigFilters::dateIsWeekend($saturday);
	expect($result)->toBeTrue();

	// Monday (2024-06-17 is a Monday)
	$monday  = '2024-06-17';
	$result2 = TotalCMSTwigFilters::dateIsWeekend($monday);
	expect($result2)->toBeFalse();
});

test('date is weekday filter', function () {
	// Monday (2024-06-17 is a Monday)
	$monday = '2024-06-17';
	$result = TotalCMSTwigFilters::dateIsWeekday($monday);
	expect($result)->toBeTrue();

	// Saturday (2024-06-15 is a Saturday)
	$saturday = '2024-06-15';
	$result2  = TotalCMSTwigFilters::dateIsWeekday($saturday);
	expect($result2)->toBeFalse();
});

test('date is past filter', function () {
	// A date definitely in the past
	$pastDate = '2020-01-01';
	$result   = TotalCMSTwigFilters::dateIsPast($pastDate);
	expect($result)->toBeTrue();

	// A date in the future
	$futureDate = '2030-01-01';
	$result2    = TotalCMSTwigFilters::dateIsPast($futureDate);
	expect($result2)->toBeFalse();
});

test('date is future filter', function () {
	// A date in the future
	$futureDate = '2030-01-01';
	$result     = TotalCMSTwigFilters::dateIsFuture($futureDate);
	expect($result)->toBeTrue();

	// A date definitely in the past
	$pastDate = '2020-01-01';
	$result2  = TotalCMSTwigFilters::dateIsFuture($pastDate);
	expect($result2)->toBeFalse();
});

test('date is today filter', function () {
	// Today
	$today  = date('Y-m-d');
	$result = TotalCMSTwigFilters::dateIsToday($today);
	expect($result)->toBeTrue();

	// Yesterday
	$yesterday = date('Y-m-d', strtotime('-1 day'));
	$result2   = TotalCMSTwigFilters::dateIsToday($yesterday);
	expect($result2)->toBeFalse();
});

test('filters handle invalid dates gracefully', function () {
	$invalidDate = 'not a date';

	// All filters should handle invalid dates gracefully
	expect(TotalCMSTwigFilters::dateRelative($invalidDate))->toBe($invalidDate);
	expect(TotalCMSTwigFilters::dateFormat($invalidDate))->toBe($invalidDate);
	expect(TotalCMSTwigFilters::dateAdd($invalidDate, '+1 day'))->toBe($invalidDate);
	expect(TotalCMSTwigFilters::dateSubtract($invalidDate, '1 day'))->toBe($invalidDate);
	expect(TotalCMSTwigFilters::dateDiff($invalidDate, 'also invalid'))->toBe('');
	expect(TotalCMSTwigFilters::dateStartOf($invalidDate, 'day'))->toBe($invalidDate);
	expect(TotalCMSTwigFilters::dateEndOf($invalidDate, 'day'))->toBe($invalidDate);
	expect(TotalCMSTwigFilters::dateIsWeekend($invalidDate))->toBeFalse();
	expect(TotalCMSTwigFilters::dateIsWeekday($invalidDate))->toBeFalse();
	expect(TotalCMSTwigFilters::dateIsPast($invalidDate))->toBeFalse();
	expect(TotalCMSTwigFilters::dateIsFuture($invalidDate))->toBeFalse();
	expect(TotalCMSTwigFilters::dateIsToday($invalidDate))->toBeFalse();
});

test('filters work with smart date strings', function () {
	// Test with relative date strings
	$result = TotalCMSTwigFilters::dateFormat('tomorrow', 'Y-m-d');
	expect($result)->toMatch('/^\d{4}-\d{2}-\d{2}$/');

	$result2 = TotalCMSTwigFilters::dateFormat('yesterday', 'Y-m-d');
	expect($result2)->toMatch('/^\d{4}-\d{2}-\d{2}$/');

	$result3 = TotalCMSTwigFilters::dateFormat('+1 week', 'Y-m-d');
	expect($result3)->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});
