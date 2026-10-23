<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLinter;
use TotalCMS\Domain\Schema\Service\SchemaValidator;

/**
 * @param array<string,mixed> $properties
 * @param array<string> $required
 * @param array<string> $index
 * @param array<string> $inheritFrom
 */
function makeSchema(
	string $id,
	array $properties,
	array $required = [],
	array $index = [],
	array $inheritFrom = [],
	string $description = 'A described schema',
): SchemaData {
	$schema              = new SchemaData();
	$schema->id          = $id;
	$schema->description = $description;
	$schema->properties  = $properties;
	$schema->required    = $required;
	$schema->index       = $index;
	$schema->inheritFrom = $inheritFrom;

	return $schema;
}

/** @return array<string,mixed> */
function idProperty(): array
{
	return [
		'field' => 'id',
		'label' => 'ID',
		'help'  => 'The unique identifier',
		'$ref'  => 'https://www.totalcms.co/schemas/properties/slug.json',
	];
}

/**
 * @param array<string,SchemaData> $store keyed by schema id; raw === flattened unless overridden
 * @param array<string,SchemaData> $flattened overrides for fetchSchema()
 */
function makeLinter(array $store, array $flattened = [], ?SchemaValidator $validator = null): SchemaLinter
{
	$fetcher = test()->createMock(SchemaFetcher::class);
	$fetcher->method('fetchRawSchema')->willReturnCallback(
		fn (string $id): SchemaData => $store[$id] ?? throw new \UnexpectedValueException("Schema not found: {$id}")
	);
	$fetcher->method('fetchSchema')->willReturnCallback(
		fn (string $id): SchemaData => $flattened[$id] ?? $store[$id] ?? throw new \UnexpectedValueException("Schema not found: {$id}")
	);
	$fetcher->method('schemaExists')->willReturnCallback(
		fn (string $id): bool => isset($store[$id])
	);

	if ($validator === null) {
		$validator = test()->createMock(SchemaValidator::class);
		$validator->method('validateSchema')->willReturn(true);
	}

	return new SchemaLinter($fetcher, $validator, new DeckCompatibilityChecker());
}

it('passes a clean schema with no findings', function (): void {
	$schema = makeSchema('clean', ['id' => idProperty()], ['id'], ['id']);
	$result = makeLinter(['clean' => $schema])->lint('clean');

	expect($result['errors'])->toBeEmpty();
	expect($result['warnings'])->toBeEmpty();
});

it('errors when no id property is defined', function (): void {
	$schema = makeSchema('noid', ['title' => ['type' => 'string', 'field' => 'text', 'help' => 'The title']]);
	$result = makeLinter(['noid' => $schema])->lint('noid');

	expect($result['errors'])->toContain("No 'id' property is defined (own or inherited). Every schema needs one.");
});

it('accepts an id property inherited from a parent', function (): void {
	$title     = ['type' => 'string', 'field' => 'text', 'help' => 'The title'];
	$child     = makeSchema('child', ['title' => $title], inheritFrom: ['parent']);
	$parent    = makeSchema('parent', ['id' => idProperty()]);
	$flattened = makeSchema('child', ['title' => $title, 'id' => idProperty()]);

	$result = makeLinter(['child' => $child, 'parent' => $parent], ['child' => $flattened])->lint('child');

	expect($result['errors'])->toBeEmpty();
});

it('errors when inheritFrom references a missing schema', function (): void {
	$schema = makeSchema('orphan', ['id' => idProperty()], inheritFrom: ['ghost']);
	$result = makeLinter(['orphan' => $schema])->lint('orphan');

	expect($result['errors'])->toContain("inheritFrom references missing schema 'ghost'.");
});

it('errors when required or index list undefined properties', function (): void {
	$schema = makeSchema('mislist', ['id' => idProperty()], ['id', 'phantom'], ['id', 'specter']);
	$result = makeLinter(['mislist' => $schema])->lint('mislist');

	expect($result['errors'])->toContain("required lists 'phantom', which is not a defined property.");
	expect($result['errors'])->toContain("index lists 'specter', which is not a defined property.");
});

it('errors when a deck references a missing child schema', function (): void {
	$schema = makeSchema('deckhost', [
		'id'   => idProperty(),
		'rows' => ['field' => 'deck', 'help' => 'Rows', '$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'schemaref' => 'https://www.totalcms.co/schemas/custom/ghost.json'],
	], ['id'], ['id']);
	$result = makeLinter(['deckhost' => $schema])->lint('deckhost');

	expect($result['errors'])->toContain("Property 'rows' references missing schema 'ghost'.");
});

it('errors when a deck child schema holds deck-incompatible properties', function (): void {
	$child = makeSchema('gallery-child', [
		'id'     => idProperty(),
		'photos' => ['field' => 'gallery', 'help' => 'Photos', '$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json'],
	], ['id'], ['id']);
	$host = makeSchema('deckhost', [
		'id'   => idProperty(),
		'rows' => ['field' => 'deck', 'help' => 'Rows', '$ref' => 'https://www.totalcms.co/schemas/properties/deck.json', 'schemaref' => 'gallery-child'],
	], ['id'], ['id']);

	$result = makeLinter(['deckhost' => $host, 'gallery-child' => $child])->lint('deckhost');

	expect($result['errors'])->toContain("Deck property 'rows' uses schema 'gallery-child' which contains deck-incompatible properties: photos.");
});

it('warns on missing property help and schema description', function (): void {
	$schema = makeSchema('quiet', [
		'id'    => idProperty(),
		'title' => ['type' => 'string', 'field' => 'text'],
	], ['id'], ['id'], description: '');
	$result = makeLinter(['quiet' => $schema])->lint('quiet');

	expect($result['errors'])->toBeEmpty();
	expect($result['warnings'])->toContain("Property 'title' has no help text — help feeds the MCP tool catalog AI agents read.");
	expect($result['warnings'])->toContain('Schema has no description.');
});

it('surfaces meta-schema validation failures as errors', function (): void {
	$validator = test()->createMock(SchemaValidator::class);
	$validator->method('validateSchema')->willThrowException(new \DomainException('Schema Validation Failed. (/properties) invalid'));

	$schema = makeSchema('invalid', ['id' => idProperty()], ['id'], ['id']);
	$result = makeLinter(['invalid' => $schema], validator: $validator)->lint('invalid');

	expect($result['errors'])->toContain('Schema Validation Failed. (/properties) invalid');
});

it('reports an unloadable schema as a single error', function (): void {
	$result = makeLinter([])->lint('missing');

	expect($result['errors'])->toHaveCount(1);
	expect($result['errors'][0])->toContain('Schema cannot be loaded');
});

/** @return array<string,mixed> */
function passwordProperty(): array
{
	return [
		'field' => 'password',
		'label' => 'Password',
		'help'  => 'The account password',
		'$ref'  => 'https://www.totalcms.co/schemas/properties/password.json',
	];
}

it('warns when index lists a password property', function (): void {
	$schema = makeSchema('members', ['id' => idProperty(), 'password' => passwordProperty()], ['id'], ['id', 'password']);
	$result = makeLinter(['members' => $schema])->lint('members');

	expect($result['errors'])->toBeEmpty();
	expect($result['warnings'])->toBe([
		"index lists password property 'password'. Password hashes are never indexed — remove it from index.",
	]);
});

it('warns when index lists an inherited password property', function (): void {
	$parent    = makeSchema('base-user', ['id' => idProperty(), 'password' => passwordProperty()], ['id'], ['id']);
	$raw       = makeSchema('members', ['id' => idProperty()], ['id'], ['id', 'password'], ['base-user']);
	$flattened = makeSchema('members', ['id' => idProperty(), 'password' => passwordProperty()], ['id'], ['id', 'password'], ['base-user']);

	$result = makeLinter(['members' => $raw, 'base-user' => $parent], ['members' => $flattened])->lint('members');

	expect($result['warnings'])->toContain(
		"index lists password property 'password'. Password hashes are never indexed — remove it from index."
	);
});

it('does not warn about a password property that is not indexed', function (): void {
	$schema = makeSchema('members', ['id' => idProperty(), 'password' => passwordProperty()], ['id'], ['id']);
	$result = makeLinter(['members' => $schema])->lint('members');

	expect($result['warnings'])->toBeEmpty();
});

// --- protected ---
//
// `"protected": true` makes a property admin-only; `"protected": {"value": X}`
// also forces X on every non-admin write. AuthFieldPolicy ignores any other
// shape at runtime so a typo cannot take the write path down — which means
// the linter is the only place the typo is ever reported.

it('accepts both protected shapes', function (): void {
	$schema = makeSchema('moderated', [
		'id'       => idProperty(),
		'featured' => ['type' => 'boolean', 'field' => 'toggle', 'help' => 'Admin-only flag', 'protected' => true],
		'draft'    => ['type' => 'boolean', 'field' => 'toggle', 'help' => 'Forced back on', 'protected' => ['value' => true]],
	], ['id'], ['id']);
	$result = makeLinter(['moderated' => $schema])->lint('moderated');

	expect($result['errors'])->toBeEmpty();
});

it('errors on a protected value that is neither true nor an object with value', function (): void {
	$schema = makeSchema('typo', [
		'id'     => idProperty(),
		'flag'   => ['type' => 'boolean', 'field' => 'toggle', 'help' => 'Flag', 'protected' => 'yes'],
		'off'    => ['type' => 'boolean', 'field' => 'toggle', 'help' => 'Off', 'protected' => false],
		'noval'  => ['type' => 'string', 'field' => 'input', 'help' => 'No value key', 'protected' => ['default' => 'x']],
	], ['id'], ['id']);
	$result = makeLinter(['typo' => $schema])->lint('typo');

	expect($result['errors'])->toContain("Property 'flag' has an invalid protected value. Use true, or an object with a value key.");
	expect($result['errors'])->toContain("Property 'off' has an invalid protected value. Use true, or an object with a value key.");
	expect($result['errors'])->toContain("Property 'noval' has an invalid protected value. Use true, or an object with a value key.");
});

it('warns about a field type neither core nor an enabled extension provides', function (): void {
	// A disabled extension's field type: the admin renders no editor for it,
	// and before the fix its field-type dropdown fell back to `code`.
	$schema = makeSchema('shots', [
		'id'    => idProperty(),
		'image' => ['field' => 'screenshot', 'help' => 'A captured screenshot', '$ref' => 'https://www.totalcms.co/schemas/properties/image.json'],
	]);
	$result = makeLinter(['shots' => $schema])->lint('shots');

	expect($result['warnings'])->toContain("Property 'image' uses field type 'screenshot', which neither core nor an enabled extension provides. The admin cannot render it; enable the extension that adds it, or change the field.");
});

it('warns when a field stores a different shape than its $ref', function (): void {
	$schema = makeSchema('mismatch', [
		'id'    => idProperty(),
		'image' => ['field' => 'code', 'help' => 'An image', '$ref' => 'https://www.totalcms.co/schemas/properties/image.json'],
	]);
	$result = makeLinter(['mismatch' => $schema])->lint('mismatch');

	expect($result['warnings'])->toContain("Property 'image' uses field 'code', which stores string, but its \$ref stores object. Pick the field that matches the \$ref.");
});

it('accepts a field that matches its $ref, or a $ref overridden by an explicit type', function (): void {
	$schema = makeSchema('matched', [
		'id'      => idProperty(),
		'image'   => ['field' => 'image', 'help' => 'An image', '$ref' => 'https://www.totalcms.co/schemas/properties/image.json'],
		'snippet' => ['field' => 'code', 'type' => 'string', 'help' => 'Code', '$ref' => 'https://www.totalcms.co/schemas/properties/json.json'],
	]);
	$result = makeLinter(['matched' => $schema])->lint('matched');

	expect($result['warnings'])->toBeEmpty();
});
