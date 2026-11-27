<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Schema\Data\SchemaData;
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
	expect($this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('things', 'c')->toArray())->toHaveKey('author', 'Me');
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

test('a parent the form skips for any read error is skipped by the save too', function (): void {
	// Valid JSON of the wrong shape: the serializer refuses it with its own
	// exception, neither a DomainException nor a corrupt-file one. The form's
	// walk (SchemaFetcher) catches every Exception, so the save must as well.
	($this->writeSchema)('misshapen-parent', ['properties' => 'not an object', 'required' => 'tag']);
	($this->writeSchema)('child', [
		'inheritFrom' => ['misshapen-parent'],
		'properties'  => ['id' => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id']],
		'required'    => ['id'],
	]);
	($this->collection)('child');

	expect(array_keys($this->c->get(SchemaFetcher::class)->fetchSchema('child')->properties))->toBe(['id']);
	($this->save)(['id' => 'ok']);
	expect(($this->refused)(['id' => 'ok2']))->toBeFalse();
});

test('saving a parent clears its child\'s flattened schema under the versioned key', function (): void {
	$saver  = $this->c->get(SchemaSaver::class);
	$parent = ['id' => 'parent', 'type' => 'object', 'properties' => ['tag' => ['type' => 'string', 'field' => 'text', 'label' => 'Tag', 'help' => 'A tag.']]];
	$saver->saveSchema($parent);
	$saver->saveSchema(['id' => 'child', 'type' => 'object', 'inheritFrom' => ['parent'], 'properties' => []]);

	$cache = $this->c->get(CacheManager::class);
	$key   = SchemaData::flattenedCacheKey('child');
	$this->c->get(SchemaFetcher::class)->fetchSchema('child');

	expect($key)->toBe('schema_flattened:v2:child')
		->and($cache->getComputedData($key))->not->toBeNull();

	$saver->saveSchema($parent);
	expect($cache->getComputedData($key))->toBeNull();
});

test('a user on a schema that inherits auth still saves, signs in, and saves again unchanged', function (): void {
	$this->c->get(SchemaSaver::class)->saveSchema([
		'id'          => 'member',
		'type'        => 'object',
		'inheritFrom' => ['auth'],
		'properties'  => ['nickname' => ['type' => 'string', 'field' => 'text', 'label' => 'Nickname', 'help' => 'What friends call them.']],
	]);
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'members', 'name' => 'Members', 'schema' => 'member']);
	$this->c->get(ObjectSaver::class)->saveObject('members', [
		'id'       => 'ann',
		'email'    => 'ann@example.com',
		'name'     => 'Ann',
		'password' => 'correct horse battery',
		'active'   => true,
		'nickname' => 'Annie',
	]);

	$user = $this->c->get(LoginService::class)->authenticate('ann@example.com', 'correct horse battery', 'members');
	expect($user['id'] ?? null)->toBe('ann');

	$stored = $this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('members', 'ann')->toArray();
	$this->c->get(ObjectUpdater::class)->updateObject('members', 'ann', $stored);

	expect($this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('members', 'ann')->toArray())
		->toHaveKey('nickname', 'Annie')
		->toHaveKey('email', 'ann@example.com');
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
