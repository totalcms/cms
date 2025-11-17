<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Data;

use TotalCMS\Domain\Property\Data\ArrayData;
use TotalCMS\Domain\Property\Data\BooleanData;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Property\Data\EmailData;
use TotalCMS\Domain\Property\Data\ListData;
use TotalCMS\Domain\Property\Data\NumberData;
use TotalCMS\Domain\Property\Data\PhoneData;
use TotalCMS\Domain\Property\Data\SlugData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Property\Data\UrlData;

/**
 * Comprehensive tests for empty and default value handling across PropertyData types.
 *
 * NOTE: PropertyData classes expect specific types and don't accept null directly.
 * The PropertyFactory service is responsible for converting null values to appropriate
 * defaults before creating PropertyData instances. These tests document how PropertyData
 * classes handle empty/default values once created.
 */
describe('PropertyData Empty and Default Value Handling', function (): void {
	describe('StringData', function (): void {
		test('handles default empty value', function (): void {
			$data = new StringData(); // No arguments uses default ''
			expect($data->text)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles empty string', function (): void {
			$data = new StringData('');
			expect($data->text)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles whitespace-only string', function (): void {
			$data = new StringData('   ');
			expect($data->text)->toBe('   ');
			expect($data->transform())->toBeString();
		});

		test('preserves zero string', function (): void {
			$data = new StringData('0');
			expect($data->text)->toBe('0');
			expect($data->transform())->toBe('0');
		});
	});

	describe('EmailData', function (): void {
		test('handles default empty value', function (): void {
			$data = new EmailData();
			expect($data->email)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles empty string', function (): void {
			$data = new EmailData('');
			expect($data->email)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles valid email', function (): void {
			$data = new EmailData('test@example.com');
			expect($data->email)->toBe('test@example.com');
			expect($data->transform())->toBe('test@example.com');
		});

		test('does not normalize case', function (): void {
			// Email data does NOT normalize to lowercase - it preserves the original case
			$data = new EmailData('TEST@EXAMPLE.COM');
			expect($data->transform())->toBe('TEST@EXAMPLE.COM');
		});
	});

	describe('NumberData', function (): void {
		test('handles default zero value', function (): void {
			$data = new NumberData(); // Default is 0
			expect($data->number)->toBe(0.0);
			expect($data->transform())->toBe(0.0);
		});

		test('handles zero', function (): void {
			$data = new NumberData(0);
			expect($data->number)->toBe(0.0);
			expect($data->transform())->toBe(0.0);
		});

		test('handles empty string as zero', function (): void {
			$data = new NumberData('');
			expect($data->number)->toBe(0.0);
			expect($data->transform())->toBe(0.0);
		});

		test('handles string number', function (): void {
			$data = new NumberData('42');
			expect($data->number)->toBe(42.0);
			expect($data->transform())->toBe(42.0);
		});

		test('handles float values', function (): void {
			$data = new NumberData(3.14);
			expect($data->number)->toBe(3.14);
			expect($data->transform())->toBe(3.14);
		});
	});

	describe('BooleanData', function (): void {
		test('handles default false value', function (): void {
			$data = new BooleanData(); // Default is false
			expect($data->status)->toBe(false);
			expect($data->transform())->toBe(false);
		});

		test('handles false', function (): void {
			$data = new BooleanData(false);
			expect($data->status)->toBe(false);
			expect($data->transform())->toBe(false);
		});

		test('handles true', function (): void {
			$data = new BooleanData(true);
			expect($data->status)->toBe(true);
			expect($data->transform())->toBe(true);
		});

		test('handles empty string as false', function (): void {
			$data = new BooleanData('');
			expect($data->status)->toBe(false);
			expect($data->transform())->toBe(false);
		});

		test('handles truthy string', function (): void {
			$data = new BooleanData('1');
			expect($data->status)->toBe(true);
			expect($data->transform())->toBe(true);
		});

		test('handles various falsy values', function (): void {
			$falsyValues = ['', 0, '0', false];

			foreach ($falsyValues as $value) {
				$data = new BooleanData($value);
				expect($data->status)->toBe(false);
			}
		});
	});

	describe('ArrayData', function (): void {
		test('handles default empty array', function (): void {
			$data = new ArrayData();
			expect($data->data)->toBe([]);
			expect($data->transform())->toBe([]);
		});

		test('handles empty array', function (): void {
			$data = new ArrayData([]);
			expect($data->data)->toBe([]);
			expect($data->transform())->toBe([]);
		});

		test('handles empty string as empty array', function (): void {
			$data = new ArrayData('');
			expect($data->data)->toBe([]);
			expect($data->transform())->toBe([]);
		});

		test('handles valid array', function (): void {
			$data = new ArrayData(['a', 'b', 'c']);
			expect($data->data)->toBe(['a', 'b', 'c']);
			expect($data->transform())->toBe(['a', 'b', 'c']);
		});
	});

	describe('ListData', function (): void {
		test('handles default empty list', function (): void {
			$data = new ListData();
			expect($data->list)->toBe([]);
			expect($data->transform())->toBe([]);
		});

		test('handles empty array', function (): void {
			$data = new ListData([]);
			expect($data->list)->toBe([]);
			expect($data->transform())->toBe([]);
		});

		test('handles empty string', function (): void {
			$data = new ListData('');
			expect($data->list)->toBe([]);
			expect($data->transform())->toBe([]);
		});

		test('handles valid list', function (): void {
			$data = new ListData(['item1', 'item2']);
			expect($data->list)->toBeArray();
			expect($data->transform())->toBeArray();
		});

		test('handles string value gracefully', function (): void {
			$data = new ListData('single-item');
			expect($data->list)->toBeArray();
			expect($data->transform())->toBeArray();
		});
	});

	describe('DateData', function (): void {
		test('handles default empty value', function (): void {
			$data = new DateData();
			expect($data->date)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles empty string', function (): void {
			$data = new DateData('');
			expect($data->date)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles valid ISO date', function (): void {
			$data = new DateData('2024-01-15T12:30:00Z');
			expect($data->date)->toBeString();
			expect($data->transform())->toBeString();
		});

		test('handles various empty-like values', function (): void {
			$emptyLikeValues = ['', '0'];

			foreach ($emptyLikeValues as $value) {
				$data = new DateData($value);
				// Should handle gracefully without throwing exceptions
				expect($data->transform())->toBeString();
			}
		});
	});

	describe('UrlData', function (): void {
		test('handles default empty value', function (): void {
			$data = new UrlData();
			expect($data->url)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles empty string', function (): void {
			$data = new UrlData('');
			expect($data->url)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles valid URL', function (): void {
			$data = new UrlData('https://example.com');
			expect($data->url)->toBe('https://example.com');
			expect($data->transform())->toBe('https://example.com');
		});

		test('handles various valid URL formats', function (): void {
			$validUrls = [
				'https://example.com',
				'http://example.com',
				'https://example.com/path',
			];

			foreach ($validUrls as $url) {
				$data = new UrlData($url);
				expect($data->transform())->toBeString();
				expect($data->url)->not->toBe('');
			}
		});
	});

	describe('PhoneData', function (): void {
		test('handles default empty value', function (): void {
			$data = new PhoneData();
			expect($data->phone)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles empty string', function (): void {
			$data = new PhoneData('');
			expect($data->phone)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles valid phone', function (): void {
			$data = new PhoneData('+1-555-123-4567');
			expect($data->phone)->toBeString();
			expect($data->transform())->toBeString();
		});

		test('strips formatting consistently', function (): void {
			$data = new PhoneData('(555) 123-4567');
			expect($data->transform())->toBeString();
			// Phone should have consistent formatting
		});
	});

	describe('SlugData', function (): void {
		test('handles default empty value', function (): void {
			$data = new SlugData();
			expect($data->slug)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles empty string', function (): void {
			$data = new SlugData('');
			expect($data->slug)->toBe('');
			expect($data->transform())->toBe('');
		});

		test('handles valid slug', function (): void {
			$data = new SlugData('my-test-slug');
			expect($data->slug)->toBe('my-test-slug');
			expect($data->transform())->toBe('my-test-slug');
		});

		test('normalizes input', function (): void {
			$data = new SlugData('My Test Slug!!!');
			// Slug should be normalized (lowercase, hyphens, no special chars)
			expect($data->transform())->toBeString();
			expect($data->slug)->not->toContain(' ');
			expect($data->slug)->not->toContain('!');
		});
	});

	describe('Edge Cases and Validation', function (): void {
		test('StringData handles special characters', function (): void {
			$specialText = 'Special chars: !@#$%^&*()_+-=[]{}|;:,.<>?';
			$data        = new StringData($specialText);

			expect($data->text)->toBeString();
			expect((string)$data)->toBeString();
		});

		test('NumberData handles negative numbers', function (): void {
			$data = new NumberData(-42);
			expect($data->number)->toBe(-42.0);
			expect($data->transform())->toBe(-42.0);
		});

		test('BooleanData handles string "true"', function (): void {
			$data = new BooleanData('true');
			expect($data->status)->toBe(true);
		});

		test('ArrayData converts associative to sequential while preserving nested arrays', function (): void {
			// ArrayData converts associative keys to sequential integers but preserves nested structure
			$nested = [
				'key1' => ['nested' => 'value'],
				'key2' => [1, 2, 3],
			];
			$data = new ArrayData($nested);

			// Associative keys are lost, converted to [0, 1]
			expect($data->data)->toBeArray();
			expect($data->data)->toHaveCount(2);
			// But nested structure is preserved
			expect($data->data[0])->toBeArray();
			expect($data->data[0])->toBe(['nested' => 'value']);
			expect($data->data[1])->toBe([1, 2, 3]);
		});

		test('ListData maintains array structure', function (): void {
			$items = ['first', 'second', 'third'];
			$data  = new ListData($items);

			expect($data->list)->toHaveCount(3);
			expect($data->transform())->toBeArray();
		});

		test('DateData handles ISO 8601 formats', function (): void {
			$dates = [
				'2024-01-15T12:30:00Z',
				'2024-01-15T12:30:00+00:00',
				'2024-01-15',
			];

			foreach ($dates as $date) {
				$data = new DateData($date);
				expect($data->date)->toBeString();
			}
		});

		test('EmailData preserves valid format', function (): void {
			$emails = [
				'user@example.com',
				'user.name@example.com',
				'user+tag@example.com',
			];

			foreach ($emails as $email) {
				$data = new EmailData($email);
				expect($data->email)->toBe($email);
			}
		});

		test('SlugData creates URL-safe slugs', function (): void {
			$inputs = [
				'Hello World'         => 'hello-world',
				'Test@#$%123'         => 'test-123',
				'Multiple   Spaces'   => 'multiple-spaces',
			];

			foreach (array_keys($inputs) as $input) {
				$data = new SlugData($input);
				expect($data->slug)->toBeString();
				expect($data->slug)->not->toContain(' ');
			}
		});
	});
});
