<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Object\Service;

use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectImporter;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Service\DepotSaver;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Property\Service\GallerySaver;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/*
 * ObjectImporter is the object WRITE path behind the job queue, JumpStart,
 * and every CMS importer (Total CMS 1, Alloy, WordPress, CSV, JSON, RSS, URL).
 * A fault here does not throw a 500 in someone's face — it silently writes the
 * wrong bytes into a customer's collection, or leaves the event dispatcher
 * suspended so every later `object.*` listener (search indexing, automations,
 * extensions) goes deaf for the rest of the process.
 *
 * These tests drive the importer with mocked collaborators but a REAL
 * EventDispatcher, because the import/suspend lifecycle is the part extension
 * authors depend on and mocking it away would test nothing.
 *
 * Helper names are prefixed `objectImporter*` — Pest loads every test file into
 * one process, so unprefixed globals collide with other suites.
 */

/** A 1x1 PNG. `getimagesize()` must succeed on gallery members or they're skipped. */
const OBJECT_IMPORTER_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function objectImporterRef(string $type): string
{
	return SchemaData::PROPERTY_TYPE_TO_REF[$type];
}

/** @param array<string,mixed> $properties */
function objectImporterSchema(array $properties): SchemaData
{
	$schema             = new SchemaData();
	$schema->id         = 'blog';
	$schema->properties = $properties;

	return $schema;
}

/**
 * Build an ObjectImporter with recording doubles.
 *
 * Every collaborator records what it was asked to do on the returned handle so
 * assertions can be made about the *payload that would hit disk*, which is the
 * thing that actually corrupts a collection when it is wrong.
 *
 * @param array<string,mixed> $properties schema properties for the collection
 * @param array<string,mixed> $options    objectId, exists, saveThrows
 */
function objectImporterHarness(array $properties = [], array $options = []): object
{
	$handle = new \stdClass();

	$handle->collection = 'blog';
	$handle->objectId   = $options['objectId'] ?? 'post-1';

	$handle->savedCollection = null;
	$handle->savedPayload    = null;
	$handle->patchedId       = null;
	$handle->patchedPayload  = null;
	$handle->propertyPatches = [];
	$handle->metaPatches     = [];
	$handle->imageSaves      = [];
	$handle->gallerySaves    = [];
	$handle->fileSaves       = [];
	$handle->depotSaves      = [];
	$handle->counterCalls    = [];
	$handle->events          = [];
	// Was the collection suspended at the moment the inner write happened?
	$handle->suspendedDuringWrite = null;

	// A real dispatcher: suppression of object.created/object.updated during an
	// import is behaviour, not a detail, and a mock would happily "verify" it
	// while the real rule was broken.
	$handle->dispatcher = new EventDispatcher(new NullLogger());
	foreach ([
		CoreEvent::OBJECT_CREATED,
		CoreEvent::OBJECT_UPDATED,
		CoreEvent::IMPORT_CREATED,
		CoreEvent::IMPORT_UPDATED,
	] as $eventName) {
		$handle->dispatcher->listen($eventName, function (array $payload) use ($handle, $eventName): void {
			$handle->events[] = ['event' => $eventName, 'payload' => $payload];
		});
	}

	$handle->schemaFetcher = test()->createMock(SchemaFetcher::class);
	$handle->schemaFetcher->method('fetchSchemaForCollection')
		->willReturn(objectImporterSchema($properties));

	$handle->objectSaver = test()->createMock(ObjectSaver::class);
	$handle->objectSaver->method('saveObject')->willReturnCallback(
		function (string $collection, array $objectData) use ($handle, $options): ObjectData {
			$handle->savedCollection      = $collection;
			$handle->savedPayload         = $objectData;
			$handle->suspendedDuringWrite = $handle->dispatcher->isImportSuspended($collection);

			if (isset($options['saveThrows'])) {
				throw $options['saveThrows'];
			}

			// Mirror the real ObjectSaver, which dispatches object.created after
			// a successful write. The importer relies on the dispatcher dropping
			// it while the collection is suspended.
			$handle->dispatcher->dispatch(
				CoreEvent::OBJECT_CREATED,
				new ObjectEventPayload($collection, $handle->objectId, new ObjectData($handle->objectId, [])),
			);

			return new ObjectData($handle->objectId, []);
		}
	);

	$handle->objectPatcher = test()->createMock(ObjectPatcher::class);
	$handle->objectPatcher->method('patchObject')->willReturnCallback(
		function (string $collection, string $id, array $newData) use ($handle): ObjectData {
			$handle->patchedId            = $id;
			$handle->patchedPayload       = $newData;
			$handle->suspendedDuringWrite = $handle->dispatcher->isImportSuspended($collection);

			// ObjectPatcher delegates to ObjectUpdater, which dispatches
			// object.updated on a non-silent patch.
			$handle->dispatcher->dispatch(
				CoreEvent::OBJECT_UPDATED,
				new ObjectEventPayload($collection, $id, new ObjectData($id, [])),
			);

			return new ObjectData($id, []);
		}
	);
	$handle->objectPatcher->method('patchObjectProperty')->willReturnCallback(
		function (string $collection, string $id, string $property, array $newData) use ($handle): ObjectData {
			$handle->propertyPatches[] = compact('collection', 'id', 'property', 'newData');

			return new ObjectData($id, []);
		}
	);
	$handle->objectPatcher->method('patchObjectPropertyMeta')->willReturnCallback(
		function (string $collection, string $id, string $property, string $name, array $newData) use ($handle): ObjectData {
			$handle->metaPatches[] = compact('collection', 'id', 'property', 'name', 'newData');

			return new ObjectData($id, []);
		}
	);

	$handle->objectFetcher = test()->createMock(ObjectFetcher::class);
	$handle->objectFetcher->method('fetchObject')->willReturnCallback(
		fn (string $collection, string $id): ObjectData => new ObjectData($id, [])
	);
	$handle->objectFetcher->method('existsObject')->willReturn((bool)($options['exists'] ?? false));

	$handle->imageSaver = test()->createMock(ImageSaver::class);
	$handle->imageSaver->method('save')->willReturnCallback(
		function (string $collection, string $id, string $property, string $path) use ($handle): ObjectData {
			$handle->imageSaves[] = compact('collection', 'id', 'property', 'path');

			return new ObjectData($id, []);
		}
	);

	$handle->gallerySaver = test()->createMock(GallerySaver::class);
	$handle->gallerySaver->method('save')->willReturnCallback(
		function (string $collection, string $id, string $property, string $path) use ($handle): ObjectData {
			$handle->gallerySaves[] = compact('collection', 'id', 'property', 'path');

			return new ObjectData($id, []);
		}
	);

	$handle->fileSaver = test()->createMock(FileSaver::class);
	$handle->fileSaver->method('save')->willReturnCallback(
		function (string $collection, string $id, string $property, string $path) use ($handle): ObjectData {
			$handle->fileSaves[] = compact('collection', 'id', 'property', 'path');

			return new ObjectData($id, []);
		}
	);

	$handle->depotSaver = test()->createMock(DepotSaver::class);
	$handle->depotSaver->method('save')->willReturnCallback(
		function (string $collection, string $id, string $property, string $path) use ($handle): ObjectData {
			$handle->depotSaves[] = compact('collection', 'id', 'property', 'path');

			return new ObjectData($id, []);
		}
	);

	$handle->collectionFetcher = test()->createMock(CollectionFetcher::class);
	$handle->collectionFetcher->method('incrementCachedCount')->willReturnCallback(
		function (string $collectionId) use ($handle): void {
			$handle->counterCalls[] = ['cached', $collectionId];
		}
	);

	$collectionStub          = test()->createMock(CollectionData::class);
	$handle->collectionSaver = test()->createMock(CollectionSaver::class);
	$handle->collectionSaver->method('incrementCount')->willReturnCallback(
		function (string $collectionId) use ($handle, $collectionStub): CollectionData {
			$handle->counterCalls[] = ['persisted', $collectionId];

			return $collectionStub;
		}
	);

	$handle->importer = new ObjectImporter(
		$handle->schemaFetcher,
		$handle->objectSaver,
		$handle->objectPatcher,
		$handle->objectFetcher,
		$handle->imageSaver,
		$handle->gallerySaver,
		$handle->fileSaver,
		$handle->depotSaver,
		$handle->dispatcher,
		$handle->collectionFetcher,
		$handle->collectionSaver,
	);

	return $handle;
}

/** Create a scratch directory that the test tears down again. */
function objectImporterTempDir(): string
{
	$dir = sys_get_temp_dir() . '/tcms-object-importer-' . bin2hex(random_bytes(6));
	mkdir($dir, 0o777, true);

	return $dir;
}

function objectImporterWritePng(string $path): string
{
	file_put_contents($path, base64_decode(OBJECT_IMPORTER_PNG, true));

	return $path;
}

function objectImporterRemoveDir(string $dir): void
{
	if (!is_dir($dir)) {
		return;
	}
	foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $file) {
		/** @var \SplFileInfo $file */
		$file->isDir() ? objectImporterRemoveDir($file->getPathname()) : @unlink($file->getPathname());
	}
	@rmdir($dir);
}

/** @return list<string> */
function objectImporterEventNames(object $handle): array
{
	return array_column($handle->events, 'event');
}

beforeEach(function (): void {
	// replacePathTemplates() dereferences $_SERVER['DOCUMENT_ROOT'] unguarded,
	// so anything exercising a media property needs it present.
	$this->originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
	$_SERVER['DOCUMENT_ROOT']   = sys_get_temp_dir();
	$this->scratchDirs          = [];
});

afterEach(function (): void {
	if ($this->originalDocumentRoot === null) {
		unset($_SERVER['DOCUMENT_ROOT']);
	} else {
		$_SERVER['DOCUMENT_ROOT'] = $this->originalDocumentRoot;
	}

	foreach ($this->scratchDirs as $dir) {
		objectImporterRemoveDir($dir);
	}
});

describe('ObjectImporter::importObject payload correctness', function (): void {
	it('persists every schema-known field and returns the re-fetched object', function (): void {
		// The importer must hand ObjectSaver the caller's values verbatim, and
		// must return the object as it exists AFTER the media side-cars were
		// written — returning the saver's result would hand callers an object
		// missing its images.
		$handle = objectImporterHarness([
			'title' => ['type' => 'string'],
			'body'  => ['type' => 'string'],
		]);

		$result = $handle->importer->importObject('blog', [
			'title' => 'Hello World',
			'body'  => 'Some content',
		]);

		expect($handle->savedCollection)->toBe('blog')
			->and($handle->savedPayload)->toBe([
				'title' => 'Hello World',
				'body'  => 'Some content',
			])
			->and($result)->toBeInstanceOf(ObjectData::class)
			->and($result->id)->toBe('post-1');
	});

	it('drops properties the schema does not define', function (): void {
		// Importers routinely receive extra columns (CSV headers, WordPress
		// meta, stale JumpStart exports). Writing them through would put
		// unschema'd junk into the JSON file, which the admin form then cannot
		// edit or delete.
		$handle = objectImporterHarness(['title' => ['type' => 'string']]);

		$handle->importer->importObject('blog', [
			'title'      => 'Kept',
			'wp_post_id' => 4821,
			'__proto__'  => 'nope',
		]);

		expect($handle->savedPayload)->toBe(['title' => 'Kept']);
	});

	it('restores escaped newlines in string fields', function (): void {
		// CSV cells store multi-line text as a literal backslash-n. Without this
		// the customer's blog post renders "line1\nline2" on the live site.
		$handle = objectImporterHarness(['body' => ['type' => 'string']]);

		$handle->importer->importObject('blog', ['body' => 'line1\nline2']);

		expect($handle->savedPayload['body'])->toBe("line1\nline2");
	});

	it('splits delimited list fields on commas and pipes', function (): void {
		// A list property stored as a scalar string would break every
		// `for tag in object.tags` loop in a customer template.
		$handle = objectImporterHarness(['tags' => ['$ref' => objectImporterRef('list')]]);

		$handle->importer->importObject('blog', ['tags' => ' php , twig| cms ']);

		expect(array_values($handle->savedPayload['tags']))->toBe(['php', 'twig', 'cms']);
	});

	it('decodes JSON ref values instead of treating them as filesystem paths', function (): void {
		// Round-trip of an exported object: image/gallery/file/depot/card/deck
		// columns come back as JSON. If this branch missed, the importer would
		// treat the JSON blob as a path, find no file, and silently write an
		// empty image — data loss on every export/import cycle.
		$handle = objectImporterHarness(['photo' => ['$ref' => objectImporterRef('image')]]);

		$handle->importer->importObject('blog', [
			'photo' => '{"file":"hero.jpg","alt":"Hero"}',
		]);

		expect($handle->savedPayload['photo'])->toBe(['file' => 'hero.jpg', 'alt' => 'Hero'])
			->and($handle->imageSaves)->toBe([]);
	});

	it('unflattens dotted card columns into a nested array', function (): void {
		// CSV exports one column per card sub-key. These must be recombined
		// BEFORE the unknown-property filter runs, otherwise every card column
		// is stripped and the card silently imports empty.
		$handle = objectImporterHarness(['hero' => ['$ref' => objectImporterRef('card')]]);

		$handle->importer->importObject('blog', [
			'hero.title'    => 'Big Title',
			'hero.subtitle' => 'Small Title',
			'hero.photo'    => '{"file":"x.jpg"}',
		]);

		expect($handle->savedPayload['hero'])->toBe([
			'title'    => 'Big Title',
			'subtitle' => 'Small Title',
			// nested JSON written by the exporter round-trips as an array
			'photo' => ['file' => 'x.jpg'],
		]);
	});

	it('leaves unrelated and empty-suffix columns out of the unflattened card', function (): void {
		// A stray "hero." header (trailing separator, common in hand-edited
		// CSVs) must not create an empty sub-key inside the card, and sibling
		// columns for other fields must be left alone.
		$handle = objectImporterHarness([
			'hero'  => ['$ref' => objectImporterRef('card')],
			'title' => ['type' => 'string'],
		]);

		$handle->importer->importObject('blog', [
			'title'      => 'Post title',
			'hero.'      => 'orphan',
			'hero.title' => 'Card title',
		]);

		expect($handle->savedPayload['hero'])->toBe(['title' => 'Card title'])
			->and($handle->savedPayload['title'])->toBe('Post title');
	});

	it('unflattens dotted localized columns keyed by locale', function (): void {
		// All three localized field types share one $ref; a regression here
		// would collapse a multi-locale site down to a single string value.
		$handle = objectImporterHarness(['title' => ['$ref' => objectImporterRef('localizedtext')]]);

		$handle->importer->importObject('blog', [
			'title.en_US' => 'Hi',
			'title.de_DE' => 'Hallo',
		]);

		expect($handle->savedPayload['title'])->toBe(['en_US' => 'Hi', 'de_DE' => 'Hallo']);
	});
});

describe('ObjectImporter::importObject media side-cars', function (): void {
	it('strips the media path from the payload and saves the file after the object exists', function (): void {
		// The path is a local filesystem path, not a value: writing it into the
		// object would leak the importing machine's directory layout into the
		// customer's data. The file save has to happen after saveObject because
		// the savers address the object by ID.
		$dir                 = objectImporterTempDir();
		$this->scratchDirs[] = $dir;
		$image               = objectImporterWritePng($dir . '/hero.png');

		$handle = objectImporterHarness(['photo' => ['$ref' => objectImporterRef('image')]]);

		$handle->importer->importObject('blog', ['photo' => $image]);

		expect($handle->savedPayload['photo'])->toBe([])
			->and($handle->imageSaves)->toHaveCount(1)
			->and($handle->imageSaves[0]['property'])->toBe('photo')
			->and($handle->imageSaves[0]['id'])->toBe('post-1')
			->and($handle->imageSaves[0]['path'])->toBe($image);
	});

	it('skips a media path that does not exist rather than aborting the import', function (): void {
		// Half-migrated imports point at files that never made it across. The
		// object itself must still land — losing the whole record because one
		// image is missing is far worse than an empty image field.
		$handle = objectImporterHarness(['photo' => ['$ref' => objectImporterRef('image')]]);

		$result = $handle->importer->importObject('blog', ['photo' => '/no/such/file.png']);

		expect($handle->imageSaves)->toBe([])
			->and($result->id)->toBe('post-1');
	});

	it('applies an alt-text side-car file to the imported image', function (): void {
		// Total CMS 1 stored alt text in a sibling .cms/.txt file. Dropping it
		// loses accessibility metadata for every migrated image.
		$dir                 = objectImporterTempDir();
		$this->scratchDirs[] = $dir;
		$image               = objectImporterWritePng($dir . '/hero.png');
		file_put_contents($dir . '/hero.txt', 'A hero image');

		$handle = objectImporterHarness(['photo' => ['$ref' => objectImporterRef('image')]]);

		$handle->importer->importObject('blog', ['photo' => $image]);

		expect($handle->propertyPatches)->toHaveCount(1)
			->and($handle->propertyPatches[0]['property'])->toBe('photo')
			->and($handle->propertyPatches[0]['newData'])->toBe(['alt' => 'A hero image']);
	});

	it('imports gallery directories while skipping legacy thumbnails and non-images', function (): void {
		// Total CMS 1 galleries ship -th/-sq thumbnails beside the originals.
		// Importing those creates duplicate, low-res gallery entries the
		// customer then has to delete by hand.
		$dir                 = objectImporterTempDir();
		$this->scratchDirs[] = $dir;
		objectImporterWritePng($dir . '/one.png');
		objectImporterWritePng($dir . '/one-th.png');
		objectImporterWritePng($dir . '/one-sq.png');
		file_put_contents($dir . '/notes.txt', 'not an image');

		$handle = objectImporterHarness(['pics' => ['$ref' => objectImporterRef('gallery')]]);

		$handle->importer->importObject('blog', ['pics' => $dir]);

		expect($handle->gallerySaves)->toHaveCount(1)
			->and(basename($handle->gallerySaves[0]['path']))->toBe('one.png')
			->and($handle->gallerySaves[0]['property'])->toBe('pics');
	});

	it('applies gallery alt-text side-cars keyed by filename', function (): void {
		$dir                 = objectImporterTempDir();
		$this->scratchDirs[] = $dir;
		objectImporterWritePng($dir . '/one.png');
		file_put_contents($dir . '/one.cms', 'Gallery caption');

		$handle = objectImporterHarness(['pics' => ['$ref' => objectImporterRef('gallery')]]);

		$handle->importer->importObject('blog', ['pics' => $dir]);

		expect($handle->metaPatches)->toHaveCount(1)
			->and($handle->metaPatches[0]['name'])->toBe('one.png')
			->and($handle->metaPatches[0]['newData'])->toBe(['alt' => 'Gallery caption']);
	});

	it('imports every file in a depot directory', function (): void {
		$dir                 = objectImporterTempDir();
		$this->scratchDirs[] = $dir;
		file_put_contents($dir . '/a.pdf', 'a');
		file_put_contents($dir . '/b.zip', 'b');
		mkdir($dir . '/nested');

		$handle = objectImporterHarness(['downloads' => ['$ref' => objectImporterRef('depot')]]);

		$handle->importer->importObject('blog', ['downloads' => $dir]);

		$names = array_map(fn (array $save): string => basename($save['path']), $handle->depotSaves);
		sort($names);

		expect($names)->toBe(['a.pdf', 'b.zip']);
	});

	it('saves a single file property', function (): void {
		$dir                 = objectImporterTempDir();
		$this->scratchDirs[] = $dir;
		file_put_contents($dir . '/manual.pdf', 'pdf');

		$handle = objectImporterHarness(['manual' => ['$ref' => objectImporterRef('file')]]);

		$handle->importer->importObject('blog', ['manual' => $dir . '/manual.pdf']);

		expect($handle->savedPayload['manual'])->toBe([])
			->and($handle->fileSaves)->toHaveCount(1)
			->and($handle->fileSaves[0]['path'])->toBe($dir . '/manual.pdf');
	});

	it('expands DOCUMENT_ROOT and shell-escaped spaces in media paths', function (): void {
		// Customers paste paths straight out of a terminal ("Placeholder\ Images")
		// and JumpStart manifests use the DOCUMENT_ROOT token so they stay
		// portable between the source machine and the target server.
		$dir                 = objectImporterTempDir();
		$this->scratchDirs[] = $dir;
		mkdir($dir . '/My Images');
		$image                    = objectImporterWritePng($dir . '/My Images/hero.png');
		$_SERVER['DOCUMENT_ROOT'] = $dir;

		$handle = objectImporterHarness(['photo' => ['$ref' => objectImporterRef('image')]]);

		$handle->importer->importObject('blog', ['photo' => 'DOCUMENT_ROOT/My\ Images/hero.png']);

		expect($handle->imageSaves)->toHaveCount(1)
			->and($handle->imageSaves[0]['path'])->toBe($image);
	});

	it('skips gallery and depot properties that point at a missing directory', function (): void {
		// Same reasoning as the missing-image case: a partially copied media
		// tree must not cost the customer the whole record, and a
		// FilesystemIterator over a non-existent path throws.
		$handle = objectImporterHarness([
			'pics'      => ['$ref' => objectImporterRef('gallery')],
			'downloads' => ['$ref' => objectImporterRef('depot')],
		]);

		$result = $handle->importer->importObject('blog', [
			'pics'      => '/no/such/gallery',
			'downloads' => '/no/such/depot',
		]);

		expect($handle->gallerySaves)->toBe([])
			->and($handle->depotSaves)->toBe([])
			->and($result->id)->toBe('post-1');
	});

	it('resets pending media between imports so files do not leak across objects', function (): void {
		// The importer is a long-lived singleton reused for every row of an
		// import. If the pending-media arrays were not cleared, row 2 would
		// re-save row 1's image onto itself — cross-contaminating records.
		$dir                 = objectImporterTempDir();
		$this->scratchDirs[] = $dir;
		$image               = objectImporterWritePng($dir . '/hero.png');

		$handle = objectImporterHarness([
			'title' => ['type' => 'string'],
			'photo' => ['$ref' => objectImporterRef('image')],
		]);

		$handle->importer->importObject('blog', ['photo' => $image]);
		$handle->importer->importObject('blog', ['title' => 'No image here']);

		expect($handle->imageSaves)->toHaveCount(1);
	});
});

describe('ObjectImporter::importObject event lifecycle', function (): void {
	it('fires import.created and suppresses object.created', function (): void {
		// This is the documented contract for extension authors: during an
		// import they get import.* and NOT object.*. Breaking it either
		// double-fires listeners (search re-index storms, automation loops) or
		// silences them entirely.
		$handle = objectImporterHarness(['title' => ['type' => 'string']]);

		$handle->importer->importObject('blog', ['title' => 'Hi']);

		expect(objectImporterEventNames($handle))->toBe([CoreEvent::IMPORT_CREATED])
			->and($handle->suspendedDuringWrite)->toBeTrue();
	});

	it('carries the collection, id and stored object on the import.created payload', function (): void {
		$handle = objectImporterHarness(['title' => ['type' => 'string']]);

		$handle->importer->importObject('blog', ['title' => 'Hi']);

		$payload = $handle->events[0]['payload'];

		expect($payload['collection'])->toBe('blog')
			->and($payload['id'])->toBe('post-1')
			->and($payload['object'])->toBeInstanceOf(ObjectData::class);
	});

	it('releases its own suspension once the import finishes', function (): void {
		// A leaked suspension is invisible and permanent for the life of the
		// process: every later user-driven save on that collection would stop
		// firing object.created/object.updated.
		$handle = objectImporterHarness(['title' => ['type' => 'string']]);

		$handle->importer->importObject('blog', ['title' => 'Hi']);

		expect($handle->dispatcher->isImportSuspended('blog'))->toBeFalse();
	});

	it('releases its own suspension even when the write throws', function (): void {
		// Same failure mode, reached through the error path — this is why the
		// resume lives in a finally block.
		$handle = objectImporterHarness(
			['title' => ['type' => 'string']],
			['saveThrows' => new \RuntimeException('disk full')],
		);

		expect(fn () => $handle->importer->importObject('blog', ['title' => 'Hi']))
			->toThrow(\RuntimeException::class, 'disk full');

		expect($handle->dispatcher->isImportSuspended('blog'))->toBeFalse()
			->and(objectImporterEventNames($handle))->toBe([]);
	});

	it('leaves an outer batch suspension in place', function (): void {
		// Batch importers (JumpStart, JSON, CSV) own the lifecycle and resume
		// on import.completed. If a single-object import resumed here, the rest
		// of the batch would start firing object.created per row.
		$handle = objectImporterHarness(['title' => ['type' => 'string']]);
		$handle->dispatcher->suspendForImport('blog');

		$handle->importer->importObject('blog', ['title' => 'Hi']);

		expect($handle->dispatcher->isImportSuspended('blog'))->toBeTrue();
	});

	it('suspends only the collection being imported', function (): void {
		// Suspension is per-collection: an import into `blog` must not silence
		// events for an unrelated collection being written in the same process.
		$handle = objectImporterHarness(['title' => ['type' => 'string']]);

		$handle->importer->importObject('blog', ['title' => 'Hi']);

		expect($handle->dispatcher->isImportSuspended('products'))->toBeFalse();
	});
});

describe('ObjectImporter::importObject collection counters', function (): void {
	it('persists the collection count for a standalone import', function (): void {
		// object.created is suppressed, so CollectionMetadataListener never runs
		// and the counter that feeds auto-generated OIDs has to be maintained
		// here. Miss it and the next auto-ID collides with an existing object.
		$handle = objectImporterHarness(['title' => ['type' => 'string']]);

		$handle->importer->importObject('blog', ['title' => 'Hi']);

		expect($handle->counterCalls)->toBe([['persisted', 'blog']]);
	});

	it('only bumps the cached count inside a batch import', function (): void {
		// The batch optimisation: one disk write at the end of the import
		// instead of one per object. Writing per-object here is what made large
		// imports crawl.
		$handle = objectImporterHarness(['title' => ['type' => 'string']]);
		$handle->dispatcher->suspendForImport('blog');

		$handle->importer->importObject('blog', ['title' => 'Hi']);

		expect($handle->counterCalls)->toBe([['cached', 'blog']]);
	});

	it('does not touch the counter when the write fails', function (): void {
		// A counter bumped for an object that was never written inflates
		// totalObjects and skews every auto-generated ID after it.
		$handle = objectImporterHarness(
			['title' => ['type' => 'string']],
			['saveThrows' => new \RuntimeException('nope')],
		);

		expect(fn () => $handle->importer->importObject('blog', ['title' => 'Hi']))
			->toThrow(\RuntimeException::class);

		expect($handle->counterCalls)->toBe([]);
	});
});

describe('ObjectImporter::updateObject', function (): void {
	it('rejects a payload with no id', function (): void {
		// An update without an ID has nowhere to go — treating it as a create
		// would duplicate the record on every re-import.
		$handle = objectImporterHarness([
			'id'    => ['$ref' => objectImporterRef('slug')],
			'title' => ['type' => 'string'],
		], ['exists' => true]);

		expect(fn () => $handle->importer->updateObject('blog', ['title' => 'Hi']))
			->toThrow(\InvalidArgumentException::class, 'Object ID is required for updating');
	});

	it('rejects an id that does not exist', function (): void {
		// updateObject is patch-only. Silently creating here would bypass the
		// media/counter handling importObject() does and desync totalObjects.
		$handle = objectImporterHarness([
			'id'    => ['$ref' => objectImporterRef('slug')],
			'title' => ['type' => 'string'],
		], ['exists' => false]);

		expect(fn () => $handle->importer->updateObject('blog', ['id' => 'post-1', 'title' => 'Hi']))
			->toThrow(\InvalidArgumentException::class, 'Object does not exist');
	});

	it('rejects the payload when the schema has no id property', function (): void {
		// TRAP: the schema filter runs BEFORE the id check, so `id` survives
		// only because it is declared as a property. A custom schema without an
		// `id` field can therefore never be updated by an importer — it fails
		// with the misleading "Object ID is required" message.
		$handle = objectImporterHarness(['title' => ['type' => 'string']], ['exists' => true]);

		expect(fn () => $handle->importer->updateObject('blog', ['id' => 'post-1', 'title' => 'Hi']))
			->toThrow(\InvalidArgumentException::class, 'Object ID is required for updating');
	});

	it('slugifies the incoming id so it matches what import wrote', function (): void {
		// Importers receive human titles as IDs ("My First Post"). The stored
		// object is slugified, so an unslugified lookup would 404 and the
		// re-import would create a duplicate instead of updating.
		$handle = objectImporterHarness([
			'id'    => ['$ref' => objectImporterRef('slug')],
			'title' => ['type' => 'string'],
		], ['exists' => true]);

		$result = $handle->importer->updateObject('blog', ['id' => 'My First Post', 'title' => 'Hi']);

		expect($handle->patchedId)->toBe('my-first-post')
			->and($result->id)->toBe('my-first-post');
	});

	it('patches only schema-known fields', function (): void {
		// updateObject must patch, never replace: unknown keys are dropped and
		// fields absent from the payload are left untouched by ObjectPatcher.
		$handle = objectImporterHarness([
			'id'    => ['$ref' => objectImporterRef('slug')],
			'title' => ['type' => 'string'],
		], ['exists' => true]);

		$handle->importer->updateObject('blog', [
			'id'       => 'post-1',
			'title'    => 'Updated',
			'wp_extra' => 'junk',
		]);

		expect($handle->patchedPayload)->toBe(['id' => 'post-1', 'title' => 'Updated']);
	});

	it('fires import.updated with the previous state and suppresses object.updated', function (): void {
		// Extension authors diff previous vs current on import.updated. If
		// object.updated leaked out instead, listeners that assume a
		// user-driven edit (notifications, automations) would fire per row of
		// a bulk re-import.
		$handle = objectImporterHarness([
			'id'    => ['$ref' => objectImporterRef('slug')],
			'title' => ['type' => 'string'],
		], ['exists' => true]);

		$handle->importer->updateObject('blog', ['id' => 'post-1', 'title' => 'Updated']);

		expect(objectImporterEventNames($handle))->toBe([CoreEvent::IMPORT_UPDATED])
			->and($handle->suspendedDuringWrite)->toBeTrue()
			->and($handle->events[0]['payload']['previous'])->toBeInstanceOf(ObjectData::class)
			->and($handle->events[0]['payload']['collection'])->toBe('blog');
	});

	it('does not touch collection counters', function (): void {
		// An update is not a new object. Bumping the count here would inflate
		// totalObjects on every re-import of an existing dataset.
		$handle = objectImporterHarness([
			'id'    => ['$ref' => objectImporterRef('slug')],
			'title' => ['type' => 'string'],
		], ['exists' => true]);

		$handle->importer->updateObject('blog', ['id' => 'post-1', 'title' => 'Updated']);

		expect($handle->counterCalls)->toBe([]);
	});

	it('releases its own suspension after the update', function (): void {
		$handle = objectImporterHarness([
			'id'    => ['$ref' => objectImporterRef('slug')],
			'title' => ['type' => 'string'],
		], ['exists' => true]);

		$handle->importer->updateObject('blog', ['id' => 'post-1', 'title' => 'Updated']);

		expect($handle->dispatcher->isImportSuspended('blog'))->toBeFalse();
	});

	it('imports media side-cars on update too', function (): void {
		// A re-import that replaces an image must actually copy the new file,
		// otherwise the object points at the stale one forever.
		$dir                 = objectImporterTempDir();
		$this->scratchDirs[] = $dir;
		$image               = objectImporterWritePng($dir . '/hero.png');

		$handle = objectImporterHarness([
			'id'    => ['$ref' => objectImporterRef('slug')],
			'photo' => ['$ref' => objectImporterRef('image')],
		], ['exists' => true]);

		$handle->importer->updateObject('blog', ['id' => 'post-1', 'photo' => $image]);

		expect($handle->patchedPayload['photo'])->toBe([])
			->and($handle->imageSaves)->toHaveCount(1)
			->and($handle->imageSaves[0]['path'])->toBe($image);
	});
});
