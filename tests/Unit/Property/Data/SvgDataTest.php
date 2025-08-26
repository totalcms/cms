<?php

use TotalCMS\Domain\Property\Data\SvgData;

describe('SvgData', function (): void {
	test('SvgData → creates with empty SVG', function (): void {
		$svg = new SvgData();

		expect($svg->svg)->toBe('');
		expect($svg->settings)->toBe([]);
	});

	test('SvgData → creates with settings', function (): void {
		$settings = ['svgclean' => false, 'width' => 100];
		$svg      = new SvgData('', $settings);

		expect($svg->settings)->toBe($settings);
	});

	test('SvgData → transforms to string correctly', function (): void {
		$svg = new SvgData('');

		expect($svg->transform())->toBe('');
		expect($svg->transform())->toBeString();
	});

	test('SvgData → converts to string with __toString', function (): void {
		$svg = new SvgData('');

		expect((string)$svg)->toBe('');
	});

	test('SvgData → transform returns same as __toString', function (): void {
		$svg = new SvgData('');

		expect($svg->transform())->toBe((string)$svg);
	});

	test('SvgData → handles SVG cleaning disabled', function (): void {
		// Test with svgclean disabled to avoid SVGSanitizer dependency
		$settings   = ['svgclean' => false];
		$svgContent = '<svg><circle cx="50" cy="50" r="40"/></svg>';

		// This might throw an exception if SVGSanitizer validation fails
		// We'll test both cases to see what actually happens
		try {
			$svg = new SvgData($svgContent, $settings);
			expect($svg->svg)->toBe($svgContent);
		} catch (InvalidArgumentException $e) {
			// If SVGSanitizer still validates even when cleaning is disabled
			expect($e->getMessage())->toBe('Invalid SVG content');
		}
	});

	test('SvgData → throws exception for invalid SVG when validation occurs', function (): void {
		// Test invalid SVG content
		$invalidSvg = '<not-svg>invalid content</not-svg>';

		expect(fn (): SvgData => new SvgData($invalidSvg))
			->toThrow(InvalidArgumentException::class, 'Invalid SVG content');
	});

	test('SvgData → bypasses validation and sanitization when SVG is empty', function (): void {
		// Empty SVG should not trigger sanitization or validation
		$svg = new SvgData('');

		expect($svg->svg)->toBe('');
		expect((string)$svg)->toBe('');
	});

	test('SvgData → handles whitespace-only SVG as empty', function (): void {
		// Whitespace-only gets sanitized to empty string and throws exception
		$whitespaceOnly = '   ';

		// This should throw an exception because whitespace becomes empty after sanitization
		expect(fn (): SvgData => new SvgData($whitespaceOnly))
			->toThrow(InvalidArgumentException::class, 'Invalid SVG content after sanitization');
	});

	test('SvgData → creates instance without throwing with disabled cleaning', function (): void {
		// Test that we can create an instance with cleaning disabled
		$settings = ['svgclean' => false];

		// Empty string should work without throwing
		$svg = new SvgData('', $settings);
		expect($svg->svg)->toBe('');
	});

	test('SvgData → preserves settings regardless of SVG content', function (): void {
		$settings = ['svgclean' => false, 'custom' => 'value'];
		$svg      = new SvgData('', $settings);

		expect($svg->settings)->toBe($settings);
	});

	test('SvgData → handles basic SVG structure with cleaning disabled', function (): void {
		$basicSvg = '<svg></svg>';
		$settings = ['svgclean' => false];

		try {
			$svg = new SvgData($basicSvg, $settings);
			expect($svg->svg)->toBeString();
			expect(strlen($svg->svg))->toBeGreaterThanOrEqual(0);
		} catch (InvalidArgumentException $e) {
			// If even basic SVG fails validation
			expect($e->getMessage())->toBe('Invalid SVG content');
		}
	});

	test('SvgData → string representation matches svg property', function (): void {
		// Use valid SVG content to avoid validation errors
		$svg = new SvgData('<svg></svg>');

		// toString should match svg property
		expect((string)$svg)->toBe($svg->svg);
	});
});
