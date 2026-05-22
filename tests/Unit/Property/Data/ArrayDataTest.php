<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Data;

use TotalCMS\Domain\Property\Data\ArrayData;

/**
 * Coverage for the dual-shape input parsing in ArrayData:
 *  - PHP arrays pass through
 *  - JSON-array strings (leading `[`) decode as JSON
 *  - All other strings CSV-explode on comma
 *
 * The JSON branch exists because fields like the auth `passkeys` list store
 * structured rows. The form builder round-trips the current value as a
 * JSON-encoded hidden input; without the JSON branch, `explode(',', ...)`
 * would split every comma in the JSON and shred the data.
 */
describe('ArrayData', function (): void {

	describe('PHP array input', function (): void {
		test('list of strings passes through unchanged', function (): void {
			$data = new ArrayData(['red', 'green', 'blue']);

			expect($data->data)->toBe(['red', 'green', 'blue']);
		});

		test('list of associative arrays preserves structure', function (): void {
			// The shape passkeys uses — list of structured objects.
			$entries = [
				['credentialId' => 'abc', 'signCount' => 0],
				['credentialId' => 'def', 'signCount' => 5],
			];
			$data    = new ArrayData($entries);

			expect($data->data)->toBe($entries);
			expect($data->transform())->toBe($entries);
		});

		test('strips falsy entries and reindexes', function (): void {
			$data = new ArrayData(['a', '', 'b', null, 'c']);

			expect($data->data)->toBe(['a', 'b', 'c']);
		});
	});

	describe('string input — tag-list CSV shape', function (): void {
		test('comma-separated string explodes', function (): void {
			$data = new ArrayData('red,green,blue');

			expect($data->data)->toBe(['red', 'green', 'blue']);
		});

		test('empty string produces empty array', function (): void {
			$data = new ArrayData('');

			expect($data->data)->toBe([]);
		});

		test('single value with no commas is one entry', function (): void {
			$data = new ArrayData('singletag');

			expect($data->data)->toBe(['singletag']);
		});
	});

	describe('string input — JSON-array shape', function (): void {
		test('JSON list of objects decodes preserving each row', function (): void {
			// The exact regression scenario: form re-submits the current
			// passkeys JSON as a hidden input value.
			$json = '[{"credentialId":"abc","signCount":0},{"credentialId":"def","signCount":5}]';

			$data = new ArrayData($json);

			expect($data->data)->toBe([
				['credentialId' => 'abc', 'signCount' => 0],
				['credentialId' => 'def', 'signCount' => 5],
			]);
		});

		test('JSON list of strings still decodes as JSON (not CSV-split)', function (): void {
			// A literal JSON array of strings — could come from any caller
			// that JSON-encoded its data. The JSON branch wins on the `[`
			// prefix so the entries stay intact.
			$json = '["one","two,still-two","three"]';

			$data = new ArrayData($json);

			expect($data->data)->toBe(['one', 'two,still-two', 'three']);
		});

		test('leading whitespace before [ is tolerated', function (): void {
			$data = new ArrayData("  \n[\"a\",\"b\"]");

			expect($data->data)->toBe(['a', 'b']);
		});

		test('malformed JSON falls back to CSV explode', function (): void {
			// Defensive: a string that starts with [ but isn't valid JSON
			// shouldn't kill the property — fall through to the CSV path.
			$data = new ArrayData('[oops,bad,json');

			expect($data->data)->toBe(['[oops', 'bad', 'json']);
		});

		test('JSON object (not array) falls back to CSV explode', function (): void {
			// Anchored on `[`, so `{...}` input takes the CSV path.
			$data = new ArrayData('{"key":"value"}');

			expect($data->data)->toBe(['{"key":"value"}']);
		});
	});

	describe('__toString round-trip', function (): void {
		test('tag-list joins with commas (backwards-compatible)', function (): void {
			$data = new ArrayData(['red', 'green', 'blue']);

			expect((string)$data)->toBe('red,green,blue');
		});

		test('list of structured rows JSON-encodes for stable round-trip', function (): void {
			$entries = [['credentialId' => 'abc', 'signCount' => 0]];
			$data    = new ArrayData($entries);

			$serialized = (string)$data;

			// And the JSON output can be fed back into ArrayData without loss.
			$roundTrip = new ArrayData($serialized);
			expect($roundTrip->data)->toBe($entries);
		});

		test('empty data produces empty string', function (): void {
			$data = new ArrayData([]);

			expect((string)$data)->toBe('');
		});
	});
});
