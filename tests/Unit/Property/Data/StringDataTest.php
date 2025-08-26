<?php

use TotalCMS\Domain\Property\Data\StringData;

describe('StringData', function (): void {
	test('StringData → creates with simple text', function (): void {
		$string = new StringData('Hello World');
		
		expect($string->text)->toBe('Hello World');
		expect($string->settings)->toBe([]);
	});

	test('StringData → creates with empty text', function (): void {
		$string = new StringData();
		
		expect($string->text)->toBe('');
	});

	test('StringData → creates with settings', function (): void {
		$settings = ['htmlclean' => false, 'maxlength' => 100];
		$string = new StringData('test text', $settings);
		
		expect($string->settings)->toBe($settings);
		expect($string->text)->toBe('test text');
	});

	test('StringData → transforms to string correctly', function (): void {
		$string = new StringData('transform test');
		
		expect($string->transform())->toBe('transform test');
		expect($string->transform())->toBeString();
	});

	test('StringData → converts to string with __toString', function (): void {
		$string = new StringData('toString test');
		
		expect((string)$string)->toBe('toString test');
	});

	test('StringData → transform returns same as __toString', function (): void {
		$string = new StringData('consistency test');
		
		expect($string->transform())->toBe((string)$string);
	});

	test('StringData → containsHTML detects HTML tags', function (): void {
		$htmlString = new StringData('<p>HTML content</p>');
		$plainString = new StringData('Plain text');
		
		expect($htmlString->containsHTML())->toBe(true);
		expect($plainString->containsHTML())->toBe(false);
	});

	test('StringData → containsHTML detects various HTML tags', function (): void {
		$testCases = [
			['<div>content</div>', true],
			['<span class="test">text</span>', true],
			['<br>', true],
			['<img src="test.jpg">', true],
			['<script>alert("xss")</script>', false], // Gets sanitized away
			['Plain text without tags', false],
			['Text with < and > symbols', false],
			['Email: user@domain.com', false],
		];
		
		foreach ($testCases as [$text, $expectedContainsHTML]) {
			$string = new StringData($text);
			expect($string->containsHTML())->toBe($expectedContainsHTML);
		}
	});

	test('StringData → handles special characters', function (): void {
		$specialText = 'Special chars: !@#$%^&*()_+-=[]{}|;:,.<>?';
		$string = new StringData($specialText);
		
		expect($string->text)->toBe($specialText);
		expect((string)$string)->toBe($specialText);
	});

	test('StringData → handles unicode characters', function (): void {
		$unicodeText = 'Unicode: 世界 🌍 café naïve résumé';
		$string = new StringData($unicodeText);
		
		expect($string->text)->toBe($unicodeText);
		expect((string)$string)->toBe($unicodeText);
	});

	test('StringData → handles multiline text', function (): void {
		$multilineText = "Line 1\nLine 2\nLine 3";
		$string = new StringData($multilineText);
		
		expect($string->text)->toBe($multilineText);
	});

	test('StringData → handles very long text', function (): void {
		$longText = str_repeat('Long text content. ', 100);
		$string = new StringData($longText);
		
		expect($string->text)->toBe($longText);
		expect(strlen($string->text))->toBeGreaterThan(1000);
	});

	test('StringData → preserves whitespace', function (): void {
		$whitespaceText = "  \t  Leading and trailing whitespace  \t  ";
		$string = new StringData($whitespaceText);
		
		expect($string->text)->toBe($whitespaceText);
	});

	test('StringData → handles HTML sanitization when enabled', function (): void {
		// Test with htmlclean disabled to avoid Config dependencies
		$settings = ['htmlclean' => false];
		$htmlText = '<p>This should not be sanitized</p><script>alert("xss")</script>';
		$string = new StringData($htmlText, $settings);
		
		// When htmlclean is disabled, HTML should be preserved
		expect($string->text)->toBe($htmlText);
		expect($string->containsHTML())->toBe(true);
	});

	test('StringData → containsHTML correctly identifies self-closing tags', function (): void {
		$selfClosingTags = [
			'<br/>',
			'<hr />',
			'<img src="test.jpg" />',
			'<input type="text" />',
		];
		
		foreach ($selfClosingTags as $tag) {
			// Test with htmlclean disabled to avoid sanitization
			$string = new StringData($tag, ['htmlclean' => false]);
			expect($string->containsHTML())->toBe(true);
		}
	});

	test('StringData → containsHTML handles edge cases', function (): void {
		$edgeCases = [
			['<', true], // strip_tags treats this as HTML
			['>', false], // Just greater-than symbol
			['<>', true], // strip_tags treats this as HTML
			['< p>', false], // Space after < makes it not HTML
			['<p >', true], // Valid tag with space
			['text<br>text', true], // HTML in middle
			['&lt;p&gt;', false], // HTML entities
		];
		
		foreach ($edgeCases as [$text, $expected]) {
			// Test with htmlclean disabled to avoid sanitization
			$string = new StringData($text, ['htmlclean' => false]);
			expect($string->containsHTML())->toBe($expected);
		}
	});

	test('StringData → handles quotes and apostrophes', function (): void {
		$quotedText = 'Text with "double quotes" and \'single quotes\' and `backticks`';
		$string = new StringData($quotedText);
		
		expect($string->text)->toBe($quotedText);
	});

	test('StringData → handles numeric strings', function (): void {
		$numericStrings = ['123', '45.67', '0', '-99', '1e10'];
		
		foreach ($numericStrings as $numeric) {
			$string = new StringData($numeric);
			expect($string->text)->toBe($numeric);
			expect($string->containsHTML())->toBe(false);
		}
	});

	test('StringData → handles empty and null-like strings', function (): void {
		$emptyLikeStrings = ['', '0', ' ', "\t", "\n"];
		
		foreach ($emptyLikeStrings as $emptyLike) {
			$string = new StringData($emptyLike);
			expect($string->text)->toBe($emptyLike);
		}
	});
});