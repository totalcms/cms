<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLinter;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

/*
 * An object saved into a collection whose schema inherits is checked against
 * every inherited property and `required`, not only the schema's own.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();

	/** Write a custom schema file as it is on disk, bypassing the saver's checks. */
	$this->writeSchema = function (string $id, string|array $contents): void {
		$dir = $this->c->get(SchemaRepository::class)->getCustomSchemaDir();
		is_dir($dir) || mkdir($dir, 0755, true);
		file_put_contents("{$dir}/{$id}.json", is_array($contents) ? json_encode($contents + ['id' => $id, 'type' => 'object']) : $contents);
	};

	$this->collection = function (string $schema): void {
		$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'things', 'name' => 'Things', 'schema' => $schema]);
	};

	$this->save = fn (array $object) => $this->c->get(ObjectSaver::class)->saveObject('things', $object);

	/** Whether saving the object is refused. */
	$this->refused = function (array $object, string $collection = 'things'): bool {
		try {
			$this->c->get(ObjectSaver::class)->saveObject($collection, $object);
		} catch (\Throwable) {
			return true;
		}

		return false;
	};
});

test('a product missing its title is rejected, and a whole one saves', function (): void {
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'things', 'name' => 'Things', 'schema' => 'commerce-product']);

	// A price left out is filled with its default, so only the title can be missing.
	expect(($this->refused)(['id' => 'a', 'price' => 5]))->toBeTrue()
		->and(($this->refused)(['id' => 'b', 'title' => '', 'price' => 5]))->toBeTrue();

	($this->save)(['id' => 'd', 'title' => 'D', 'price' => 5]);
	expect(true)->toBeTrue();
});

test('an object is checked against the fields its parents define', function (): void {
	$this->c->get(SchemaSaver::class)->saveSchema([
		'id'          => 'book',
		'type'        => 'object',
		'inheritFrom' => ['commerce-product-base', 'commerce-product-digital'],
		'properties'  => ['author' => ['type' => 'string', 'field' => 'text', 'label' => 'Author', 'help' => 'Who wrote it.']],
	]);
	($this->collection)('book');

	expect(($this->refused)(['id' => 'a', 'title' => '', 'price' => 5]))->toBeTrue()
		->and(($this->refused)(['id' => 'b', 'price' => 5]))->toBeTrue();

	($this->save)(['id' => 'c', 'title' => 'C', 'price' => 5, 'author' => 'Me']);
	expect($this->c->get(\TotalCMS\Domain\Object\Service\ObjectFetcher::class)->fetchObjectFromDisk('things', 'c')->toArray())->toHaveKey('author', 'Me');
});

test('a loop in inheritFrom and a chain deeper than the cap neither hang nor throw', function (): void {
	($this->writeSchema)('ring-a', ['inheritFrom' => ['ring-b'], 'properties' => ['id' => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id']], 'required' => ['id']]);
	($this->writeSchema)('ring-b', ['inheritFrom' => ['ring-a'], 'properties' => ['note' => ['type' => 'string', 'field' => 'text', 'label' => 'Note']]]);
	($this->collection)('ring-a');
	($this->save)(['id' => 'x', 'note' => 'hello']);

	foreach (range(1, 8) as $n) {
		($this->writeSchema)("deep-{$n}", [
			'inheritFrom' => $n < 8 ? ['deep-' . ($n + 1)] : [],
			'properties'  => $n === 1
				? ['id' => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id']]
				: ["f{$n}" => ['type' => 'string', 'field' => 'text', 'label' => "F{$n}"]],
			'required'    => $n === 1 ? ['id'] : [],
		]);
	}
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'deepthings', 'name' => 'Deep', 'schema' => 'deep-1']);
	$this->c->get(ObjectSaver::class)->saveObject('deepthings', ['id' => 'y']);

	expect(true)->toBeTrue();
});

test('a parent whose file is corrupt is skipped, and the rest still validates', function (): void {
	($this->writeSchema)('broken-parent', '{ this is not json');
	($this->writeSchema)('sound-parent', ['properties' => ['tag' => ['type' => 'string', 'field' => 'text', 'label' => 'Tag']], 'required' => ['tag']]);
	($this->writeSchema)('child', [
		'inheritFrom' => ['broken-parent', 'sound-parent'],
		'properties'  => ['id' => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id']],
		'required'    => ['id'],
	]);
	($this->collection)('child');

	($this->save)(['id' => 'ok', 'tag' => 'x']);
	expect(($this->refused)(['id' => 'bad', 'tag' => '']))->toBeTrue();
});

test('a schema made only of parts can be saved and linted, with or without a properties key', function (array $extra): void {
	$this->c->get(SchemaSaver::class)->saveSchema(['id' => 'parts-only', 'type' => 'object', 'inheritFrom' => ['commerce-product-base', 'commerce-product-digital']] + $extra);

	expect($this->c->get(SchemaFetcher::class)->fetchSchema('parts-only')->properties)->toHaveKeys(['title', 'price', 'download'])
		->and($this->c->get(SchemaLinter::class)->lint('parts-only')['errors'])->toBe([]);

	($this->collection)('parts-only');
	($this->save)(['id' => 'p', 'title' => 'P', 'price' => 1]);
	expect(($this->refused)(['id' => 'q', 'price' => 1]))->toBeTrue();
})->with([
	'properties absent'  => [[]],
	'properties is {}'   => [['properties' => []]],
]);

test('an object saves into a collection whose parts-only schema names only missing parents', function (): void {
	($this->writeSchema)('orphan', ['inheritFrom' => ['ghost', 'phantom'], 'properties' => []]);
	($this->collection)('orphan');

	($this->save)(['id' => 'o']);
	expect(true)->toBeTrue();
});
