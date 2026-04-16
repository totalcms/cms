<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Data;

use TotalCMS\Domain\Property\Data\SlugData;

describe('SlugData', function (): void {
	test('creates slug data with simple string', function (): void {
		$slug = new SlugData('hello-world');

		expect($slug->slug)->toBe('hello-world');
		expect($slug->settings)->toBe([]);
	});

	test('creates slug data with settings', function (): void {
		$settings = ['maxLength' => 50, 'required' => true];
		$slug     = new SlugData('hello-world', $settings);

		expect($slug->slug)->toBe('hello-world');
		expect($slug->settings)->toBe($settings);
	});

	test('slugifies text with spaces', function (): void {
		$slug = new SlugData('Hello World');

		expect($slug->slug)->toBe('hello-world');
	});

	test('slugifies text with special characters', function (): void {
		$slug = new SlugData('Hello & World!');

		expect($slug->slug)->toBe('hello-world');
	});

	test('slugifies text with mixed case', function (): void {
		$slug = new SlugData('CamelCase Text');

		expect($slug->slug)->toBe('camelcase-text');
	});

	test('handles @ symbol with custom rule', function (): void {
		$slug = new SlugData('contact@example.com');

		expect($slug->slug)->toBe('contact-at-example-com');
	});

	test('removes consecutive special characters', function (): void {
		$slug = new SlugData('hello---world!!!');

		expect($slug->slug)->toBe('hello-world');
	});

	test('handles numbers in slug', function (): void {
		$slug = new SlugData('Article 123');

		expect($slug->slug)->toBe('article-123');
	});

	test('handles underscores', function (): void {
		$slug = new SlugData('hello_world_test');

		expect($slug->slug)->toBe('hello_world_test');
	});

	test('handles empty string', function (): void {
		$slug = new SlugData('');

		expect($slug->slug)->toBe('');
	});

	test('handles already valid slug', function (): void {
		$slug = new SlugData('already-valid-slug');

		expect($slug->slug)->toBe('already-valid-slug');
	});

	test('transform returns slug as string', function (): void {
		$slug = new SlugData('Hello World');

		expect($slug->transform())->toBe('hello-world');
	});

	test('toString returns slug', function (): void {
		$slug = new SlugData('Test Title');

		expect((string)$slug)->toBe('test-title');
	});

	test('static slugify method works independently', function (): void {
		expect(SlugData::slugify('Hello World'))->toBe('hello-world');
		expect(SlugData::slugify('Test & Example'))->toBe('test-example');
		expect(SlugData::slugify('contact@email.com'))->toBe('contact-at-email-com');
	});

	test('handles unicode characters', function (): void {
		$slug = new SlugData('Café & Résumé');

		expect($slug->slug)->toBe('cafe-resume');
	});

	test('handles multiple spaces and tabs', function (): void {
		$slug = new SlugData("Hello\t\t  World   Test");

		expect($slug->slug)->toBe('hello-world-test');
	});

	test('handles punctuation marks', function (): void {
		$testCases = [
			['Hello, World!', 'hello-world'],
			['Test: Example', 'test-example'],
			['Question?', 'question'],
			['Multiple...Dots', 'multiple-dots'],
			['Parentheses (test)', 'parentheses-test'],
			['Brackets [test]', 'brackets-test'],
			['Curly {test}', 'curly-test'],
		];

		foreach ($testCases as [$input, $expected]) {
			$slug = new SlugData($input);
			expect($slug->slug)->toBe($expected);
		}
	});

	test('handles long text', function (): void {
		$longText = 'This is a very long title that should be properly slugified with multiple words and characters';
		$slug     = new SlugData($longText);

		expect($slug->slug)->toBe('this-is-a-very-long-title-that-should-be-properly-slugified-with-multiple-words-and-characters');
	});

	test('preserves valid characters', function (): void {
		$validChars = 'abc123DEF_test-slug';
		$slug       = new SlugData($validChars);

		expect($slug->slug)->toBe('abc123def_test-slug');
	});

	test('handles edge cases', function (): void {
		$edgeCases = [
			['   ', ''],
			['---', ''],
			['!!!', ''],
			['123', '123'],
			['ABC', 'abc'],
			['_', '_'],
			['-', ''],
		];

		foreach ($edgeCases as [$input, $expected]) {
			$slug = new SlugData($input);
			expect($slug->slug)->toBe($expected);
		}
	});

	test('handles email addresses', function (): void {
		$slug = new SlugData('test@example.com');

		expect($slug->slug)->toBe('test-at-example-com');
	});

	test('handles URLs', function (): void {
		$slug = new SlugData('https://example.com/page');

		expect($slug->slug)->toBe('https-example-com-page');
	});

	test('settings are preserved through operations', function (): void {
		$settings = ['maxLength' => 100, 'separator' => '-', 'lowercase' => true];
		$slug     = new SlugData('Test Title', $settings);

		expect($slug->settings)->toBe($settings);

		// Settings should persist through transformations
		$slug->transform();
		expect($slug->settings)->toBe($settings);
	});

	test('consistent output between constructor and static method', function (): void {
		$testInputs = [
			'Hello World',
			'Test & Example',
			'contact@email.com',
			'Multiple   Spaces',
			'Special!@#$%Characters',
		];

		foreach ($testInputs as $input) {
			$slugData   = new SlugData($input);
			$staticSlug = SlugData::slugify($input);

			expect($slugData->slug)->toBe($staticSlug);
		}
	});
});
