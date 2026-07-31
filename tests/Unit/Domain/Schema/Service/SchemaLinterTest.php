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
