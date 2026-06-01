# External Code Fields (`external: true`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an `external: true` setting to the `code` schema field so a field's value persists to a sibling file on disk (`<collection>/<id>/<property>/<property>.<ext>`) instead of inline in the object JSON, while behaving like a normal string property everywhere else (admin form, Twig, JumpStart, Sync).

**Architecture:** A single new `ExternalFieldStore` service owns the disk round-trip. `ObjectRepository::saveObject` calls it to write the sidecar file and blank the value in the canonical object JSON; `ObjectRepository::fetchObject`/`fetchObjectFromDisk` call it to hydrate the value back from disk. No JumpStart changes are needed: the exporter loads objects via `fetchObject` (hydrated → `toArray()` inlines the value), and the importer flows through the normal save pipeline (re-externalizes). Object delete/duplicate already operate on the whole `<id>/` directory, so file lifecycle is free.

**Tech Stack:** PHP 8.2+, PHP-DI 7, Flysystem (`StorageAdapterInterface`), Pest. This is Plan 1 of 4 for the Automations feature (see `docs/planning/automations.md`); it ships a reusable primitive with no dependency on the rest.

> **Plan location note:** Saved under `docs/planning/` to sit beside the spec (`automations.md`), matching this project's established planning home rather than the skill default `docs/superpowers/plans/`.

---

## File Structure

- **Create** `src/Domain/Property/Service/ExternalFieldStore.php` — the only new unit. Resolves which schema fields are external, builds their sidecar paths, writes (persist) and reads (hydrate) them.
- **Create** `tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php` — unit tests for the pure field-resolution + path logic.
- **Create** `tests/Integration/ExternalFieldRoundTripTest.php` — end-to-end save → on-disk shape → fetch → JumpStart export/import round-trip.
- **Modify** `src/Domain/Object/Repository/ObjectRepository.php` — inject `ExternalFieldStore`; wire persist into `saveObject`, hydrate into `fetchObject` + `fetchObjectFromDisk`.
- **Modify** `config/container.php` (or the relevant DI definitions file) — register `ExternalFieldStore` and add it to `ObjectRepository`'s constructor args if the container uses explicit definitions.

---

## Conventions to follow (verified in the codebase)

- **Tabs for indentation**, camelCase methods, constructor property promotion with visibility, explicit return types (PHPStan L8).
- **Path building:** `TotalCMS\Infrastructure\Filesystem\PathUtils::buildPath(collection: $c, objectID: $id, property: $p, filename: $f)` → `c/id/p/f`. Paths are relative to the data dir; the `StorageAdapterInterface` filesystem is rooted there.
- **Schema shape:** `$schema->properties` is `array<string, array{field?:string, type?:string, settings?:array}>` (see `ObjectFactory::generateProperties`). The `code` field carries `settings: { mode, rows, external }` (see `resources/schemas/mcp-prompt.json` `body`).
- **Property value:** an external `code` field is a `CodeData` (`src/Domain/Property/Data/CodeData.php`) with a public `string $code`; `(string)$property` yields the code, and `$property->code = '...'` sets it.
- **Filesystem:** `StorageAdapterInterface` exposes `write($path, $contents)` (auto-creates dirs), `read($path)`, `fileExists($path)`.
- **Tests:** Pest with `expect()`; integration tests use `recursiveDelete(cmsDataDir())` in `beforeEach` and `$this->setUpApp(bootstrap())` to get the real DI container. Resolve services with `app()->get(Foo::class)` (the container helper) — verify the exact accessor in an existing integration test before Task 5.

---

## Task 1: `ExternalFieldStore::externalFields()` — pure field resolution

**Files:**
- Create: `src/Domain/Property/Service/ExternalFieldStore.php`
- Test: `tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use TotalCMS\Domain\Property\Service\ExternalFieldStore;

function makeStore(): ExternalFieldStore
{
    // SchemaFetcher + filesystem are only used by persist()/hydrate(), not externalFields().
    return new ExternalFieldStore(
        test()->createMock(\TotalCMS\Domain\Storage\StorageAdapterInterface::class),
        test()->createMock(\TotalCMS\Domain\Schema\Service\SchemaFetcher::class),
    );
}

it('returns external code fields mapped to their file extension', function (): void {
    $properties = [
        'title'   => ['type' => 'string', 'field' => 'text'],
        'handler' => ['type' => 'string', 'field' => 'code', 'settings' => ['mode' => 'php', 'external' => true]],
        'body'    => ['type' => 'string', 'field' => 'code', 'settings' => ['mode' => 'twig', 'external' => true]],
        'notes'   => ['type' => 'string', 'field' => 'code', 'settings' => ['mode' => 'twig']], // not external
    ];

    expect(makeStore()->externalFields($properties))->toBe([
        'handler' => 'php',
        'body'    => 'twig',
    ]);
});

it('defaults unknown or missing modes to txt and ignores non-code fields', function (): void {
    $properties = [
        'a' => ['field' => 'code', 'settings' => ['external' => true]],                 // no mode
        'b' => ['field' => 'code', 'settings' => ['mode' => 'ruby', 'external' => true]], // unknown mode
        'c' => ['field' => 'image', 'settings' => ['external' => true]],                  // not code
    ];

    expect(makeStore()->externalFields($properties))->toBe([
        'a' => 'txt',
        'b' => 'txt',
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php`
Expected: FAIL — `Class "TotalCMS\Domain\Property\Service\ExternalFieldStore" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\CodeData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

/**
 * Persists `code` fields flagged `external: true` to a sibling file on disk
 * (`<collection>/<id>/<property>/<property>.<ext>`) instead of inline in the
 * object JSON, and hydrates them back on read.
 */
readonly class ExternalFieldStore
{
	private const EXT_BY_MODE = [
		'php'        => 'php',
		'twig'       => 'twig',
		'javascript' => 'js',
		'js'         => 'js',
		'css'        => 'css',
		'html'       => 'html',
		'json'       => 'json',
	];

	public function __construct(
		private StorageAdapterInterface $filesystem,
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/**
	 * Resolve which schema properties are external code fields.
	 *
	 * @param array<string,array<string,mixed>> $properties Schema `->properties`
	 *
	 * @return array<string,string> propertyName => file extension
	 */
	public function externalFields(array $properties): array
	{
		$external = [];

		foreach ($properties as $name => $definition) {
			$settings = $definition['settings'] ?? [];

			if (($definition['field'] ?? '') !== 'code') {
				continue;
			}
			if (($settings['external'] ?? false) !== true) {
				continue;
			}

			$mode            = (string)($settings['mode'] ?? '');
			$external[$name] = self::EXT_BY_MODE[$mode] ?? 'txt';
		}

		return $external;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php`
Expected: PASS (2 passing).

- [ ] **Step 5: Run static analysis**

Run: `composer run stan -- src/Domain/Property/Service/ExternalFieldStore.php`
Expected: No errors (Level 8).

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Property/Service/ExternalFieldStore.php tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php
git commit -m "feat(fields): ExternalFieldStore resolves external code fields"
```

---

## Task 2: `ExternalFieldStore::sidecarPath()` — path building

**Files:**
- Modify: `src/Domain/Property/Service/ExternalFieldStore.php`
- Test: `tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php`

- [ ] **Step 1: Write the failing test** (append to the test file)

```php
it('builds the sidecar path as collection/id/property/property.ext', function (): void {
    expect(makeStore()->sidecarPath('automations', 'process-monthly', 'handler', 'php'))
        ->toBe('automations/process-monthly/handler/handler.php');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php`
Expected: FAIL — `Call to undefined method ExternalFieldStore::sidecarPath()`.

- [ ] **Step 3: Add the method** (inside `ExternalFieldStore`)

```php
	/**
	 * Build the relative on-disk path for an external field's sidecar file.
	 */
	public function sidecarPath(string $collection, string $id, string $property, string $ext): string
	{
		return PathUtils::buildPath(
			collection: $collection,
			objectID: $id,
			property: $property,
			filename: $property . '.' . $ext,
		);
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php`
Expected: PASS (3 passing).

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Property/Service/ExternalFieldStore.php tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php
git commit -m "feat(fields): ExternalFieldStore sidecar path builder"
```

---

## Task 3: `persist()` and `hydrate()` — disk round-trip

**Files:**
- Modify: `src/Domain/Property/Service/ExternalFieldStore.php`
- Test: `tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php`

`persist()` writes each external field's value to its sidecar and returns the property names that were externalized (so the caller can blank them in the canonical JSON). `hydrate()` reads each sidecar back into the live `CodeData`. Both resolve the schema via `SchemaFetcher::fetchSchemaForCollection`.

- [ ] **Step 1: Write the failing test** (append)

```php
use TotalCMS\Domain\Property\Data\CodeData;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Storage\StorageAdapterInterface;

/**
 * Build a store whose SchemaFetcher returns a schema with one external `handler`
 * field, backed by an in-memory fake filesystem (array of path => contents).
 *
 * @param array<string,string> $files passed by reference to observe writes
 */
function storeWithSchema(array &$files): ExternalFieldStore
{
    $schema = SchemaData::fromArray([
        'id'         => 'automations',
        'properties' => [
            'id'      => ['type' => 'string', 'field' => 'text'],
            'handler' => ['type' => 'string', 'field' => 'code', 'settings' => ['mode' => 'php', 'external' => true]],
        ],
    ]);

    $fetcher = test()->createMock(SchemaFetcher::class);
    $fetcher->method('fetchSchemaForCollection')->willReturn($schema);

    $fs = test()->createMock(StorageAdapterInterface::class);
    $fs->method('write')->willReturnCallback(function (string $path, string $contents) use (&$files): void {
        $files[$path] = $contents;
    });
    $fs->method('fileExists')->willReturnCallback(fn (string $path): bool => isset($files[$path]));
    $fs->method('read')->willReturnCallback(fn (string $path): string => $files[$path] ?? '');

    return new ExternalFieldStore($fs, $fetcher);
}

it('persists external field values to sidecar files and reports the field names', function (): void {
    $files = [];
    $store = storeWithSchema($files);

    $object = new ObjectData('process-monthly', [
        'handler' => new CodeData('<?php return fn($ctx) => 1;', ['mode' => 'php', 'external' => true]),
    ]);

    $blanked = $store->persist('automations', $object);

    expect($blanked)->toBe(['handler']);
    expect($files['automations/process-monthly/handler/handler.php'])
        ->toBe('<?php return fn($ctx) => 1;');
});

it('hydrates external field values from sidecar files into the object', function (): void {
    $files = ['automations/process-monthly/handler/handler.php' => '<?php return 42;'];
    $store = storeWithSchema($files);

    // Object loaded from blanked JSON: handler is empty until hydrated.
    $object = new ObjectData('process-monthly', [
        'handler' => new CodeData('', ['mode' => 'php', 'external' => true]),
    ]);

    $store->hydrate('automations', $object);

    expect((string)$object->properties->get('handler'))->toBe('<?php return 42;');
});
```

> **Note:** confirm `SchemaData::fromArray()` exists with this shape before running. If the constructor differs, build the `SchemaData` via the same path `SchemaFactory` uses (`generateSchemaFromJson(json_encode([...]))`) — check `src/Domain/Schema/` and adjust the helper. The assertions stay the same.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php`
Expected: FAIL — `Call to undefined method ExternalFieldStore::persist()`.

- [ ] **Step 3: Add `persist()` and `hydrate()`** (inside `ExternalFieldStore`)

```php
	/**
	 * Write each external field's value to its sidecar file.
	 *
	 * @return list<string> property names that were externalized (to blank in JSON)
	 */
	public function persist(string $collection, ObjectData $object): array
	{
		$fields    = $this->externalFields($this->schemaFetcher->fetchSchemaForCollection($collection)->properties);
		$persisted = [];

		foreach ($fields as $name => $ext) {
			$property = $object->properties->get($name);
			if ($property === null) {
				continue;
			}

			$this->filesystem->write($this->sidecarPath($collection, $object->id, $name, $ext), (string)$property);
			$persisted[] = $name;
		}

		return $persisted;
	}

	/**
	 * Read each external field's value from its sidecar file back into the object.
	 */
	public function hydrate(string $collection, ObjectData $object): void
	{
		$fields = $this->externalFields($this->schemaFetcher->fetchSchemaForCollection($collection)->properties);

		foreach ($fields as $name => $ext) {
			$property = $object->properties->get($name);
			if (!$property instanceof CodeData) {
				continue;
			}

			$path = $this->sidecarPath($collection, $object->id, $name, $ext);
			if ($this->filesystem->fileExists($path)) {
				$property->code = $this->filesystem->read($path);
			}
		}
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php`
Expected: PASS (5 passing).

- [ ] **Step 5: Static analysis**

Run: `composer run stan -- src/Domain/Property/Service/ExternalFieldStore.php`
Expected: No errors.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Property/Service/ExternalFieldStore.php tests/Unit/Domain/Property/Service/ExternalFieldStoreTest.php
git commit -m "feat(fields): ExternalFieldStore persist + hydrate sidecar files"
```

---

## Task 4: Wire `ExternalFieldStore` into `ObjectRepository`

**Files:**
- Modify: `src/Domain/Object/Repository/ObjectRepository.php` (constructor; `saveObject` line ~64; `fetchObject` lines 84/95/108; `fetchObjectFromDisk` line 135)
- Modify: `config/container.php` (register service + add constructor arg if definitions are explicit)

- [ ] **Step 1: Add the constructor dependency**

In `ObjectRepository::__construct`, add the parameter after `IndexRepository $indexRepository`:

```php
		private readonly IndexRepository $indexRepository,
		private readonly \TotalCMS\Domain\Property\Service\ExternalFieldStore $externalFields,
	) {
		parent::__construct($filesystem);
	}
```

- [ ] **Step 2: Persist + blank in `saveObject`**

Replace the write block (current lines 62-64):

```php
		$objectFile = $this->buildObjectPath($collection, $object->id);

		$this->filesystem->write($objectFile, $object->toJson());
```

with:

```php
		$objectFile = $this->buildObjectPath($collection, $object->id);

		// Externalize `external: true` code fields to sidecar files, then blank
		// them in the canonical object JSON so the value lives in one place.
		$persisted = $this->externalFields->persist($collection, $object);

		if ($persisted === []) {
			$this->filesystem->write($objectFile, $object->toJson());
		} else {
			$data = $object->toArray();
			foreach ($persisted as $name) {
				$data[$name] = '';
			}
			$this->filesystem->write(
				$objectFile,
				(string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			);
		}
```

- [ ] **Step 3: Add a hydration helper and use it on every read path**

Add this private method (e.g. just below `fetchObjectFromDisk`):

```php
	/**
	 * Build an ObjectData from raw JSON contents, then hydrate any external
	 * (file-backed) fields from their sidecar files.
	 *
	 * @param array<string,mixed> $contents
	 */
	private function buildObject(string $collection, array $contents): ObjectData
	{
		$object = $this->factory->generateObject($collection, $contents);
		$this->externalFields->hydrate($collection, $object);

		return $object;
	}
```

Then replace each `return $this->factory->generateObject($collection, ...)` in `fetchObject` (lines 84, 95, 108) and `fetchObjectFromDisk` (line 135) with `return $this->buildObject($collection, ...)`, keeping the same array argument. Concretely:

- Line 84: `return $this->buildObject($collection, $this->requestCache[$cacheKey]);`
- Line 95: `return $this->buildObject($collection, $cached);`
- Line 108: `return $this->buildObject($collection, $contents);`
- Line 135: `return $this->buildObject($collection, $contents);`

- [ ] **Step 4: Register in the container**

In `config/container.php`, ensure `ExternalFieldStore` is constructable (its deps `StorageAdapterInterface` + `SchemaFetcher` are already registered). If `ObjectRepository` has an explicit factory definition listing constructor args, append `$c->get(ExternalFieldStore::class)`. If the container autowires, no change is needed beyond confirming autowiring is on for this namespace. Verify by grepping:

Run: `grep -n "ObjectRepository::class\|ExternalFieldStore" config/container.php`
Expected: either an autowired definition (no arg list) or a factory you extend with the new arg.

- [ ] **Step 5: Static analysis**

Run: `composer run stan -- src/Domain/Object/Repository/ObjectRepository.php`
Expected: No errors. (If "container compiled closure" style errors appear, this is plain constructor injection — not a closure — so it should be clean.)

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Object/Repository/ObjectRepository.php config/container.php
git commit -m "feat(fields): wire ExternalFieldStore into object save/fetch"
```

---

## Task 5: Integration round-trip test (save → disk → fetch → JumpStart)

This is the proof the whole feature works, including the claim that JumpStart needs no changes. It creates a real collection with an external `handler` field, saves an object, and asserts: (a) the sidecar file holds the code, (b) the canonical `<id>.json` does NOT contain the code, (c) a fresh `fetchObject` returns the full code, (d) a JumpStart export → wipe → import round-trip preserves the code on disk.

**Files:**
- Create: `tests/Integration/ExternalFieldRoundTripTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\JumpStart\Service\JumpStartImporter;

beforeEach(function (): void {
    recursiveDelete(cmsDataDir());
    $this->setUpApp(bootstrap());
});

it('externalizes a code field to a sidecar, blanks the JSON, and round-trips through JumpStart', function (): void {
    $handler = "<?php\n\nreturn function (\$ctx) {\n    return ['ok' => true];\n};\n";

    // 1. Create a collection whose schema has an external code field.
    //    (Mirror how other integration tests build a collection + schema; the
    //    schema below is the contract this feature must support.)
    $schema = [
        'id'         => 'widgets',
        'properties' => [
            'id'      => ['type' => 'string', 'field' => 'text'],
            'title'   => ['type' => 'string', 'field' => 'text'],
            'handler' => ['type' => 'string', 'field' => 'code', 'settings' => ['mode' => 'php', 'external' => true]],
        ],
    ];
    createCollectionWithSchema('widgets', $schema); // helper: see Step note

    // 2. Save an object carrying the handler code.
    $saver = app()->get(ObjectSaver::class);
    $saver->saveObject('widgets', ['id' => 'alpha', 'title' => 'Alpha', 'handler' => $handler]);

    // (a) sidecar holds the code
    $sidecar = cmsDataDir() . 'widgets/alpha/handler/handler.php';
    expect(file_exists($sidecar))->toBeTrue();
    expect(file_get_contents($sidecar))->toBe($handler);

    // (b) canonical JSON does NOT contain the code
    $json = file_get_contents(cmsDataDir() . 'widgets/alpha.json');
    expect($json)->not->toContain('return function');
    expect(json_decode($json, true)['handler'])->toBe('');

    // (c) a fresh fetch returns the full code
    $fetched = app()->get(ObjectFetcher::class)->fetchObject('widgets', 'alpha');
    expect((string)$fetched->properties->get('handler'))->toBe($handler);

    // (d) JumpStart export carries the code inline; import re-externalizes it
    $export = app()->get(JumpStartExporter::class)->exportCollection('widgets'); // adjust to real export API
    expect($export)->toContain('return function');

    recursiveDelete(cmsDataDir());
    createCollectionWithSchema('widgets', $schema);
    app()->get(JumpStartImporter::class)->import($export); // adjust to real import API

    expect(file_get_contents(cmsDataDir() . 'widgets/alpha/handler/handler.php'))->toBe($handler);
});
```

> **Step note (helpers/API):** Before running, confirm three things against existing code and adjust the test (assertions unchanged):
> 1. How integration tests create a collection + schema. Search `tests/Integration` for `CollectionSaver` or a `createCollection` helper; define `createCollectionWithSchema()` at the top of the test (or in `tests/Pest.php`) using whatever real path exists (write the schema JSON to the schemas dir + `CollectionSaver`).
> 2. The real `JumpStartExporter` export method name/signature (Task research showed `processObjectData` is private; find the public `export*`).
> 3. The real `JumpStartImporter` entry method (e.g. `import(string $json)` vs an array). The changelog references `processObjects`; find the public entry point.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Integration/ExternalFieldRoundTripTest.php`
Expected: FAIL — initially on the helper/API names; once those are fixed, it should fail only if the feature is wrong. After Tasks 1–4 it should pass.

- [ ] **Step 3: Make it pass**

No new production code expected — Tasks 1–4 implement the behavior. Fix only the test's helper/API calls (Step note) until green. If (b) fails because the JSON still contains the code, re-check Task 4 Step 2 blanking. If (c) fails, re-check the `buildObject` hydration wiring (Task 4 Step 3). If (d) export lacks the code, confirm the exporter loads via `fetchObject` (it does — `JumpStartExporter` lines 169/251) and that hydration is wired.

- [ ] **Step 4: Run the full object + jumpstart suites for regressions**

Run: `vendor/bin/pest tests/Unit/Domain/Object tests/Integration/ExternalFieldRoundTripTest.php`
Expected: PASS, no regressions in existing object tests.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/ExternalFieldRoundTripTest.php tests/Pest.php
git commit -m "test(fields): external code field round-trip incl. JumpStart"
```

---

## Task 6: Documentation

**Files:**
- Modify: `resources/docs/fields/` (the `code` field doc — find it: `grep -rl "field.*code" resources/docs/fields`)

- [ ] **Step 1: Document the `external` setting**

Add a short subsection to the `code` field documentation describing `settings.external: true`: the value is stored at `<collection>/<id>/<property>/<property>.<ext>` (extension from `mode`), is editable like any code field, travels through Sync/JumpStart inline, and is removed/duplicated with the object automatically. No code; prose only.

- [ ] **Step 2: Rebuild the docs search index** (only if a doc page was added/renamed; an edit to an existing page may not require it — check `bin/build-docs-index.php` usage)

Run: `php bin/build-docs-index.php`
Expected: `resources/docs/search-index.json` updated.

- [ ] **Step 3: Commit**

```bash
git add resources/docs/ 
git commit -m "docs(fields): document external: true code field storage"
```

---

## Self-Review

**Spec coverage** (against `automations.md` → "Why the handler is externalized" + Sync section):
- External `code` field persists to `<collection>/<id>/<property>/<property>.<ext>` — Tasks 2–4. ✓
- Field behaves like a normal string everywhere (admin/Twig) — it stays a `CodeData`; only repo read/write diverts. ✓
- JumpStart/Sync carry the value inline (no binary-style nulling) — Task 5 (d) proves it, no exporter change. ✓
- Lazy hydration (not on bulk index reads) — hydrate runs in `fetchObject`/`fetchObjectFromDisk`, not in index/bulk paths. ✓ (Note: index building reads via `fetchObjectFromDisk`, which now hydrates one object at a time on demand — acceptable; index fields rarely include the handler. If profiling shows cost, add an opt-out, but YAGNI for now.)
- Lifecycle (delete/duplicate) — free via existing `deleteObject`/`copyObjectFiles` on the `<id>/` dir. ✓ (No task needed; called out so the executor doesn't add redundant code.)

**Placeholder scan:** No "TODO"/"handle edge cases". The three "confirm the real API name" notes in Task 5 are deliberate verification steps against the live codebase, with exact search commands and unchanged assertions — not hand-waving.

**Type consistency:** `externalFields(array): array<string,string>`, `sidecarPath(...): string`, `persist(...): list<string>`, `hydrate(...): void`, `buildObject(string, array): ObjectData` — names/signatures match across Tasks 1–4. `CodeData->code` (public string) used consistently in Task 3 and hydrate.

**Open risk to verify during execution:** `SchemaData::fromArray()` shape (Task 3 note) and the container wiring style (Task 4 Step 4). Both have explicit fallback instructions.
