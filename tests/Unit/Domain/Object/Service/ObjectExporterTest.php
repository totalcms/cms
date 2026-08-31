<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Object\Service;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectExporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\LocalizedtextData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/*
|--------------------------------------------------------------------------
| ObjectExporter
|--------------------------------------------------------------------------
|
| ObjectExporter is the write side of every "get my data out" path: JumpStart
| export, the admin CSV/JSON download, and site-to-site migrations. Anything
| it silently drops or reshapes is data the customer loses when they move a
| site, and they only find out at import time. These tests pin the exact
| output shape rather than just "it returned something".
|
| All helpers are prefixed `objectExporter` because Pest loads every test file
| into a single process and global function names collide across files.
*/

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Build a Config with a fixed i18n locale list.
 *
 * Config's real constructor reads settings off disk, so we bypass it (per the
 * project convention) and set only what the exporter reads.
 *
 * @param array<int,string> $locales
 */
function objectExporterConfig(array $locales = []): Config
{
	$config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();

	$config->i18n = [
		'default'   => $locales[0] ?? '',
		'available' => array_map(static fn (string $code): array => ['code' => $code], $locales),
	];

	return $config;
}

/**
 * Wire up an ObjectExporter with mocked collaborators.
 *
 * @return array{
 *     exporter: ObjectExporter,
 *     storage: IndexRepository&MockObject,
 *     objects: ObjectFetcher&MockObject,
 *     schemas: SchemaFetcher&MockObject,
 *     filter: IndexFilter&MockObject,
 *     config: Config
 * }
 */
function objectExporterHarness(?Config $config = null): array
{
	$storage = test()->createMock(IndexRepository::class);
	$objects = test()->createMock(ObjectFetcher::class);
	$schemas = test()->createMock(SchemaFetcher::class);
	$filter  = test()->createMock(IndexFilter::class);
	$config ??= objectExporterConfig();

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

	return [
		'exporter' => new ObjectExporter($storage, $objects, $schemas, $filter, $config, $loggerFactory),
		'storage'  => $storage,
		'objects'  => $objects,
		'schemas'  => $schemas,
		'filter'   => $filter,
		'config'   => $config,
	];
}

/**
 * Teach the ObjectFetcher mock which objects exist.
 *
 * A `\Throwable` value simulates a corrupt/schema-mismatched object; an
 * unknown id throws the same exception the real fetcher throws.
 *
 * @param array<string,ObjectData|\Throwable> $map
 */
function objectExporterMapObjects(MockObject $fetcher, array $map): void
{
	$fetcher->method('fetchObject')->willReturnCallback(
		static function (string $collection, string $id) use ($map): ObjectData {
			if (!isset($map[$id])) {
				throw new \UnexpectedValueException("Unable to fetch object $collection/$id");
			}

			if ($map[$id] instanceof \Throwable) {
				throw $map[$id];
			}

			return $map[$id];
		}
	);
}

/**
 * @param array<string,\TotalCMS\Domain\Property\Data\PropertyData> $properties
 */
function objectExporterObject(string $id, array $properties): ObjectData
{
	return new ObjectData($id, $properties);
}

/**
 * @param array<string,mixed> $properties
 */
function objectExporterSchema(array $properties): SchemaData
{
	$schema             = new SchemaData();
	$schema->id         = 'test-schema';
	$schema->properties = $properties;

	return $schema;
}

/** @return array<string,mixed> */
function objectExporterCardProperty(string $schemaref): array
{
	return [
		'$ref'      => SchemaData::PROPERTY_TYPE_TO_REF['card'],
		'schemaref' => $schemaref,
	];
}

/** @return array<string,mixed> */
function objectExporterLocalizedProperty(): array
{
	return ['$ref' => SchemaData::PROPERTY_TYPE_TO_REF['localizedtext']];
}

/** @return array<string,mixed> */
function objectExporterTextProperty(): array
{
	return ['type' => 'string', 'field' => 'text'];
}

// -----------------------------------------------------------------------------
// exportAllObjects — the JumpStart / migration path
// -----------------------------------------------------------------------------

describe('exportAllObjects', function (): void {
	it('round-trips every field of every object with the id first', function (): void {
		// JumpStart export feeds straight back into JumpStart import. If a field
		// is dropped or renamed here the customer's content is gone after a
		// site move, so assert the exact array, not just a subset.
		$h = objectExporterHarness();

		$h['storage']->method('fetchObjectIds')->willReturn(['first-post', 'second-post']);
		objectExporterMapObjects($h['objects'], [
			'first-post'  => objectExporterObject('first-post', [
				'title' => new StringData('Hello World'),
				'body'  => new StringData('Some body copy'),
			]),
			'second-post' => objectExporterObject('second-post', [
				'title' => new StringData('Second'),
				'body'  => new StringData(''),
			]),
		]);

		expect($h['exporter']->exportAllObjects('posts'))->toBe([
			['id' => 'first-post', 'title' => 'Hello World', 'body' => 'Some body copy'],
			['id' => 'second-post', 'title' => 'Second', 'body' => ''],
		]);
	});

	it('preserves the index order of the object ids', function (): void {
		// The order the index hands back is the collection's sort order. Export
		// must not reshuffle it — importers replay it and some sites rely on
		// insertion order for un-sorted collections.
		$h = objectExporterHarness();

		$h['storage']->method('fetchObjectIds')->willReturn(['c', 'a', 'b']);
		objectExporterMapObjects($h['objects'], [
			'a' => objectExporterObject('a', []),
			'b' => objectExporterObject('b', []),
			'c' => objectExporterObject('c', []),
		]);

		expect(array_column($h['exporter']->exportAllObjects('posts'), 'id'))->toBe(['c', 'a', 'b']);
	});

	it('exports an empty collection as an empty array rather than erroring', function (): void {
		// A brand-new (or fully emptied) collection must still export cleanly —
		// a fatal here would break a whole-site JumpStart export.
		$h = objectExporterHarness();

		$h['storage']->method('fetchObjectIds')->willReturn([]);

		expect($h['exporter']->exportAllObjects('posts'))->toBe([]);
	});

	it('skips an object it cannot read and returns the rest', function (): void {
		// This used to have no try/catch, so one object whose stored data no
		// longer matched the schema aborted the export and the operator got
		// nothing — the one failure mode where they most want the other
		// objects out. Now it matches the JSON and CSV variants.
		$h = objectExporterHarness();

		$h['storage']->method('fetchObjectIds')->willReturn(['good', 'broken', 'also-good']);
		objectExporterMapObjects($h['objects'], [
			'good'      => objectExporterObject('good', ['title' => new StringData('Fine')]),
			'broken'    => new \RuntimeException('type mismatch'),
			'also-good' => objectExporterObject('also-good', ['title' => new StringData('Also fine')]),
		]);

		// Crucially it keeps going past the failure rather than stopping there.
		expect(array_column($h['exporter']->exportAllObjects('posts'), 'id'))
			->toBe(['good', 'also-good']);
	});

	it('returns an empty export rather than throwing when nothing can be read', function (): void {
		$h = objectExporterHarness();

		$h['storage']->method('fetchObjectIds')->willReturn(['broken']);
		objectExporterMapObjects($h['objects'], ['broken' => new \RuntimeException('type mismatch')]);

		expect($h['exporter']->exportAllObjects('posts'))->toBe([]);
	});
});

// -----------------------------------------------------------------------------
// exportAllObjectsForJson
// -----------------------------------------------------------------------------

describe('exportAllObjectsForJson', function (): void {
	it('returns the same payload as exportAllObjects with an empty error list', function (): void {
		// The JSON download and the JumpStart export must agree byte-for-byte;
		// if they drift, a JSON round-trip stops being a faithful backup.
		$h = objectExporterHarness();

		$h['storage']->method('fetchObjectIds')->willReturn(['post-1']);
		objectExporterMapObjects($h['objects'], [
			'post-1' => objectExporterObject('post-1', [
				'title' => new StringData('Title'),
				'card'  => new CardData(['label' => 'Nested', 'count' => 3]),
			]),
		]);

		expect($h['exporter']->exportAllObjectsForJson('posts'))->toBe([
			'data' => [[
				'id'    => 'post-1',
				'title' => 'Title',
				'card'  => ['label' => 'Nested', 'count' => 3],
			]],
			'errors' => [],
		]);
	});

	it('skips unreadable objects, reports their ids, and keeps the good ones', function (): void {
		// Schema edits after the fact leave objects that no longer hydrate. The
		// export must still hand back everything readable AND name what it
		// dropped — a silent skip is a silent data loss during migration.
		$h = objectExporterHarness();

		$h['storage']->method('fetchObjectIds')->willReturn(['ok-1', 'bad-1', 'ok-2', 'bad-2']);
		objectExporterMapObjects($h['objects'], [
			'ok-1'  => objectExporterObject('ok-1', ['title' => new StringData('One')]),
			'bad-1' => new \TypeError('string given, array expected'),
			'ok-2'  => objectExporterObject('ok-2', ['title' => new StringData('Two')]),
			'bad-2' => new \UnexpectedValueException('missing file'),
		]);

		$result = $h['exporter']->exportAllObjectsForJson('posts');

		expect(array_column($result['data'], 'id'))->toBe(['ok-1', 'ok-2'])
			->and($result['errors'])->toBe(['bad-1', 'bad-2']);
	});

	it('exports an empty collection as empty data and empty errors', function (): void {
		$h = objectExporterHarness();

		$h['storage']->method('fetchObjectIds')->willReturn([]);

		expect($h['exporter']->exportAllObjectsForJson('posts'))
			->toBe(['data' => [], 'errors' => []]);
	});
});

// -----------------------------------------------------------------------------
// exportFilteredObjectsForJson
// -----------------------------------------------------------------------------

describe('exportFilteredObjectsForJson', function (): void {
	it('passes the filter options straight through to the index filter', function (): void {
		// The admin passes include/exclude/sort from the UI. If the exporter
		// mangles or drops an option the operator downloads the wrong rows and
		// has no way to tell.
		$h        = objectExporterHarness();
		$captured = null;

		$h['filter']->method('fetchFilteredIndex')->willReturnCallback(
			static function (string $collection, array $options) use (&$captured): array {
				$captured = [$collection, $options];

				return [];
			}
		);

		$h['exporter']->exportFilteredObjectsForJson('posts', [
			'include' => 'featured:true',
			'exclude' => 'draft:true',
			'sort'    => 'date',
		]);

		expect($captured)->toBe(['posts', [
			'include' => 'featured:true',
			'exclude' => 'draft:true',
			'sort'    => 'date',
		]]);
	});

	it('exports only the ids the filter returned and ignores everything else', function (): void {
		// The whole point of a filtered export is that non-matching objects stay
		// out of the file. A leak here means draft/private content ships in a
		// download the operator believed was filtered.
		$h = objectExporterHarness();

		$h['filter']->method('fetchFilteredIndex')->willReturn([
			['id' => 'keep-1', 'title' => 'Keep 1'],
			['id' => 'keep-2', 'title' => 'Keep 2'],
		]);
		objectExporterMapObjects($h['objects'], [
			'keep-1'  => objectExporterObject('keep-1', ['title' => new StringData('Keep 1')]),
			'keep-2'  => objectExporterObject('keep-2', ['title' => new StringData('Keep 2')]),
			'exclude' => objectExporterObject('exclude', ['title' => new StringData('Nope')]),
		]);

		$result = $h['exporter']->exportFilteredObjectsForJson('posts', ['include' => 'x']);

		expect(array_column($result['data'], 'id'))->toBe(['keep-1', 'keep-2'])
			->and($result['errors'])->toBe([]);
	});

	it('preserves the filter order rather than the on-disk order', function (): void {
		// IndexFilter applies the requested sort. The exporter must not
		// re-order, or a `sort=date` CSV/JSON export comes out unsorted.
		$h = objectExporterHarness();

		$h['filter']->method('fetchFilteredIndex')->willReturn([
			['id' => 'z'], ['id' => 'm'], ['id' => 'a'],
		]);
		objectExporterMapObjects($h['objects'], [
			'a' => objectExporterObject('a', []),
			'm' => objectExporterObject('m', []),
			'z' => objectExporterObject('z', []),
		]);

		$result = $h['exporter']->exportFilteredObjectsForJson('posts', []);

		expect(array_column($result['data'], 'id'))->toBe(['z', 'm', 'a']);
	});

	it('degrades a malformed index row into an error entry instead of crashing', function (): void {
		// A hand-edited or half-written index can contain a row with no `id`.
		// The exporter coerces it to '' and the fetch fails — that must land in
		// `errors`, not take the whole download down.
		$h = objectExporterHarness();

		$h['filter']->method('fetchFilteredIndex')->willReturn([
			['title' => 'no id here'],
			['id' => 'good'],
		]);
		objectExporterMapObjects($h['objects'], [
			'good' => objectExporterObject('good', ['title' => new StringData('Good')]),
		]);

		$result = $h['exporter']->exportFilteredObjectsForJson('posts', []);

		expect(array_column($result['data'], 'id'))->toBe(['good'])
			->and($result['errors'])->toBe(['']);
	});

	it('exports empty data when the filter matches nothing', function (): void {
		$h = objectExporterHarness();

		$h['filter']->method('fetchFilteredIndex')->willReturn([]);

		expect($h['exporter']->exportFilteredObjectsForJson('posts', ['include' => 'nope']))
			->toBe(['data' => [], 'errors' => []]);
	});
});

// -----------------------------------------------------------------------------
// CSV export — headers
// -----------------------------------------------------------------------------

describe('CSV headers', function (): void {
	it('puts the header row first, in schema property order', function (): void {
		// CSV column order is the contract the CSV importer reads back. Schema
		// order (not object order, not alphabetical) is what round-trips.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'id'      => objectExporterTextProperty(),
			'title'   => objectExporterTextProperty(),
			'summary' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['post-1']);
		objectExporterMapObjects($h['objects'], [
			'post-1' => objectExporterObject('post-1', [
				// Deliberately a different order than the schema declares.
				'summary' => new StringData('Summary text'),
				'title'   => new StringData('Title text'),
			]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts'))->toBe([
			'data' => [
				['id', 'title', 'summary'],
				['post-1', 'Title text', 'Summary text'],
			],
			'errors' => [],
		]);
	});

	it('still emits the header row for an empty collection', function (): void {
		// An empty CSV export must keep its columns — an operator downloading a
		// template to fill in and re-import gets nothing usable otherwise.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'id'    => objectExporterTextProperty(),
			'title' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn([]);

		expect($h['exporter']->exportAllObjectsForCSv('posts'))
			->toBe(['data' => [['id', 'title']], 'errors' => []]);
	});

	it('emits a single column for a non-array schema property definition', function (): void {
		// Legacy/hand-written schemas can carry scalar property definitions.
		// Those must not blow up header building.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'title'  => objectExporterTextProperty(),
			'legacy' => 'string',
		]));
		$h['storage']->method('fetchObjectIds')->willReturn([]);

		// `id` is prepended because this schema does not declare one.
		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'][0])->toBe(['id', 'title', 'legacy']);
	});

	it('prepends an id column when the schema does not declare an id property', function (): void {
		// CSV columns come from the SCHEMA, but the id is not optional: without
		// an id column a re-import has nothing to match on, so an
		// export/edit/import round trip creates a duplicate of every record
		// instead of updating the originals. The exporter therefore forces an
		// id column in front when the schema does not supply one.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'title' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['post-1']);
		objectExporterMapObjects($h['objects'], [
			'post-1' => objectExporterObject('post-1', ['title' => new StringData('Title')]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'])->toBe([
			['id', 'title'],
			['post-1', 'Title'],
		]);
	});

	it('leaves a schema-declared id column exactly where the schema puts it', function (): void {
		// The safety property for the 36 shipped schemas that DO declare `id`:
		// the forced column is only added when missing, never moved. If it were
		// hoisted to the front instead, every existing export would change
		// column order and break scripts and diffs that consume those files.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'title'   => objectExporterTextProperty(),
			'id'      => objectExporterTextProperty(),
			'summary' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['post-1']);
		objectExporterMapObjects($h['objects'], [
			'post-1' => objectExporterObject('post-1', [
				'title'   => new StringData('Title text'),
				'summary' => new StringData('Summary text'),
			]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'])->toBe([
			['title', 'id', 'summary'],
			['Title text', 'post-1', 'Summary text'],
		]);
	});

	it('drops object properties that the schema no longer declares', function (): void {
		// Same schema-driven-headers consequence in the other direction: data
		// still stored on the object but removed from the schema is NOT in the
		// CSV. JSON export keeps it; CSV does not. Worth knowing before someone
		// treats a CSV export as a backup.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'id'    => objectExporterTextProperty(),
			'title' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['post-1']);
		objectExporterMapObjects($h['objects'], [
			'post-1' => objectExporterObject('post-1', [
				'title'   => new StringData('Kept'),
				'orphan'  => new StringData('Dropped by CSV'),
			]),
		]);

		$result = $h['exporter']->exportAllObjectsForCSv('posts');

		expect($result['data'][0])->not->toContain('orphan')
			->and($result['data'][1])->toBe(['post-1', 'Kept']);
	});

	it('leaves an empty cell for a schema property the object has no value for', function (): void {
		// Column count must stay constant across rows or the CSV is unparseable.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'id'      => objectExporterTextProperty(),
			'title'   => objectExporterTextProperty(),
			'missing' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['post-1']);
		objectExporterMapObjects($h['objects'], [
			'post-1' => objectExporterObject('post-1', ['title' => new StringData('Only title')]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'][1])
			->toBe(['post-1', 'Only title', '']);
	});
});

// -----------------------------------------------------------------------------
// CSV export — card flattening
// -----------------------------------------------------------------------------

describe('CSV card flattening', function (): void {
	it('expands a card into dot-notation columns and excludes the card id', function (): void {
		// Documented CSV contract: `mycard.title`. The sub-schema's own `id`
		// field is meaningless for a card and must not become a column, or the
		// importer writes a bogus id into the nested object.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'id'      => objectExporterTextProperty(),
			'address' => objectExporterCardProperty('https://www.totalcms.co/schemas/custom/address.json'),
		]));
		$h['schemas']->method('fetchSchema')->willReturnCallback(
			static function (string $id): SchemaData {
				expect($id)->toBe('address');

				return objectExporterSchema([
					'id'     => objectExporterTextProperty(),
					'street' => objectExporterTextProperty(),
					'city'   => objectExporterTextProperty(),
				]);
			}
		);
		$h['storage']->method('fetchObjectIds')->willReturn(['contact-1']);
		objectExporterMapObjects($h['objects'], [
			'contact-1' => objectExporterObject('contact-1', [
				'address' => new CardData(['street' => '1 Main St', 'city' => 'Austin']),
			]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts'))->toBe([
			'data' => [
				['id', 'address.street', 'address.city'],
				['contact-1', '1 Main St', 'Austin'],
			],
			'errors' => [],
		]);
	});

	it('json-encodes a nested array stored inside a card', function (): void {
		// An image/file field nested in a card is an array. It has to survive as
		// JSON in the cell or the nested asset is lost on re-import.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'meta' => objectExporterCardProperty('https://www.totalcms.co/schemas/custom/meta.json'),
		]));
		$h['schemas']->method('fetchSchema')->willReturn(objectExporterSchema([
			'photo' => objectExporterTextProperty(),
			'count' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['obj']);
		objectExporterMapObjects($h['objects'], [
			'obj' => objectExporterObject('obj', [
				'meta' => new CardData([
					'photo' => ['src' => '/images/a.jpg', 'alt' => 'A'],
					'count' => 7,
				]),
			]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'][1])->toBe([
			'obj',
			'{"src":"/images/a.jpg","alt":"A"}',
			'7',
		]);
	});

	it('escapes newlines inside card values so each record stays one CSV line', function (): void {
		// A literal newline in a cell splits the record in two for most CSV
		// parsers. The exporter converts it to a literal \n, matching
		// ObjectData::forCsv() so both paths round-trip identically.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'meta' => objectExporterCardProperty('https://www.totalcms.co/schemas/custom/meta.json'),
		]));
		$h['schemas']->method('fetchSchema')->willReturn(objectExporterSchema([
			'notes' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['obj']);
		objectExporterMapObjects($h['objects'], [
			'obj' => objectExporterObject('obj', [
				'meta' => new CardData(['notes' => "line one\r\nline two\nline three"]),
			]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'][1])
			->toBe(['obj', 'line one\nline two\nline three']);
	});

	it('leaves an empty cell for a card sub-property with no stored value', function (): void {
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'meta' => objectExporterCardProperty('https://www.totalcms.co/schemas/custom/meta.json'),
		]));
		$h['schemas']->method('fetchSchema')->willReturn(objectExporterSchema([
			'set'   => objectExporterTextProperty(),
			'unset' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['obj']);
		objectExporterMapObjects($h['objects'], [
			'obj' => objectExporterObject('obj', ['meta' => new CardData(['set' => 'value'])]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'][1])->toBe(['obj', 'value', '']);
	});

	it('leaves the card columns empty when the stored property is not actually a card', function (): void {
		// Schema says card, disk says string (someone changed a field type).
		// Export must produce blanks, not a fatal — otherwise the operator can
		// never get their remaining data out to fix it.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'meta' => objectExporterCardProperty('https://www.totalcms.co/schemas/custom/meta.json'),
		]));
		$h['schemas']->method('fetchSchema')->willReturn(objectExporterSchema([
			'a' => objectExporterTextProperty(),
			'b' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['obj']);
		objectExporterMapObjects($h['objects'], [
			'obj' => objectExporterObject('obj', ['meta' => new StringData('was a card once')]),
		]);

		// The id still exports — only the unreadable card columns blank out.
		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'][1])->toBe(['obj', '', '']);
	});

	it('falls back to one JSON column when the card has no schemaref', function (): void {
		// Without a resolvable sub-schema there are no sub-keys to expand, so
		// the whole card ships as one JSON cell. Losing the card entirely would
		// be data loss; this fallback keeps it recoverable.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'meta' => ['$ref' => SchemaData::PROPERTY_TYPE_TO_REF['card']],
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['obj']);
		objectExporterMapObjects($h['objects'], [
			'obj' => objectExporterObject('obj', ['meta' => new CardData(['a' => 1, 'b' => 'two'])]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'])->toBe([
			['id', 'meta'],
			['obj', '{"a":1,"b":"two"}'],
		]);
	});

	it('falls back to one JSON column when the referenced sub-schema cannot be loaded', function (): void {
		// A deleted sub-schema must not take the export down with it.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'meta' => objectExporterCardProperty('https://www.totalcms.co/schemas/custom/gone.json'),
		]));
		$h['schemas']->method('fetchSchema')->willThrowException(new \RuntimeException('schema missing'));
		$h['storage']->method('fetchObjectIds')->willReturn(['obj']);
		objectExporterMapObjects($h['objects'], [
			'obj' => objectExporterObject('obj', ['meta' => new CardData(['a' => 1])]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'])->toBe([
			['id', 'meta'],
			['obj', '{"a":1}'],
		]);
	});

	it('reads a schemaref nested under settings', function (): void {
		// Older schemas store the ref under `settings.schemaref`. Both shapes
		// must expand, or the same card exports differently on two sites.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'meta' => [
				'$ref'     => SchemaData::PROPERTY_TYPE_TO_REF['card'],
				'settings' => ['schemaref' => 'https://www.totalcms.co/schemas/custom/meta.json'],
			],
		]));
		$h['schemas']->method('fetchSchema')->willReturn(objectExporterSchema([
			'label' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['obj']);
		objectExporterMapObjects($h['objects'], [
			'obj' => objectExporterObject('obj', ['meta' => new CardData(['label' => 'Nested ref'])]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'])->toBe([
			['id', 'meta.label'],
			['obj', 'Nested ref'],
		]);
	});
});

// -----------------------------------------------------------------------------
// CSV export — localized text flattening
// -----------------------------------------------------------------------------

describe('CSV localized text flattening', function (): void {
	it('expands a localized field into one column per configured locale, in config order', function (): void {
		// Locale column order mirrors the operator's configured preference
		// order (also the Twig fall-down order). If it drifts, two exports from
		// the same site produce differently-ordered CSVs and diffing breaks.
		$h = objectExporterHarness(objectExporterConfig(['en_US', 'de_DE', 'fr_FR']));

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'id'      => objectExporterTextProperty(),
			'heading' => objectExporterLocalizedProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['page-1']);
		objectExporterMapObjects($h['objects'], [
			'page-1' => objectExporterObject('page-1', [
				'heading' => new LocalizedtextData(['de_DE' => 'Hallo', 'en_US' => 'Hello']),
			]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts'))->toBe([
			'data' => [
				['id', 'heading.en_US', 'heading.de_DE', 'heading.fr_FR'],
				// fr_FR was never translated — empty cell, not a missing column.
				['page-1', 'Hello', 'Hallo', ''],
			],
			'errors' => [],
		]);
	});

	it('escapes newlines inside localized values', function (): void {
		// Same one-line-per-record guarantee as card values.
		$h = objectExporterHarness(objectExporterConfig(['en_US']));

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'body' => objectExporterLocalizedProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['page-1']);
		objectExporterMapObjects($h['objects'], [
			'page-1' => objectExporterObject('page-1', [
				'body' => new LocalizedtextData(['en_US' => "first\nsecond"]),
			]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'][1])->toBe(['page-1', 'first\nsecond']);
	});

	it('falls back to a single column when no locales are configured', function (): void {
		// A site that has not opted into i18n still has to export localized
		// fields somehow — one column holding the default-locale string.
		$h = objectExporterHarness(objectExporterConfig([]));

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'heading' => objectExporterLocalizedProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['page-1']);
		objectExporterMapObjects($h['objects'], [
			'page-1' => objectExporterObject('page-1', [
				'heading' => new LocalizedtextData(['en_US' => 'Hello']),
			]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'])->toBe([
			['id', 'heading'],
			['page-1', 'Hello'],
		]);
	});

	it('leaves the locale columns empty when the stored property is not localized data', function (): void {
		// Field type changed under an existing collection — blanks, not a fatal.
		$h = objectExporterHarness(objectExporterConfig(['en_US', 'de_DE']));

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'heading' => objectExporterLocalizedProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['page-1']);
		objectExporterMapObjects($h['objects'], [
			'page-1' => objectExporterObject('page-1', ['heading' => new StringData('plain text')]),
		]);

		// The id still exports — only the unreadable locale columns blank out.
		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'][1])->toBe(['page-1', '', '']);
	});

	it('ignores blank locale codes in the i18n config', function (): void {
		// A half-filled locale row in Settings must not produce a `heading.`
		// column with an empty suffix, which the importer cannot map back.
		$config       = objectExporterConfig(['en_US']);
		$config->i18n = [
			'default'   => 'en_US',
			'available' => [['code' => 'en_US'], ['code' => ''], ['label' => 'no code at all']],
		];
		$h = objectExporterHarness($config);

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'heading' => objectExporterLocalizedProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn([]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'][0])->toBe(['id', 'heading.en_US']);
	});
});

// -----------------------------------------------------------------------------
// CSV export — general behaviour
// -----------------------------------------------------------------------------

describe('CSV export behaviour', function (): void {
	it('escapes newlines in ordinary string columns', function (): void {
		// Delegated to ObjectData::forCsv(), but pinned here because the CSV
		// export is the contract customers actually consume.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'body' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['post-1']);
		objectExporterMapObjects($h['objects'], [
			'post-1' => objectExporterObject('post-1', ['body' => new StringData("one\r\ntwo\rthree\nfour")]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'][1])
			->toBe(['post-1', 'one\ntwo\nthree\nfour']);
	});

	it('skips unreadable objects but keeps the header and the readable rows', function (): void {
		// Partial CSV beats no CSV — and the caller needs the failed ids to
		// report them, otherwise the operator ships an incomplete migration
		// believing it was complete.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'id'    => objectExporterTextProperty(),
			'title' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['ok-1', 'bad-1', 'ok-2']);
		objectExporterMapObjects($h['objects'], [
			'ok-1'  => objectExporterObject('ok-1', ['title' => new StringData('One')]),
			'bad-1' => new \TypeError('field type changed'),
			'ok-2'  => objectExporterObject('ok-2', ['title' => new StringData('Two')]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts'))->toBe([
			'data' => [
				['id', 'title'],
				['ok-1', 'One'],
				['ok-2', 'Two'],
			],
			'errors' => ['bad-1'],
		]);
	});

	it('filtered CSV export passes its options to the index filter', function (): void {
		$h        = objectExporterHarness();
		$captured = null;

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'id' => objectExporterTextProperty(),
		]));
		$h['filter']->method('fetchFilteredIndex')->willReturnCallback(
			static function (string $collection, array $options) use (&$captured): array {
				$captured = [$collection, $options];

				return [];
			}
		);

		$h['exporter']->exportFilteredObjectsForCsv('posts', ['include' => 'featured:true']);

		expect($captured)->toBe(['posts', ['include' => 'featured:true']]);
	});

	it('filtered CSV export excludes non-matching objects and never touches the index', function (): void {
		// A filtered CSV must come from IndexFilter, not from the full object-id
		// list — otherwise the "filtered" download quietly contains everything.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'id'    => objectExporterTextProperty(),
			'title' => objectExporterTextProperty(),
		]));
		$h['storage']->expects(test()->never())->method('fetchObjectIds');
		$h['filter']->method('fetchFilteredIndex')->willReturn([['id' => 'keep-1']]);
		objectExporterMapObjects($h['objects'], [
			'keep-1'  => objectExporterObject('keep-1', ['title' => new StringData('Keep')]),
			'exclude' => objectExporterObject('exclude', ['title' => new StringData('Nope')]),
		]);

		expect($h['exporter']->exportFilteredObjectsForCsv('posts', ['include' => 'x']))->toBe([
			'data' => [
				['id', 'title'],
				['keep-1', 'Keep'],
			],
			'errors' => [],
		]);
	});

	it('exports cards and localized fields side by side without cross-contamination', function (): void {
		// Both flattened kinds share the same dot-notation namespace in
		// buildCsvRow. This is the regression guard that a card column never
		// gets resolved as a locale (or vice versa) when both are present.
		$h = objectExporterHarness(objectExporterConfig(['en_US', 'de_DE']));

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'id'      => objectExporterTextProperty(),
			'address' => objectExporterCardProperty('https://www.totalcms.co/schemas/custom/address.json'),
			'heading' => objectExporterLocalizedProperty(),
			'body'    => objectExporterTextProperty(),
		]));
		$h['schemas']->method('fetchSchema')->willReturn(objectExporterSchema([
			'id'   => objectExporterTextProperty(),
			'city' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['page-1']);
		objectExporterMapObjects($h['objects'], [
			'page-1' => objectExporterObject('page-1', [
				'address' => new CardData(['city' => 'Austin']),
				'heading' => new LocalizedtextData(['en_US' => 'Hello', 'de_DE' => 'Hallo']),
				'body'    => new StringData('Body copy'),
			]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts'))->toBe([
			'data' => [
				['id', 'address.city', 'heading.en_US', 'heading.de_DE', 'body'],
				['page-1', 'Austin', 'Hello', 'Hallo', 'Body copy'],
			],
			'errors' => [],
		]);
	});

	it('treats a plain schema property whose name contains a dot as a single column', function (): void {
		// buildCsvRow branches on `.` in the header. A property literally named
		// `my.prop` must still resolve from the flat forCsv() map rather than
		// being mistaken for a card sub-column and coming back empty.
		$h = objectExporterHarness();

		$h['schemas']->method('fetchSchemaForCollection')->willReturn(objectExporterSchema([
			'my.prop' => objectExporterTextProperty(),
		]));
		$h['storage']->method('fetchObjectIds')->willReturn(['post-1']);
		objectExporterMapObjects($h['objects'], [
			'post-1' => objectExporterObject('post-1', ['my.prop' => new StringData('dotted')]),
		]);

		expect($h['exporter']->exportAllObjectsForCSv('posts')['data'])->toBe([
			['id', 'my.prop'],
			['post-1', 'dotted'],
		]);
	});
});
