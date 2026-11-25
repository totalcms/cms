<?php

/**
 * The schema select of the collection form left out schemas that extensions
 * ship. Editing a collection on one showed the first schema in the list, and
 * saving wrote that schema to the collection.
 */

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->container = bootstrap()->getContainer();

	$this->container->get(SchemaSaver::class)->saveSchema(['id' => 'accounting-enquiry', 'type' => 'object', 'properties' => [
		'id' => ['$ref' => 'https://www.totalcms.co/schemas/properties/slug.json', 'field' => 'id'],
	], 'required' => ['id'], 'index' => ['id']]);

	// Two schemas, so the list is not in order by accident.
	$this->dir = sys_get_temp_dir() . '/tcms-ext-schemas-' . uniqid();
	mkdir($this->dir);
	$schema = json_decode((string)file_get_contents(SchemaRepository::defaultSchemaDir() . 'text.json'), true);
	foreach (['relay-queue-booking', 'relay-queue-archive'] as $id) {
		file_put_contents("{$this->dir}/{$id}.json", json_encode(['id' => $id] + $schema));
	}
	$this->container->get(SchemaRepository::class)->registerExtensionSchemaDir($this->dir);

	/** @return array{selected: list<string>, groups: array<string,list<string>>} */
	$this->schemaSelect = function (string $collection): array {
		$options = $collection === '' ? [] : ['id' => $collection];
		$html    = $this->container->get(TotalFormFactory::class)->collection($options);
		preg_match('/<select[^>]*name="schema".*?<\/select>/s', $html, $select);

		$dom = new DOMDocument();
		$dom->loadHTML($select[0], LIBXML_NOERROR);
		$selected = [];
		$groups   = [];
		foreach ($dom->getElementsByTagName('option') as $option) {
			$parent           = $option->parentNode;
			$group            = $parent instanceof DOMElement ? $parent->getAttribute('label') : '';
			$groups[$group][] = $option->getAttribute('value');
			if ($option->hasAttribute('selected')) {
				$selected[] = $option->getAttribute('value');
			}
		}

		return ['selected' => $selected, 'groups' => $groups];
	};
});

afterEach(function (): void {
	recursiveDelete($this->dir);
});

it('selects the extension schema of the collection being edited', function (): void {
	$this->container->get(CollectionSaver::class)->saveCollection(['id' => 'bookings', 'name' => 'Bookings', 'schema' => 'relay-queue-booking']);

	expect(($this->schemaSelect)('bookings')['selected'])->toBe(['relay-queue-booking']);
});

it('offers extension schemas to a new collection, each schema once', function (): void {
	$groups = ($this->schemaSelect)('')['groups'];
	$all    = array_merge(...array_values($groups));

	$sorted = $groups['Extension Schemas'] ?? [];
	sort($sorted);

	expect($sorted)->toContain('relay-queue-archive', 'relay-queue-booking')
		->and($groups['Extension Schemas'])->toBe($sorted)
		->and($groups['Custom Schemas'])->toContain('accounting-enquiry')
		->and($groups['Reserved Schemas'])->toContain('text')->not->toContain('relay-queue-booking')
		->and($all)->toBe(array_values(array_unique($all)));
});

// `collection` is never offered: it stands in for any schema the lists leave
// out, such as one the license's edition does not include.
it('keeps the stored schema when no list has it', function (): void {
	$this->container->get(CollectionSaver::class)->saveCollection(['id' => 'odd', 'name' => 'Odd', 'schema' => 'collection']);

	expect(($this->schemaSelect)('odd')['selected'])->toBe(['collection']);
});

// BundleChecker expects every schema in this list to be a file of the bundle:
// an extension's schema there would fail the check, and then every write.
it('keeps extension schemas out of the reserved list', function (): void {
	$ids = array_column(array_map(
		static fn (SchemaData $schema): array => $schema->toArray(),
		$this->container->get(SchemaLister::class)->listReservedSchemas(),
	), 'id');

	expect($ids)->toContain('text')
		->and(array_diff($ids, SchemaData::RESERVED_SCHEMAS))->toBe([]);
});
