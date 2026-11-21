<?php

declare(strict_types=1);

use TotalCMS\Domain\Property\Data\JsonData;

describe('JsonData', function (): void {
	test('an array is stored as it is', function (): void {
		expect((new JsonData(['a' => 1]))->transform())->toBe(['a' => 1])
			->and((new JsonData([1, 2]))->transform())->toBe([1, 2]);
	});

	test('a JSON string is decoded, and other text is kept', function (): void {
		expect((new JsonData('{"a":1}'))->transform())->toBe(['a' => 1])
			->and((new JsonData('not json'))->transform())->toBe('not json');
	});

	// The json property schema allows an object, an array, a string or the
	// empty string. null is none of them, so a blank json property used to
	// fail validation on every save that left it empty.
	test('a blank value is stored as an empty string, which the schema accepts', function (mixed $blank): void {
		expect((new JsonData($blank))->transform())->toBe('')
			->and((string)new JsonData($blank))->toBe('');
	})->with([null, '', '   ']);
});
