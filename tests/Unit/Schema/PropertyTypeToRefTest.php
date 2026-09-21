<?php

declare(strict_types=1);

use TotalCMS\Domain\Schema\Service\SchemaSaver;

/**
 * Regression cover for Sentry TOTAL-CMS-PY: a schema whose property carried a
 * JSON Schema type LIST (`"type": ["string","null"]`) crashed `tcms schema:lint`
 * with "array_key_exists(): Argument #1 ($key) must be a valid array offset
 * type" — the linter died on exactly the malformed input it exists to report.
 */
it('converts a string property type to its $ref', function (): void {
	$result = SchemaSaver::propertyTypeToRef(['cover' => ['type' => 'image']]);

	expect($result['cover'])->toHaveKey('$ref');
	expect($result['cover'])->not->toHaveKey('type');
});

it('leaves a type list alone instead of crashing', function (): void {
	$result = SchemaSaver::propertyTypeToRef(['title' => ['type' => ['string', 'null']]]);

	expect($result['title']['type'])->toBe(['string', 'null']);
	expect($result['title'])->not->toHaveKey('$ref');
});

it('leaves a property with no type alone', function (): void {
	$result = SchemaSaver::propertyTypeToRef(['title' => ['description' => 'no type here']]);

	expect($result['title'])->toBe(['description' => 'no type here']);
});

it('leaves an unrecognized string type alone', function (): void {
	$result = SchemaSaver::propertyTypeToRef(['title' => ['type' => 'not-a-t3-type']]);

	expect($result['title']['type'])->toBe('not-a-t3-type');
	expect($result['title'])->not->toHaveKey('$ref');
});
