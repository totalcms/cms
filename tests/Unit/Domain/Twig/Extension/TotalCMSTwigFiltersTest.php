<?php

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

describe('TotalCMSTwigFilters', function (): void {
	
	// -------------------------
	// Filter Registration
	// -------------------------
	
	test('TotalCMSTwigFilters → getFilters returns array of TwigFilter instances', function (): void {
		$filters = TotalCMSTwigFilters::getFilters();
		
		expect($filters)->toBeArray();
		expect(count($filters))->toBeGreaterThan(0);
		
		foreach ($filters as $filter) {
			expect($filter)->toBeInstanceOf(\Twig\TwigFilter::class);
		}
	});
	
	test('TotalCMSTwigFilters → includes all custom functions in filters', function (): void {
		$filters = TotalCMSTwigFilters::getFilters();
		$filterNames = array_map(fn($filter) => $filter->getName(), $filters);
		
		foreach (TotalCMSTwigFilters::$customFunctions as $customFunction) {
			expect($filterNames)->toContain($customFunction);
		}
	});
	
	test('TotalCMSTwigFilters → includes all PHP functions in filters', function (): void {
		$filters = TotalCMSTwigFilters::getFilters();
		$filterNames = array_map(fn($filter) => $filter->getName(), $filters);
		
		foreach (TotalCMSTwigFilters::$phpFunctions as $phpFunction) {
			expect($filterNames)->toContain($phpFunction);
		}
	});

	// -------------------------
	// Text Manipulation
	// -------------------------
	
	test('TotalCMSTwigFilters → digitsOnly extracts only digits', function (): void {
		expect(TotalCMSTwigFilters::digitsOnly('abc123def456'))->toBe('123456');
		expect(TotalCMSTwigFilters::digitsOnly('(555) 123-4567'))->toBe('5551234567');
		expect(TotalCMSTwigFilters::digitsOnly('$1,234.56'))->toBe('123456');
		expect(TotalCMSTwigFilters::digitsOnly('no-digits-here!'))->toBe('');
		expect(TotalCMSTwigFilters::digitsOnly(''))->toBe('');
		expect(TotalCMSTwigFilters::digitsOnly('999'))->toBe('999');
	});
	
	test('TotalCMSTwigFilters → humanize converts slug to readable text', function (): void {
		expect(TotalCMSTwigFilters::humanize('hello-world'))->toBe('Hello world');
		expect(TotalCMSTwigFilters::humanize('my_awesome_post', '_'))->toBe('My awesome post');
		expect(TotalCMSTwigFilters::humanize('single'))->toBe('Single');
		expect(TotalCMSTwigFilters::humanize(''))->toBe('');
		expect(TotalCMSTwigFilters::humanize('no-separators'))->toBe('No separators');
	});
	
	test('TotalCMSTwigFilters → titleize converts slug to title case', function (): void {
		expect(TotalCMSTwigFilters::titleize('hello-world'))->toBe('Hello World');
		expect(TotalCMSTwigFilters::titleize('my_awesome_post', '_'))->toBe('My Awesome Post');
		expect(TotalCMSTwigFilters::titleize('single'))->toBe('Single');
		expect(TotalCMSTwigFilters::titleize(''))->toBe('');
		expect(TotalCMSTwigFilters::titleize('multiple-word-title'))->toBe('Multiple Word Title');
	});
	
	test('TotalCMSTwigFilters → truncate limits string length', function (): void {
		$longText = 'This is a very long text that should be truncated';
		
		expect(TotalCMSTwigFilters::truncate($longText, 20))->toBe('This is a very long &hellip;');
		expect(TotalCMSTwigFilters::truncate($longText, 20, true))->toBe('This is a very long&hellip;'); // Keep words
		expect(TotalCMSTwigFilters::truncate('Short', 20))->toBe('Short'); // No truncation needed
		expect(TotalCMSTwigFilters::truncate('', 10))->toBe('');
		expect(TotalCMSTwigFilters::truncate(null, 10))->toBe('');
		expect(TotalCMSTwigFilters::truncate('<p>HTML content</p>', 10))->toBe('HTML conte&hellip;'); // Strips tags
	});
	
	test('TotalCMSTwigFilters → truncateWords limits word count', function (): void {
		$text = 'This is a test with many words to truncate properly';
		
		expect(TotalCMSTwigFilters::truncateWords($text, 5))->toBe('This is a test with&hellip;');
		expect(TotalCMSTwigFilters::truncateWords($text, 20))->toBe($text); // No truncation needed
		expect(TotalCMSTwigFilters::truncateWords('Single', 5))->toBe('Single');
		expect(TotalCMSTwigFilters::truncateWords('', 5))->toBe('');
		expect(TotalCMSTwigFilters::truncateWords('<p>HTML with spaces</p>', 2))->toBe('HTML with&hellip;'); // Strips tags
	});

	// -------------------------
	// Phone Formatting
	// -------------------------
	
	test('TotalCMSTwigFilters → formatPhone formats US phone numbers', function (): void {
		expect(TotalCMSTwigFilters::formatPhone('5551234567', 'US'))->toBe('(555) 123-4567');
		expect(TotalCMSTwigFilters::formatPhone('(555) 123-4567', 'US'))->toBe('(555) 123-4567');
		expect(TotalCMSTwigFilters::formatPhone('555.123.4567', 'US'))->toBe('(555) 123-4567');
		expect(TotalCMSTwigFilters::formatPhone('invalid', 'US'))->toBe(''); // No digits
	});
	
	test('TotalCMSTwigFilters → formatPhone formats GB phone numbers', function (): void {
		expect(TotalCMSTwigFilters::formatPhone('02012345678', 'GB'))->toBe('(020) 1234 5678');
		expect(TotalCMSTwigFilters::formatPhone('07123456789', 'GB'))->toBe('07123 456789'); // Mobile uses GBM format
	});
	
	test('TotalCMSTwigFilters → formatPhone handles various country codes', function (): void {
		expect(TotalCMSTwigFilters::formatPhone('5551234567', 'CA'))->toBe('(555) 123-4567'); // Canada
		expect(TotalCMSTwigFilters::formatPhone('1234567890', 'AU'))->toBe('1234 567 890'); // Australia
		expect(TotalCMSTwigFilters::formatPhone('1234567890', 'INVALID'))->toBe('1234567890'); // Unknown format
	});

	// -------------------------
	// Counters
	// -------------------------
	
	test('TotalCMSTwigFilters → charcount counts characters correctly', function (): void {
		expect(TotalCMSTwigFilters::charcount('Hello World'))->toBe(11);
		expect(TotalCMSTwigFilters::charcount('<p>HTML content</p>'))->toBe(12); // Strips HTML
		expect(TotalCMSTwigFilters::charcount('Multiple   spaces'))->toBe(15); // Normalizes spaces
		expect(TotalCMSTwigFilters::charcount(''))->toBe(0);
		expect(TotalCMSTwigFilters::charcount('Unicode: 世界'))->toBe(11); // Supports multibyte
	});
	
	test('TotalCMSTwigFilters → wordcount counts words correctly', function (): void {
		expect(TotalCMSTwigFilters::wordcount('Hello World'))->toBe(2);
		expect(TotalCMSTwigFilters::wordcount('One, two, three!'))->toBe(3);
		expect(TotalCMSTwigFilters::wordcount('<p>HTML content here</p>'))->toBe(3); // Strips HTML
		expect(TotalCMSTwigFilters::wordcount(''))->toBe(0);
		expect(TotalCMSTwigFilters::wordcount('Multiple   spaces   between'))->toBe(3);
	});
	
	test('TotalCMSTwigFilters → readtime calculates reading time', function (): void {
		$shortText = str_repeat('word ', 100); // 100 words
		$longText = str_repeat('word ', 500); // 500 words
		
		expect(TotalCMSTwigFilters::readtime($shortText))->toBe(1.0); // 100/180 = 0.56, ceil = 1
		expect(TotalCMSTwigFilters::readtime($longText))->toBe(3.0); // 500/180 = 2.78, ceil = 3
		expect(TotalCMSTwigFilters::readtime($shortText, 100))->toBe(1.0); // Custom WPM
		expect(TotalCMSTwigFilters::readtime(''))->toBe(0.0);
	});

	// -------------------------
	// Array Manipulation
	// -------------------------
	
	test('TotalCMSTwigFilters → sortBy sorts array by key', function (): void {
		$array = [
			['name' => 'Charlie', 'age' => 35],
			['name' => 'Alice', 'age' => 25],
			['name' => 'Bob', 'age' => 30],
		];
		
		$sortedByName = TotalCMSTwigFilters::sortBy($array, 'name');
		expect($sortedByName[0]['name'])->toBe('Alice');
		expect($sortedByName[1]['name'])->toBe('Bob');
		expect($sortedByName[2]['name'])->toBe('Charlie');
		
		$sortedByAge = TotalCMSTwigFilters::sortBy($array, 'age');
		expect($sortedByAge[0]['age'])->toBe(25);
		expect($sortedByAge[1]['age'])->toBe(30);
		expect($sortedByAge[2]['age'])->toBe(35);
	});
	
	test('TotalCMSTwigFilters → sortBy handles empty arrays and missing keys', function (): void {
		expect(TotalCMSTwigFilters::sortBy([], 'name'))->toBe([]);
		expect(TotalCMSTwigFilters::sortBy([['name' => 'Test']], ''))->toBe([['name' => 'Test']]);
		
		$arrayWithMissingKeys = [
			['name' => 'Alice'],
			['age' => 30],
			['name' => 'Bob', 'age' => 25],
		];
		$result = TotalCMSTwigFilters::sortBy($arrayWithMissingKeys, 'age');
		expect(count($result))->toBe(3); // Should not crash
	});
	
	test('TotalCMSTwigFilters → ksort sorts by keys', function (): void {
		$array = ['c' => 3, 'a' => 1, 'b' => 2];
		$sorted = TotalCMSTwigFilters::ksort($array);
		
		expect(array_keys($sorted))->toBe(['a', 'b', 'c']);
		expect(array_values($sorted))->toBe([1, 2, 3]);
	});
	
	test('TotalCMSTwigFilters → krsort sorts by keys in reverse', function (): void {
		$array = ['a' => 1, 'b' => 2, 'c' => 3];
		$sorted = TotalCMSTwigFilters::krsort($array);
		
		expect(array_keys($sorted))->toBe(['c', 'b', 'a']);
		expect(array_values($sorted))->toBe([3, 2, 1]);
	});
	
	test('TotalCMSTwigFilters → shuffle randomizes array order', function (): void {
		$array = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
		$shuffled = TotalCMSTwigFilters::shuffle($array);
		
		expect(count($shuffled))->toBe(count($array));
		expect(array_sum($shuffled))->toBe(array_sum($array)); // Same elements
		// Note: Can't reliably test order change due to randomness
	});
	
	test('TotalCMSTwigFilters → paginate slices array correctly', function (): void {
		$array = range(1, 20); // [1, 2, 3, ..., 20]
		
		$page1 = TotalCMSTwigFilters::paginate($array, 5, 1);
		expect($page1)->toBe([1, 2, 3, 4, 5]);
		
		$page3 = TotalCMSTwigFilters::paginate($array, 5, 3);
		expect($page3)->toBe([11, 12, 13, 14, 15]);
		
		$lastPage = TotalCMSTwigFilters::paginate($array, 7, 3);
		expect($lastPage)->toBe([15, 16, 17, 18, 19, 20]);
		
		// Edge cases
		expect(TotalCMSTwigFilters::paginate([], 5))->toBe([]);
		expect(TotalCMSTwigFilters::paginate([1, 2], 10))->toBe([1, 2]);
	});

	// -------------------------
	// Type Casting
	// -------------------------
	
	test('TotalCMSTwigFilters → typeof returns correct types', function (): void {
		expect(TotalCMSTwigFilters::typeof('string'))->toBe('string');
		expect(TotalCMSTwigFilters::typeof(123))->toBe('integer');
		expect(TotalCMSTwigFilters::typeof(12.34))->toBe('double');
		expect(TotalCMSTwigFilters::typeof(true))->toBe('boolean');
		expect(TotalCMSTwigFilters::typeof([]))->toBe('array');
		expect(TotalCMSTwigFilters::typeof(null))->toBe('NULL');
	});
	
	test('TotalCMSTwigFilters → string casts values to string', function (): void {
		expect(TotalCMSTwigFilters::string(123))->toBe('123');
		expect(TotalCMSTwigFilters::string(12.34))->toBe('12.34');
		expect(TotalCMSTwigFilters::string(true))->toBe('1');
		expect(TotalCMSTwigFilters::string(false))->toBe('');
		expect(TotalCMSTwigFilters::string(null))->toBe('');
	});
	
	test('TotalCMSTwigFilters → int casts values to integer', function (): void {
		expect(TotalCMSTwigFilters::int('123'))->toBe(123);
		expect(TotalCMSTwigFilters::int('12.99'))->toBe(12);
		expect(TotalCMSTwigFilters::int(true))->toBe(1);
		expect(TotalCMSTwigFilters::int(false))->toBe(0);
		expect(TotalCMSTwigFilters::int('invalid'))->toBe(0);
	});
	
	test('TotalCMSTwigFilters → float casts values to float', function (): void {
		expect(TotalCMSTwigFilters::float('12.34'))->toBe(12.34);
		expect(TotalCMSTwigFilters::float('123'))->toBe(123.0);
		expect(TotalCMSTwigFilters::float(true))->toBe(1.0);
		expect(TotalCMSTwigFilters::float(false))->toBe(0.0);
		expect(TotalCMSTwigFilters::float('invalid'))->toBe(0.0);
	});
	
	test('TotalCMSTwigFilters → bool casts values to boolean', function (): void {
		expect(TotalCMSTwigFilters::bool('non-empty'))->toBe(true);
		expect(TotalCMSTwigFilters::bool(''))->toBe(false);
		expect(TotalCMSTwigFilters::bool(1))->toBe(true);
		expect(TotalCMSTwigFilters::bool(0))->toBe(false);
		expect(TotalCMSTwigFilters::bool([1, 2]))->toBe(true);
		expect(TotalCMSTwigFilters::bool([]))->toBe(false);
	});
	
	test('TotalCMSTwigFilters → array casts values to array', function (): void {
		expect(TotalCMSTwigFilters::array('string'))->toBe(['string']);
		expect(TotalCMSTwigFilters::array(123))->toBe([123]);
		expect(TotalCMSTwigFilters::array(null))->toBe([]);
		expect(TotalCMSTwigFilters::array([1, 2, 3]))->toBe([1, 2, 3]);
	});

	// -------------------------
	// SVG Processing
	// -------------------------
	
	test('TotalCMSTwigFilters → svgToSymbol converts SVG to symbol', function (): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>';
		$result = TotalCMSTwigFilters::svgToSymbol($svg, 'my-icon');
		
		expect($result)->toContain('symbol id="my-icon"');
		expect($result)->toContain('viewBox="0 0 24 24"');
		expect($result)->toContain('<circle cx="12" cy="12" r="10"/>');
		expect($result)->toContain('style="display:none"');
	});
	
	test('TotalCMSTwigFilters → svgToSymbol returns original if no viewBox', function (): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10"/></svg>';
		$result = TotalCMSTwigFilters::svgToSymbol($svg, 'icon');
		
		expect($result)->toBe($svg); // Returns original unchanged
	});

	// -------------------------
	// Price Formatting
	// -------------------------
	
	test('TotalCMSTwigFilters → price formats prices correctly', function (): void {
		expect(TotalCMSTwigFilters::price(19.99))->toBe('$19.99');
		expect(TotalCMSTwigFilters::price(19.99, '€', 'prepend'))->toBe('€19.99');
		expect(TotalCMSTwigFilters::price(19.99, 'USD', 'append'))->toBe('19.99USD');
		expect(TotalCMSTwigFilters::price(19.99, '$', 'none'))->toBe('19.99');
		expect(TotalCMSTwigFilters::price(19.9))->toBe('$19.90'); // Adds trailing zero
		expect(TotalCMSTwigFilters::price(20))->toBe('$20.00');
	});
	
	test('TotalCMSTwigFilters → price handles edge cases', function (): void {
		expect(TotalCMSTwigFilters::price(0))->toBe('$0.00'); // Zero is valid
		expect(TotalCMSTwigFilters::price('0'))->toBe('$0.00'); // String zero
		expect(TotalCMSTwigFilters::price(''))->toBe(''); // Empty string
		expect(TotalCMSTwigFilters::price(null))->toBe(''); // Null
		expect(TotalCMSTwigFilters::price('19.99'))->toBe('$19.99'); // String number
		expect(TotalCMSTwigFilters::price('invalid'))->toBe('$0.00'); // Invalid string
	});

	// -------------------------
	// Color Manipulation
	// -------------------------
	
	test('TotalCMSTwigFilters → hexToColor creates color array', function (): void {
		$color = TotalCMSTwigFilters::hexToColor('#ff0000');
		
		expect($color)->toHaveKey('hex', '#ff0000');
		expect($color)->toHaveKey('oklch');
		expect($color['oklch'])->toBeArray();
	});
	
	test('TotalCMSTwigFilters → hex extracts hex from color array', function (): void {
		$color = ['hex' => '#00ff00', 'oklch' => ['l' => 0.8, 'c' => 0.2, 'h' => 120]];
		
		expect(TotalCMSTwigFilters::hex($color))->toBe('#00ff00');
		expect(TotalCMSTwigFilters::hex(null))->toBe('');
		expect(TotalCMSTwigFilters::hex([]))->toBe('#000000'); // Default fallback
	});
	
	test('TotalCMSTwigFilters → rgb converts color to RGB format', function (): void {
		$color = ['hex' => '#ff0000'];
		
		expect(TotalCMSTwigFilters::rgb($color))->toBe('rgb(255 0 0)');
		expect(TotalCMSTwigFilters::rgb($color, 80))->toBe('rgb(255 0 0 / 0.80)');
		expect(TotalCMSTwigFilters::rgb($color, 100, false))->toBe('255 0 0');
		expect(TotalCMSTwigFilters::rgb(null))->toBe('');
	});
	
	test('TotalCMSTwigFilters → hsl converts color to HSL format', function (): void {
		$color = ['hex' => '#ff0000'];
		
		$result = TotalCMSTwigFilters::hsl($color);
		expect($result)->toStartWith('hsl(');
		expect($result)->toContain('%');
		
		expect(TotalCMSTwigFilters::hsl($color, 75))->toContain('/ 0.75');
		expect(TotalCMSTwigFilters::hsl($color, 100, false))->not->toStartWith('hsl(');
		expect(TotalCMSTwigFilters::hsl(null))->toBe('');
	});
	
	test('TotalCMSTwigFilters → oklch converts color to OKLCH format', function (): void {
		$color = ['oklch' => ['l' => 62.796, 'c' => 0.257, 'h' => 29.234]];
		
		$result = TotalCMSTwigFilters::oklch($color);
		expect($result)->toStartWith('oklch(');
		expect($result)->toContain('%');
		
		expect(TotalCMSTwigFilters::oklch($color, 50))->toContain('/ 0.50');
		expect(TotalCMSTwigFilters::oklch($color, 100, false))->not->toStartWith('oklch(');
		expect(TotalCMSTwigFilters::oklch(null))->toBe('');
	});
	
	test('TotalCMSTwigFilters → color and colour are aliases for oklch', function (): void {
		$color = ['oklch' => ['l' => 50, 'c' => 0.1, 'h' => 180]];
		
		$oklchResult = TotalCMSTwigFilters::oklch($color);
		$colorResult = TotalCMSTwigFilters::color($color);
		$colourResult = TotalCMSTwigFilters::colour($color);
		
		expect($colorResult)->toBe($oklchResult);
		expect($colourResult)->toBe($oklchResult);
	});

	// -------------------------
	// Date Manipulation
	// -------------------------
	
	test('TotalCMSTwigFilters → dateRelative returns relative date', function (): void {
		$pastDate = date('c', strtotime('-1 day'));
		$futureDate = date('c', strtotime('+1 day'));
		
		$pastResult = TotalCMSTwigFilters::dateRelative($pastDate);
		$futureResult = TotalCMSTwigFilters::dateRelative($futureDate);
		
		expect($pastResult)->toContain('ago');
		expect($futureResult)->toMatch('/in |\sfrom now/');
		
		// Invalid date fallback
		expect(TotalCMSTwigFilters::dateRelative('invalid-date'))->toBe('invalid-date');
	});
	
	test('TotalCMSTwigFilters → dateFormat formats dates', function (): void {
		$date = '2024-01-15T12:30:45+00:00';
		
		expect(TotalCMSTwigFilters::dateFormat($date, 'Y-m-d'))->toBe('2024-01-15');
		expect(TotalCMSTwigFilters::dateFormat($date, 'H:i:s'))->toBe('12:30:45');
		expect(TotalCMSTwigFilters::dateFormat($date))->toContain('2024');
		
		// Invalid date fallback
		expect(TotalCMSTwigFilters::dateFormat('invalid-date'))->toBe('invalid-date');
	});
	
	test('TotalCMSTwigFilters → dateAdd adds time to date', function (): void {
		$date = '2024-01-15T12:00:00+00:00';
		
		$result = TotalCMSTwigFilters::dateAdd($date, '+1 day');
		expect($result)->toContain('2024-01-16');
		
		$result2 = TotalCMSTwigFilters::dateAdd($date, '+2 hours');
		expect($result2)->toContain('14:00:00');
		
		// Invalid date fallback
		expect(TotalCMSTwigFilters::dateAdd('invalid', '+1 day'))->toBe('invalid');
	});
	
	test('TotalCMSTwigFilters → dateSubtract subtracts time from date', function (): void {
		$date = '2024-01-15T12:00:00+00:00';
		
		$result = TotalCMSTwigFilters::dateSubtract($date, '1 day');
		expect($result)->toContain('2024-01-14');
		
		$result2 = TotalCMSTwigFilters::dateSubtract($date, '-2 hours'); // Already negative
		expect($result2)->toContain('10:00:00');
		
		// Invalid date fallback
		expect(TotalCMSTwigFilters::dateSubtract('invalid', '1 day'))->toBe('invalid');
	});
	
	test('TotalCMSTwigFilters → dateDiff shows difference between dates', function (): void {
		$date1 = '2024-01-15T12:00:00+00:00';
		$date2 = '2024-01-16T12:00:00+00:00';
		
		$result = TotalCMSTwigFilters::dateDiff($date1, $date2);
		expect($result)->not->toBe('');
		
		// Invalid dates
		expect(TotalCMSTwigFilters::dateDiff('invalid1', 'invalid2'))->toBe('');
	});
	
	test('TotalCMSTwigFilters → dateStartOf gets start of period', function (): void {
		$date = '2024-01-15T15:30:45+00:00';
		
		expect(TotalCMSTwigFilters::dateStartOf($date, 'day'))->toContain('T00:00:00');
		expect(TotalCMSTwigFilters::dateStartOf($date, 'month'))->toContain('2024-01-01');
		expect(TotalCMSTwigFilters::dateStartOf($date, 'year'))->toContain('2024-01-01');
		expect(TotalCMSTwigFilters::dateStartOf($date, 'invalid'))->toBe($date); // Invalid unit returns original
		
		// Invalid date fallback
		expect(TotalCMSTwigFilters::dateStartOf('invalid', 'day'))->toBe('invalid');
	});
	
	test('TotalCMSTwigFilters → dateEndOf gets end of period', function (): void {
		$date = '2024-01-15T15:30:45+00:00';
		
		expect(TotalCMSTwigFilters::dateEndOf($date, 'day'))->toContain('T23:59:59');
		expect(TotalCMSTwigFilters::dateEndOf($date, 'month'))->toContain('2024-01-31');
		expect(TotalCMSTwigFilters::dateEndOf($date, 'year'))->toContain('2024-12-31');
		
		// Invalid date fallback
		expect(TotalCMSTwigFilters::dateEndOf('invalid', 'day'))->toBe('invalid');
	});
	
	test('TotalCMSTwigFilters → dateIsWeekend detects weekends', function (): void {
		$saturday = '2024-01-13T12:00:00+00:00'; // Saturday
		$sunday = '2024-01-14T12:00:00+00:00';   // Sunday
		$monday = '2024-01-15T12:00:00+00:00';   // Monday
		
		expect(TotalCMSTwigFilters::dateIsWeekend($saturday))->toBe(true);
		expect(TotalCMSTwigFilters::dateIsWeekend($sunday))->toBe(true);
		expect(TotalCMSTwigFilters::dateIsWeekend($monday))->toBe(false);
		
		// Invalid date fallback
		expect(TotalCMSTwigFilters::dateIsWeekend('invalid'))->toBe(false);
	});
	
	test('TotalCMSTwigFilters → dateIsWeekday detects weekdays', function (): void {
		$saturday = '2024-01-13T12:00:00+00:00'; // Saturday
		$monday = '2024-01-15T12:00:00+00:00';   // Monday
		
		expect(TotalCMSTwigFilters::dateIsWeekday($saturday))->toBe(false);
		expect(TotalCMSTwigFilters::dateIsWeekday($monday))->toBe(true);
		
		// Invalid date fallback
		expect(TotalCMSTwigFilters::dateIsWeekday('invalid'))->toBe(false);
	});

	// -------------------------
	// Encryption/Security
	// -------------------------
	
	test('TotalCMSTwigFilters → obfuscate and deobfuscate work together', function (): void {
		$original = 'sensitive-information';
		$obfuscated = TotalCMSTwigFilters::obfuscate($original);
		$deobfuscated = TotalCMSTwigFilters::deobfuscate($obfuscated);
		
		expect($obfuscated)->not->toBe($original);
		expect($deobfuscated)->toBe($original);
	});
	
	test('TotalCMSTwigFilters → encrypt and decrypt work together', function (): void {
		$original = 'secret-message';
		$encrypted = TotalCMSTwigFilters::encrypt($original);
		$decrypted = TotalCMSTwigFilters::decrypt($encrypted);
		
		expect($encrypted)->not->toBe($original);
		expect($decrypted)->toBe($original);
	});

	// -------------------------
	// Utility Delegation
	// -------------------------
	
	test('TotalCMSTwigFilters → var_dump delegates to TotalCMSTwigFunctions', function (): void {
		$result = TotalCMSTwigFilters::var_dump(['test' => 'value']);
		
		expect($result)->toBeString();
		expect($result)->not->toBe('');
	});
	
	test('TotalCMSTwigFilters → print_r delegates to TotalCMSTwigFunctions', function (): void {
		$result = TotalCMSTwigFilters::print_r(['test' => 'value']);
		
		expect($result)->toBeString();
		expect($result)->not->toBe('');
	});
	
	test('TotalCMSTwigFilters → markdown converts markdown to HTML', function (): void {
		$markdown = '# Heading\n\n**Bold text**';
		$result = TotalCMSTwigFilters::markdown($markdown);
		
		expect($result)->toContain('<h1>');
		expect($result)->toContain('Heading');
		expect($result)->toContain('<strong>');
		expect($result)->toContain('Bold text');
		
		// Non-string input
		expect(TotalCMSTwigFilters::markdown(123))->toBeString();
	});
});