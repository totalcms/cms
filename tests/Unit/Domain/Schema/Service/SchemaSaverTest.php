<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Service\SchemaSaver;

// --------------------------------------------------
// sanitizeRequiredAndIndex
// --------------------------------------------------

it('keeps valid required properties', function (): void {
	$data = [
		'properties' => ['id' => [], 'title' => [], 'body' => []],
		'required'   => ['id', 'title'],
		'index'      => ['id'],
	];

	$result = SchemaSaver::sanitizeRequiredAndIndex($data);
	expect($result['required'])->toBe(['id', 'title']);
	expect($result['index'])->toBe(['id']);
});

it('strips invalid required and index properties', function (): void {
	$data = [
		'properties' => ['id' => [], 'title' => []],
		'required'   => ['id', 'nonexistent'],
		'index'      => ['id', 'missing'],
	];

	$result = SchemaSaver::sanitizeRequiredAndIndex($data);
	expect($result['required'])->toBe(['id']);
	expect($result['index'])->toBe(['id']);
});

it('allows inherited properties in required and index', function (): void {
	$data = [
		'properties' => ['id' => [], 'title' => []],
		'required'   => ['id', 'title', 'parentField'],
		'index'      => ['id', 'parentField'],
	];

	$result = SchemaSaver::sanitizeRequiredAndIndex($data, ['parentField', 'otherParentField']);
	expect($result['required'])->toBe(['id', 'title', 'parentField']);
	expect($result['index'])->toBe(['id', 'parentField']);
});

it('still strips truly invalid properties when inherited properties are provided', function (): void {
	$data = [
		'properties' => ['id' => []],
		'required'   => ['id', 'parentField', 'bogus'],
		'index'      => ['id', 'bogus'],
	];

	$result = SchemaSaver::sanitizeRequiredAndIndex($data, ['parentField']);
	expect($result['required'])->toBe(['id', 'parentField']);
	expect($result['index'])->toBe(['id']);
});

it('handles empty inherited properties array', function (): void {
	$data = [
		'properties' => ['id' => [], 'title' => []],
		'required'   => ['id', 'missing'],
		'index'      => ['id'],
	];

	$result = SchemaSaver::sanitizeRequiredAndIndex($data, []);
	expect($result['required'])->toBe(['id']);
	expect($result['index'])->toBe(['id']);
});

it('returns unchanged data when no properties key', function (): void {
	$data   = ['id' => 'test'];
	$result = SchemaSaver::sanitizeRequiredAndIndex($data);
	expect($result)->toBe($data);
});

// --------------------------------------------------
// expandPatternAliases
//
// Operators reference the shared registry by the same name they already use
// in Twig (`patterns.version`), rather than pasting a literal regex full of
// backslashes into Extra Schema Definitions. Expansion happens once, at save,
// so the stored schema stays real JSON Schema for the validator, MCP schema
// exposure and exports.
// --------------------------------------------------

it('expands a pattern alias to the anchored regex', function (): void {
	$properties = ['release' => ['type' => 'string', 'field' => 'text', 'pattern' => 'patterns.version']];

	$result = SchemaSaver::expandPatternAliases($properties);

	expect($result['release']['pattern'])->toBe('^\d+\.\d+\.\d+$');
});

it('anchors the expansion, because JSON Schema pattern is a substring match', function (): void {
	// Unanchored, `\d+\.\d+\.\d+` accepts "junk-3.5.0-junk". The registry
	// stores patterns bare because an HTML pattern attribute anchors itself;
	// JSON Schema does not, so the alias has to add them.
	$result = SchemaSaver::expandPatternAliases(['v' => ['pattern' => 'patterns.version']]);

	expect($result['v']['pattern'])->toStartWith('^');
	expect($result['v']['pattern'])->toEndWith('$');
	expect(preg_match('/' . $result['v']['pattern'] . '/', 'junk-3.5.0-junk'))->toBe(0);
	expect(preg_match('/' . $result['v']['pattern'] . '/', '3.5.0'))->toBe(1);
});

it('expands the extended version alias', function (): void {
	$result = SchemaSaver::expandPatternAliases(['v' => ['pattern' => 'patterns.versionExtended']]);

	expect(preg_match('/' . $result['v']['pattern'] . '/', 'v3.5.1-rc.1'))->toBe(1);
	expect(preg_match('/' . $result['v']['pattern'] . '/', '3.5'))->toBe(0);
});

it('expands a nested alias by dotted path', function (): void {
	$result = SchemaSaver::expandPatternAliases(['zip' => ['pattern' => 'patterns.postCode.usa']]);

	expect(preg_match('/' . $result['zip']['pattern'] . '/', '90210'))->toBe(1);
	expect(preg_match('/' . $result['zip']['pattern'] . '/', 'ABCDE'))->toBe(0);
});

it('leaves a literal regex untouched — that is the unanchored escape hatch', function (): void {
	$properties = ['code' => ['pattern' => '^[A-Z]{3}$'], 'loose' => ['pattern' => '\d+']];

	$result = SchemaSaver::expandPatternAliases($properties);

	expect($result['code']['pattern'])->toBe('^[A-Z]{3}$');
	expect($result['loose']['pattern'])->toBe('\d+');
});

it('leaves properties without a pattern alone', function (): void {
	$properties = ['title' => ['type' => 'string', 'field' => 'text', 'label' => 'Title']];

	expect(SchemaSaver::expandPatternAliases($properties))->toBe($properties);
});

it('preserves the other keys on an expanded property', function (): void {
	$properties = ['release' => [
		'type'      => 'string',
		'field'     => 'text',
		'label'     => 'Release',
		'minLength' => 5,
		'pattern'   => 'patterns.version',
	]];

	$result = SchemaSaver::expandPatternAliases($properties);

	expect($result['release']['label'])->toBe('Release');
	expect($result['release']['minLength'])->toBe(5);
	expect($result['release']['field'])->toBe('text');
});

it('throws on an unknown alias rather than storing it as a literal regex', function (): void {
	// `patterns.verison` is a valid regex that matches nothing, so passing it
	// through would reject every value with no clue why. Fail in the editor.
	SchemaSaver::expandPatternAliases(['v' => ['pattern' => 'patterns.verison']]);
})->throws(\UnexpectedValueException::class, 'patterns.verison');

it('throws on an unknown nested alias', function (): void {
	SchemaSaver::expandPatternAliases(['z' => ['pattern' => 'patterns.postCode.narnia']]);
})->throws(\UnexpectedValueException::class, 'patterns.postCode.narnia');

it('throws when an alias names a map instead of a pattern', function (): void {
	// `patterns.postCode` is a bag of country patterns, not a pattern itself.
	SchemaSaver::expandPatternAliases(['z' => ['pattern' => 'patterns.postCode']]);
})->throws(\UnexpectedValueException::class, 'patterns.postCode');

it('expands aliases as part of saveSchema, not only when called directly', function (): void {
	// Guards the wiring: the transform has to be in the saveSchema pipeline
	// alongside applyDefaultTypes() and propertyTypeToRef().
	$source = file_get_contents(__DIR__ . '/../../../../../src/Domain/Schema/Service/SchemaSaver.php');

	expect($source)->toContain('self::expandPatternAliases($schemaData[\'properties\'])');
});
