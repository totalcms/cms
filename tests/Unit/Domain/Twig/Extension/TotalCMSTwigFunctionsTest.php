<?php

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFunctions;

describe('TotalCMSTwigFunctions', function (): void {
	// -------------------------
	// Function Registration
	// -------------------------

	test('TotalCMSTwigFunctions → getFunctions returns array of TwigFunction instances', function (): void {
		$functions = TotalCMSTwigFunctions::getFunctions();

		expect($functions)->toBeArray();
		expect(count($functions))->toBeGreaterThan(0);

		foreach ($functions as $function) {
			expect($function)->toBeInstanceOf(Twig\TwigFunction::class);
		}
	});

	test('TotalCMSTwigFunctions → includes all custom functions in function list', function (): void {
		$functions     = TotalCMSTwigFunctions::getFunctions();
		$functionNames = array_map(fn (Twig\TwigFunction $function): string => $function->getName(), $functions);

		foreach (TotalCMSTwigFunctions::$customFunctions as $customFunction) {
			expect($functionNames)->toContain($customFunction);
		}
	});

	test('TotalCMSTwigFunctions → includes all PHP functions in function list', function (): void {
		$functions     = TotalCMSTwigFunctions::getFunctions();
		$functionNames = array_map(fn (Twig\TwigFunction $function): string => $function->getName(), $functions);

		foreach (TotalCMSTwigFunctions::$phpFunctions as $phpFunction) {
			expect($functionNames)->toContain($phpFunction);
		}
	});

	// -------------------------
	// Select Options Generation
	// -------------------------

	test('TotalCMSTwigFunctions → selectOptions returns empty array for empty input', function (): void {
		expect(TotalCMSTwigFunctions::selectOptions(null))->toBe([]);
		expect(TotalCMSTwigFunctions::selectOptions([]))->toBe([]);
	});

	test('TotalCMSTwigFunctions → selectOptions creates label/value pairs from simple array', function (): void {
		$data   = ['apple', 'banana', 'cherry'];
		$result = TotalCMSTwigFunctions::selectOptions($data);

		expect($result)->toBe([
			['label' => 'apple', 'value' => 'apple'],
			['label' => 'banana', 'value' => 'banana'],
			['label' => 'cherry', 'value' => 'cherry'],
		]);
	});

	test('TotalCMSTwigFunctions → selectOptions uses specified label and value keys', function (): void {
		$data = [
			['name' => 'Apple Fruit', 'id' => 'apple'],
			['name' => 'Banana Fruit', 'id' => 'banana'],
			['name' => 'Cherry Fruit', 'id' => 'cherry'],
		];

		$result = TotalCMSTwigFunctions::selectOptions($data, 'name', 'id');

		expect($result)->toBe([
			['label' => 'Apple Fruit', 'value' => 'apple'],
			['label' => 'Banana Fruit', 'value' => 'banana'],
			['label' => 'Cherry Fruit', 'value' => 'cherry'],
		]);
	});

	test('TotalCMSTwigFunctions → selectOptions falls back to default when keys are empty', function (): void {
		$data = ['red', 'green', 'blue'];

		$result1 = TotalCMSTwigFunctions::selectOptions($data, '', '');
		$result2 = TotalCMSTwigFunctions::selectOptions($data, 'name', ''); // Empty value
		$result3 = TotalCMSTwigFunctions::selectOptions($data, '', 'id'); // Empty label

		$expected = [
			['label' => 'red', 'value' => 'red'],
			['label' => 'green', 'value' => 'green'],
			['label' => 'blue', 'value' => 'blue'],
		];

		expect($result1)->toBe($expected);
		expect($result2)->toBe($expected);
		expect($result3)->toBe($expected);
	});

	// -------------------------
	// Navigation Functions
	// -------------------------

	test('TotalCMSTwigFunctions → next returns next item in array of objects', function (): void {
		$items = [
			['id' => 'a', 'title' => 'First'],
			['id' => 'b', 'title' => 'Second'],
			['id' => 'c', 'title' => 'Third'],
		];

		$result = TotalCMSTwigFunctions::next($items, 'a');
		expect($result)->toBe(['id' => 'b', 'title' => 'Second']);

		$result = TotalCMSTwigFunctions::next($items, 'b');
		expect($result)->toBe(['id' => 'c', 'title' => 'Third']);
	});

	test('TotalCMSTwigFunctions → prev returns previous item in array of objects', function (): void {
		$items = [
			['id' => 'a', 'title' => 'First'],
			['id' => 'b', 'title' => 'Second'],
			['id' => 'c', 'title' => 'Third'],
		];

		$result = TotalCMSTwigFunctions::prev($items, 'c');
		expect($result)->toBe(['id' => 'b', 'title' => 'Second']);

		$result = TotalCMSTwigFunctions::prev($items, 'b');
		expect($result)->toBe(['id' => 'a', 'title' => 'First']);
	});

	test('TotalCMSTwigFunctions → next returns null at end of list without wrap', function (): void {
		$items = [
			['id' => 'a', 'title' => 'First'],
			['id' => 'b', 'title' => 'Second'],
		];

		expect(TotalCMSTwigFunctions::next($items, 'b'))->toBeNull();
	});

	test('TotalCMSTwigFunctions → prev returns null at start of list without wrap', function (): void {
		$items = [
			['id' => 'a', 'title' => 'First'],
			['id' => 'b', 'title' => 'Second'],
		];

		expect(TotalCMSTwigFunctions::prev($items, 'a'))->toBeNull();
	});

	test('TotalCMSTwigFunctions → next wraps to first item when wrap is true', function (): void {
		$items = [
			['id' => 'a', 'title' => 'First'],
			['id' => 'b', 'title' => 'Second'],
			['id' => 'c', 'title' => 'Third'],
		];

		$result = TotalCMSTwigFunctions::next($items, 'c', true);
		expect($result)->toBe(['id' => 'a', 'title' => 'First']);
	});

	test('TotalCMSTwigFunctions → prev wraps to last item when wrap is true', function (): void {
		$items = [
			['id' => 'a', 'title' => 'First'],
			['id' => 'b', 'title' => 'Second'],
			['id' => 'c', 'title' => 'Third'],
		];

		$result = TotalCMSTwigFunctions::prev($items, 'a', true);
		expect($result)->toBe(['id' => 'c', 'title' => 'Third']);
	});

	test('TotalCMSTwigFunctions → next works with flat array of IDs', function (): void {
		$ids = ['abc', 'def', 'ghi'];

		expect(TotalCMSTwigFunctions::next($ids, 'abc'))->toBe('def');
		expect(TotalCMSTwigFunctions::next($ids, 'def'))->toBe('ghi');
		expect(TotalCMSTwigFunctions::next($ids, 'ghi'))->toBeNull();
	});

	test('TotalCMSTwigFunctions → prev works with flat array of IDs', function (): void {
		$ids = ['abc', 'def', 'ghi'];

		expect(TotalCMSTwigFunctions::prev($ids, 'ghi'))->toBe('def');
		expect(TotalCMSTwigFunctions::prev($ids, 'def'))->toBe('abc');
		expect(TotalCMSTwigFunctions::prev($ids, 'abc'))->toBeNull();
	});

	test('TotalCMSTwigFunctions → next/prev wrap works with flat array of IDs', function (): void {
		$ids = ['abc', 'def', 'ghi'];

		expect(TotalCMSTwigFunctions::next($ids, 'ghi', true))->toBe('abc');
		expect(TotalCMSTwigFunctions::prev($ids, 'abc', true))->toBe('ghi');
	});

	test('TotalCMSTwigFunctions → next/prev return null for unknown ID', function (): void {
		$items = [
			['id' => 'a', 'title' => 'First'],
			['id' => 'b', 'title' => 'Second'],
		];

		expect(TotalCMSTwigFunctions::next($items, 'unknown'))->toBeNull();
		expect(TotalCMSTwigFunctions::prev($items, 'unknown'))->toBeNull();
	});

	test('TotalCMSTwigFunctions → next/prev return null for empty array', function (): void {
		expect(TotalCMSTwigFunctions::next([], 'a'))->toBeNull();
		expect(TotalCMSTwigFunctions::prev([], 'a'))->toBeNull();
	});

	test('TotalCMSTwigFunctions → next/prev handle single item list', function (): void {
		$items = [['id' => 'a', 'title' => 'Only']];

		expect(TotalCMSTwigFunctions::next($items, 'a'))->toBeNull();
		expect(TotalCMSTwigFunctions::prev($items, 'a'))->toBeNull();
		expect(TotalCMSTwigFunctions::next($items, 'a', true))->toBe(['id' => 'a', 'title' => 'Only']);
		expect(TotalCMSTwigFunctions::prev($items, 'a', true))->toBe(['id' => 'a', 'title' => 'Only']);
	});

	test('TotalCMSTwigFunctions → next/prev match numeric IDs as strings', function (): void {
		$items = [
			['id' => 1, 'title' => 'First'],
			['id' => 2, 'title' => 'Second'],
			['id' => 3, 'title' => 'Third'],
		];

		expect(TotalCMSTwigFunctions::next($items, '1'))->toBe(['id' => 2, 'title' => 'Second']);
		expect(TotalCMSTwigFunctions::prev($items, '3'))->toBe(['id' => 2, 'title' => 'Second']);
	});

	// -------------------------
	// Session Functions
	// -------------------------

	test('TotalCMSTwigFunctions → setSessionData stores value in session', function (): void {
		$result = TotalCMSTwigFunctions::setSessionData('test-key', 'test-value');

		expect($result)->toBe('');
		expect($_SESSION['test-key'])->toBe('test-value');

		unset($_SESSION['test-key']);
	});

	test('TotalCMSTwigFunctions → setSessionData stores arrays in session', function (): void {
		$ids = ['abc', 'def', 'ghi'];
		TotalCMSTwigFunctions::setSessionData('nav-ids', $ids);

		expect($_SESSION['nav-ids'])->toBe(['abc', 'def', 'ghi']);

		unset($_SESSION['nav-ids']);
	});

	// -------------------------
	// Type Checking
	// -------------------------

	test('TotalCMSTwigFunctions → istype checks variable types correctly', function (): void {
		expect(TotalCMSTwigFunctions::istype('string', 'string'))->toBe(true);
		expect(TotalCMSTwigFunctions::istype(123, 'integer'))->toBe(true);
		expect(TotalCMSTwigFunctions::istype(12.34, 'double'))->toBe(true);
		expect(TotalCMSTwigFunctions::istype(true, 'boolean'))->toBe(true);
		expect(TotalCMSTwigFunctions::istype([], 'array'))->toBe(true);
		expect(TotalCMSTwigFunctions::istype(null, 'NULL'))->toBe(true);

		// Negative cases
		expect(TotalCMSTwigFunctions::istype('string', 'integer'))->toBe(false);
		expect(TotalCMSTwigFunctions::istype(123, 'string'))->toBe(false);
		expect(TotalCMSTwigFunctions::istype([], 'string'))->toBe(false);
	});

	// -------------------------
	// Array Sorting
	// -------------------------

	test('TotalCMSTwigFunctions → sortByKey sorts array by specified key', function (): void {
		$array = [
			['id' => 3, 'name' => 'Charlie'],
			['id' => 1, 'name' => 'Alice'],
			['id' => 2, 'name' => 'Bob'],
		];

		$result = TotalCMSTwigFunctions::sortByKey($array, 'id');

		expect($result[0]['id'])->toBe(1);
		expect($result[1]['id'])->toBe(2);
		expect($result[2]['id'])->toBe(3);
		expect($result[0]['name'])->toBe('Alice');
	});

	test('TotalCMSTwigFunctions → sortByKey uses default key "id"', function (): void {
		$array = [
			['id' => 'c', 'value' => 'Third'],
			['id' => 'a', 'value' => 'First'],
			['id' => 'b', 'value' => 'Second'],
		];

		$result = TotalCMSTwigFunctions::sortByKey($array); // No key specified, defaults to 'id'

		expect($result[0]['id'])->toBe('a');
		expect($result[1]['id'])->toBe('b');
		expect($result[2]['id'])->toBe('c');
	});

	test('TotalCMSTwigFunctions → sortByKey handles objects by converting to arrays internally', function (): void {
		$obj1 = (object)['id' => 2, 'name' => 'Object Two'];
		$obj2 = (object)['id' => 1, 'name' => 'Object One'];

		$array  = [$obj1, $obj2];
		$result = TotalCMSTwigFunctions::sortByKey($array, 'id');

		// Objects are kept as objects in the result, but sorted correctly
		expect($result[0]->id)->toBe(1);
		expect($result[1]->id)->toBe(2);
		expect($result[0]->name)->toBe('Object One');
		expect($result[1]->name)->toBe('Object Two');
	});

	test('TotalCMSTwigFunctions → sortByKey handles missing keys gracefully', function (): void {
		$array = [
			['id' => 1, 'name' => 'Has ID'],
			['name' => 'Missing ID'], // No 'id' key
			['id'   => 2, 'name' => 'Has ID Too'],
		];

		$result = TotalCMSTwigFunctions::sortByKey($array, 'id');

		// Should not crash and return some ordering
		expect(count($result))->toBe(3);
	});

	test('TotalCMSTwigFunctions → sortByKey handles non-array/non-object elements', function (): void {
		$array = [
			['id' => 1, 'name' => 'Valid'],
			'string-element', // Not array or object
			['id' => 2, 'name' => 'Also Valid'],
		];

		$result = TotalCMSTwigFunctions::sortByKey($array, 'id');

		// Should not crash
		expect(count($result))->toBe(3);
	});

	test('TotalCMSTwigFunctions → ksort sorts by keys', function (): void {
		$array  = ['c' => 3, 'a' => 1, 'b' => 2];
		$sorted = TotalCMSTwigFunctions::ksort($array);

		expect(array_keys($sorted))->toBe(['a', 'b', 'c']);
		expect(array_values($sorted))->toBe([1, 2, 3]);
	});

	test('TotalCMSTwigFunctions → krsort sorts by keys in reverse', function (): void {
		$array  = ['a' => 1, 'b' => 2, 'c' => 3];
		$sorted = TotalCMSTwigFunctions::krsort($array);

		expect(array_keys($sorted))->toBe(['c', 'b', 'a']);
		expect(array_values($sorted))->toBe([3, 2, 1]);
	});

	// -------------------------
	// File Existence Checks
	// -------------------------

	test('TotalCMSTwigFunctions → fileExists returns false for non-array input', function (): void {
		expect(TotalCMSTwigFunctions::fileExists('string'))->toBe(false);
		expect(TotalCMSTwigFunctions::fileExists(123))->toBe(false);
		expect(TotalCMSTwigFunctions::fileExists(null))->toBe(false);
		expect(TotalCMSTwigFunctions::fileExists(true))->toBe(false);
	});

	test('TotalCMSTwigFunctions → fileExists returns false for array without size', function (): void {
		$fileWithoutSize = ['name' => 'file.txt', 'mime' => 'text/plain'];

		expect(TotalCMSTwigFunctions::fileExists($fileWithoutSize))->toBe(false);
	});

	test('TotalCMSTwigFunctions → fileExists returns false for zero size files', function (): void {
		$emptyFile = ['name' => 'empty.txt', 'size' => 0];

		expect(TotalCMSTwigFunctions::fileExists($emptyFile))->toBe(false);
	});

	test('TotalCMSTwigFunctions → fileExists returns true for files with size', function (): void {
		$validFile = ['name' => 'document.pdf', 'size' => 1024, 'mime' => 'application/pdf'];

		expect(TotalCMSTwigFunctions::fileExists($validFile))->toBe(true);
	});

	test('TotalCMSTwigFunctions → imageExists is alias for fileExists', function (): void {
		$image      = ['name' => 'photo.jpg', 'size' => 2048];
		$emptyImage = ['name' => 'empty.jpg', 'size' => 0];

		expect(TotalCMSTwigFunctions::imageExists($image))->toBe(true);
		expect(TotalCMSTwigFunctions::imageExists($emptyImage))->toBe(false);
		expect(TotalCMSTwigFunctions::imageExists('not-array'))->toBe(false);
	});

	// -------------------------
	// SVG Symbol Generation
	// -------------------------

	test('TotalCMSTwigFunctions → svgSymbol generates SVG use element', function (): void {
		$result = TotalCMSTwigFunctions::svgSymbol('icon-home');

		expect($result)->toBe('<svg><use href="#icon-home"></use></svg>');
	});

	test('TotalCMSTwigFunctions → svgSymbol escapes HTML in ID', function (): void {
		$result = TotalCMSTwigFunctions::svgSymbol('<script>alert("xss")</script>');

		expect($result)->not->toContain('<script>');
		expect($result)->toContain('&lt;script&gt;');
		expect($result)->toContain('&quot;xss&quot;');
		// Note: 'alert' text itself is preserved but within escaped context
	});

	test('TotalCMSTwigFunctions → svgSymbol handles special characters', function (): void {
		$result = TotalCMSTwigFunctions::svgSymbol('icon-with-"quotes"');

		expect($result)->toContain('&quot;quotes&quot;');
		expect($result)->not->toContain('"quotes"');
	});

	// -------------------------
	// Debug Utilities
	// -------------------------

	test('TotalCMSTwigFunctions → var_dump wraps output in pre tags', function (): void {
		$data   = ['test' => 'value', 'number' => 123];
		$result = TotalCMSTwigFunctions::var_dump($data);

		expect($result)->toStartWith('<pre>');
		expect($result)->toEndWith('</pre>');
		expect($result)->toContain('array(2)');
		expect($result)->toContain('test');
		expect($result)->toContain('value');
	});

	test('TotalCMSTwigFunctions → print_r formats array readably', function (): void {
		$data   = ['name' => 'John', 'age' => 30];
		$result = TotalCMSTwigFunctions::print_r($data);

		expect($result)->toStartWith('<pre>');
		expect($result)->toEndWith('</pre>');
		expect($result)->toContain('Array');
		expect($result)->toContain('[name] => John');
		expect($result)->toContain('[age] => 30');
	});

	test('TotalCMSTwigFunctions → json_pretty formats JSON with indentation', function (): void {
		$data   = ['users' => [['name' => 'Alice', 'role' => 'admin'], ['name' => 'Bob', 'role' => 'user']]];
		$result = TotalCMSTwigFunctions::json_pretty($data);

		expect($result)->toContain('{');
		expect($result)->toContain('"users"');
		expect($result)->toContain('"name": "Alice"');
		expect($result)->toContain('"role": "admin"');
		expect($result)->toContain('    '); // Has indentation
	});

	test('TotalCMSTwigFunctions → json_pretty handles encoding failures', function (): void {
		// Create a resource that cannot be JSON encoded
		$resource = fopen('php://memory', 'r');
		$result   = TotalCMSTwigFunctions::json_pretty($resource);
		fclose($resource);

		expect($result)->toBe('');
	});

	// -------------------------
	// Embed Functions (Delegation)
	// -------------------------

	test('TotalCMSTwigFunctions → embed delegates to EmbedBuilder', function (): void {
		$url    = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
		$result = TotalCMSTwigFunctions::embed($url);

		expect($result)->toBeString();
		// The actual output depends on EmbedBuilder implementation
		// We just verify it returns a string and doesn't crash
	});

	test('TotalCMSTwigFunctions → embed passes options to EmbedBuilder', function (): void {
		$url     = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
		$options = ['width' => 800, 'height' => 450];
		$result  = TotalCMSTwigFunctions::embed($url, $options);

		expect($result)->toBeString();
	});

	test('TotalCMSTwigFunctions → youtube delegates to EmbedBuilder', function (): void {
		$url    = 'https://www.youtube.com/watch?v=test';
		$result = TotalCMSTwigFunctions::youtube($url);

		expect($result)->toBeString();
	});

	test('TotalCMSTwigFunctions → vimeo delegates to EmbedBuilder', function (): void {
		$url    = 'https://vimeo.com/123456789';
		$result = TotalCMSTwigFunctions::vimeo($url);

		expect($result)->toBeString();
	});

	test('TotalCMSTwigFunctions → video delegates to EmbedBuilder', function (): void {
		$url    = 'https://example.com/video.mp4';
		$result = TotalCMSTwigFunctions::video($url);

		expect($result)->toBeString();
	});

	test('TotalCMSTwigFunctions → audio delegates to EmbedBuilder', function (): void {
		$url    = 'https://example.com/audio.mp3';
		$result = TotalCMSTwigFunctions::audio($url);

		expect($result)->toBeString();
	});

	test('TotalCMSTwigFunctions → iframe delegates to EmbedBuilder', function (): void {
		$url    = 'https://example.com/embed';
		$result = TotalCMSTwigFunctions::iframe($url);

		expect($result)->toBeString();
	});

	// -------------------------
	// Edge Cases and Error Handling
	// -------------------------

	test('TotalCMSTwigFunctions → handles empty arrays in sorting functions', function (): void {
		expect(TotalCMSTwigFunctions::sortByKey([]))->toBe([]);
		expect(TotalCMSTwigFunctions::ksort([]))->toBe([]);
		expect(TotalCMSTwigFunctions::krsort([]))->toBe([]);
	});

	test('TotalCMSTwigFunctions → handles single element arrays', function (): void {
		$single = [['id' => 1, 'name' => 'Only']];
		$result = TotalCMSTwigFunctions::sortByKey($single);

		expect($result)->toBe($single);
	});

	test('TotalCMSTwigFunctions → debug functions handle various data types', function (): void {
		// Test with different data types
		$string  = TotalCMSTwigFunctions::var_dump('string');
		$number  = TotalCMSTwigFunctions::print_r(123);
		$boolean = TotalCMSTwigFunctions::json_pretty(true);
		$null    = TotalCMSTwigFunctions::var_dump(null);

		expect($string)->toContain('<pre>');
		expect($number)->toContain('<pre>');
		expect($boolean)->toBe('true');
		expect($null)->toContain('<pre>');
	});

	// -------------------------
	// String Search Functions
	// -------------------------

	test('TotalCMSTwigFunctions → contains finds substring', function (): void {
		expect(TotalCMSTwigFunctions::contains('Hello World', 'World'))->toBeTrue();
		expect(TotalCMSTwigFunctions::contains('Hello World', 'world'))->toBeFalse();
		expect(TotalCMSTwigFunctions::contains('Hello World', 'Hello'))->toBeTrue();
		expect(TotalCMSTwigFunctions::contains('Hello World', 'xyz'))->toBeFalse();
	});

	test('TotalCMSTwigFunctions → contains handles empty strings', function (): void {
		expect(TotalCMSTwigFunctions::contains('Hello', ''))->toBeTrue();
		expect(TotalCMSTwigFunctions::contains('', ''))->toBeTrue();
		expect(TotalCMSTwigFunctions::contains('', 'Hello'))->toBeFalse();
	});

	test('TotalCMSTwigFunctions → startsWith checks string prefix', function (): void {
		expect(TotalCMSTwigFunctions::startsWith('Hello World', 'Hello'))->toBeTrue();
		expect(TotalCMSTwigFunctions::startsWith('Hello World', 'World'))->toBeFalse();
		expect(TotalCMSTwigFunctions::startsWith('Hello World', 'hello'))->toBeFalse();
	});

	test('TotalCMSTwigFunctions → startsWith handles empty strings', function (): void {
		expect(TotalCMSTwigFunctions::startsWith('Hello', ''))->toBeTrue();
		expect(TotalCMSTwigFunctions::startsWith('', ''))->toBeTrue();
		expect(TotalCMSTwigFunctions::startsWith('', 'Hello'))->toBeFalse();
	});

	test('TotalCMSTwigFunctions → endsWith checks string suffix', function (): void {
		expect(TotalCMSTwigFunctions::endsWith('Hello World', 'World'))->toBeTrue();
		expect(TotalCMSTwigFunctions::endsWith('Hello World', 'Hello'))->toBeFalse();
		expect(TotalCMSTwigFunctions::endsWith('Hello World', 'world'))->toBeFalse();
	});

	test('TotalCMSTwigFunctions → endsWith handles empty strings', function (): void {
		expect(TotalCMSTwigFunctions::endsWith('Hello', ''))->toBeTrue();
		expect(TotalCMSTwigFunctions::endsWith('', ''))->toBeTrue();
		expect(TotalCMSTwigFunctions::endsWith('', 'Hello'))->toBeFalse();
	});

	test('TotalCMSTwigFunctions → indexOf finds first occurrence', function (): void {
		expect(TotalCMSTwigFunctions::indexOf('Hello World', 'World'))->toBe(6);
		expect(TotalCMSTwigFunctions::indexOf('Hello World', 'o'))->toBe(4);
		expect(TotalCMSTwigFunctions::indexOf('Hello World', 'xyz'))->toBeFalse();
	});

	test('TotalCMSTwigFunctions → indexOf supports offset', function (): void {
		expect(TotalCMSTwigFunctions::indexOf('Hello World', 'o', 5))->toBe(7);
	});

	test('TotalCMSTwigFunctions → lastIndexOf finds last occurrence', function (): void {
		expect(TotalCMSTwigFunctions::lastIndexOf('Hello World', 'o'))->toBe(7);
		expect(TotalCMSTwigFunctions::lastIndexOf('Hello World', 'l'))->toBe(9);
		expect(TotalCMSTwigFunctions::lastIndexOf('Hello World', 'xyz'))->toBeFalse();
	});

	test('TotalCMSTwigFunctions → lastIndexOf supports offset', function (): void {
		expect(TotalCMSTwigFunctions::lastIndexOf('Hello World', 'o', 0))->toBe(7);
	});

	// -------------------------
	// Utility Functions
	// -------------------------

	test('TotalCMSTwigFunctions → buildQuery creates query string from array', function (): void {
		$result = TotalCMSTwigFunctions::buildQuery(['foo' => 'bar', 'baz' => 'qux']);
		expect($result)->toBe('foo=bar&baz=qux');
	});

	test('TotalCMSTwigFunctions → buildQuery encodes special characters', function (): void {
		$result = TotalCMSTwigFunctions::buildQuery(['q' => 'hello world', 'tag' => 'a&b']);
		expect($result)->toContain('q=hello+world');
		expect($result)->toContain('tag=a%26b');
	});

	test('TotalCMSTwigFunctions → buildQuery handles empty array', function (): void {
		expect(TotalCMSTwigFunctions::buildQuery([]))->toBe('');
	});

	test('TotalCMSTwigFunctions → buildQuery handles nested arrays', function (): void {
		$result = TotalCMSTwigFunctions::buildQuery(['filters' => ['status' => 'active']]);
		expect($result)->toContain('filters');
		expect($result)->toContain('active');
	});

	test('TotalCMSTwigFunctions → parseJson decodes valid JSON', function (): void {
		$result = TotalCMSTwigFunctions::parseJson('{"name":"Alice","age":30}');
		expect($result)->toBe(['name' => 'Alice', 'age' => 30]);
	});

	test('TotalCMSTwigFunctions → parseJson returns null for invalid JSON', function (): void {
		expect(TotalCMSTwigFunctions::parseJson('not valid json'))->toBeNull();
		expect(TotalCMSTwigFunctions::parseJson(''))->toBeNull();
	});

	test('TotalCMSTwigFunctions → parseJson returns null for non-array JSON', function (): void {
		expect(TotalCMSTwigFunctions::parseJson('"just a string"'))->toBeNull();
		expect(TotalCMSTwigFunctions::parseJson('42'))->toBeNull();
		expect(TotalCMSTwigFunctions::parseJson('true'))->toBeNull();
	});

	test('TotalCMSTwigFunctions → parseJson handles nested structures', function (): void {
		$json   = '{"users":[{"id":1,"name":"Alice"},{"id":2,"name":"Bob"}]}';
		$result = TotalCMSTwigFunctions::parseJson($json);
		expect($result)->toHaveKey('users');
		expect($result['users'])->toHaveCount(2);
	});

	test('TotalCMSTwigFunctions → typeof returns correct type strings', function (): void {
		expect(TotalCMSTwigFunctions::typeof('hello'))->toBe('string');
		expect(TotalCMSTwigFunctions::typeof(42))->toBe('integer');
		expect(TotalCMSTwigFunctions::typeof(3.14))->toBe('double');
		expect(TotalCMSTwigFunctions::typeof(true))->toBe('boolean');
		expect(TotalCMSTwigFunctions::typeof([]))->toBe('array');
		expect(TotalCMSTwigFunctions::typeof(null))->toBe('NULL');
	});
});
