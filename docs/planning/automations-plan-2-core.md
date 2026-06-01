# Automations Core Implementation Plan (Plan 2 of 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the `automations` reserved collection, an `AutomationContext` (pre-injected services), an `AutomationLoader` (resolves automation objects + their handler closures), an `AutomationRunner` (executes a handler with run-history + per-trigger state), and a dedicated `tcms automations:process` CLI command that fires due **schedule** triggers with single-flight locking. Webhook/event triggers are Plan 3; admin UI + hardening is Plan 4.

**Architecture:** An automation is one object in the reserved `automations` collection; its `handler` is an `external: true` `code` field (Plan 1) stored at `tcms-data/automations/<slug>/handler/handler.php`, which `return`s a closure. The runner `require`s that file by path (never a container definition) and invokes it with an `AutomationContext`. Schedule due-detection + execution run in a dedicated CLI command on its own cron line, parallel to `jobs:process`, with per-trigger last-fire state in `tcms-data/.system/automations/<slug>.state.json` and run records under `.../runs/`.

**Tech Stack:** PHP 8.2+, Symfony Console (via `CliApplication`/`BaseCommand`), PHP-DI 7, `dragonmantank/cron-expression` (new dep), Monolog (`LoggerFactory`), Pest.

**Depends on:** Plan 1 (external code fields). **Blocks:** Plan 3, Plan 4.

---

## File Structure

- **Create** `resources/schemas/automations.json` — reserved schema (id, name, description, enabled, triggers deck, errorEmail, handler external code field).
- **Modify** `src/Domain/Schema/Data/SchemaData.php` — add `'automations'` to `RESERVED_SCHEMAS`.
- **Create** `src/Domain/Migration/Migration/EnsureAutomationsCollectionMigration.php` — mirrors `EnsureMcpPromptCollectionMigration`.
- **Create** `src/Domain/Automation/Data/AutomationContext.php` — service bundle + per-trigger payload.
- **Create** `src/Domain/Automation/Data/RunRecord.php` — run-history value object.
- **Create** `src/Domain/Automation/Service/AutomationStateStore.php` — `<slug>.state.json` read/write (last-fire, failure counters).
- **Create** `src/Domain/Automation/Service/AutomationLoader.php` — list enabled automations, resolve + `require` a handler closure.
- **Create** `src/Domain/Automation/Service/AutomationRunner.php` — execute a handler, persist a `RunRecord`, update state.
- **Create** `src/Domain/Automation/Service/ScheduleTicker.php` — find due schedule triggers via cron.
- **Create** `src/CLI/Command/AutomationsProcessCommand.php` — the dedicated runner; single-flight lock.
- **Modify** `src/CLI/CliApplication.php` — register the command.
- **Modify** `src/Support/Config.php` — add `public array $automations = []` + deep-merge.
- **Modify** `composer.json` — add `dragonmantank/cron-expression`.
- **Modify** `config/container.php` — register the new services.
- Tests under `tests/Unit/Domain/Automation/` and `tests/Integration/`.

---

## Conventions (verified)

- CLI command: extend `BaseCommand` (constructor `TotalCMS $totalcms`), `configure()` sets name/desc, `execute()` returns `Command::SUCCESS`. `--json` via `$this->isJson($input)`. Services via `$this->totalcms->container->get(X::class)`. Register in `CliApplication` with `$app->addCommand(new Command\AutomationsProcessCommand($totalcms))` (`src/CLI/CliApplication.php:40-100`).
- Single-flight: `flock(LOCK_EX|LOCK_NB)` on a lock file (`src/CLI/Command/JobsProcessCommand.php:42-63`).
- Reserved collection: add to `RESERVED_SCHEMAS`, ship the JSON, create via `CollectionFetcher::fetchOrCreateReserved('automations')` (`src/Domain/Collection/Service/CollectionFetcher.php:68-84`) — a migration calls it (`EnsureMcpPromptCollectionMigration`).
- State files: atomic temp-write + `move` (`src/Domain/Extension/Repository/ExtensionStateRepository.php` `persist()`).
- Logger: `$loggerFactory->addFileHandler('automations.log')->createLogger('automations')` (`src/Factory/LoggerFactory.php:57-72`).
- Handler closure: stored file is full PHP — `<?php\n\nreturn function (AutomationContext $ctx) { ... };` — so `require $path` yields the closure.

---

## Task 1: Add the cron dependency + `automations` config section

**Files:** Modify `composer.json`, `src/Support/Config.php`

- [ ] **Step 1: Add the dependency**

Run: `composer require dragonmantank/cron-expression:^3.3`
Expected: added to `require`; `composer.lock` updated. (Confirm it resolves on PHP 8.2.)

- [ ] **Step 2: Write a failing test for the config section**

Create `tests/Unit/Support/AutomationsConfigTest.php`:

```php
<?php

declare(strict_types=1);

use TotalCMS\Support\Config;

it('exposes an automations config array that deep-merges overrides', function (): void {
    $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
    $config->automations = ['urlPrefix' => '/automations', 'runHistoryLimit' => 100];

    expect($config->automations['urlPrefix'])->toBe('/automations');
    expect($config->automations['runHistoryLimit'])->toBe(100);
});
```

- [ ] **Step 3: Run it (fails — property undefined)**

Run: `vendor/bin/pest tests/Unit/Support/AutomationsConfigTest.php`
Expected: FAIL — `Undefined property: ...::$automations`.

- [ ] **Step 4: Add the property + merge**

In `src/Support/Config.php`, alongside the other `public array $...` declarations (near `$mcp`, `$oauth`):

```php
	public array $automations = [];
```

And in the constructor where sections are merged (mirroring `$this->mcp = is_array($settings['mcp'] ?? null) ? $settings['mcp'] : [];`):

```php
		$this->automations = is_array($settings['automations'] ?? null) ? $settings['automations'] : [];
```

- [ ] **Step 5: Run it (passes)** — `vendor/bin/pest tests/Unit/Support/AutomationsConfigTest.php` → PASS.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock src/Support/Config.php tests/Unit/Support/AutomationsConfigTest.php
git commit -m "feat(automations): add cron dependency + automations config section"
```

---

## Task 2: Reserved schema + collection bootstrap

**Files:** Create `resources/schemas/automations.json`; Modify `src/Domain/Schema/Data/SchemaData.php`; Create `EnsureAutomationsCollectionMigration.php`

- [ ] **Step 1: Add to RESERVED_SCHEMAS**

In `src/Domain/Schema/Data/SchemaData.php` `RESERVED_SCHEMAS` (lines 25-55), add `'automations',` (keep alphabetical-ish ordering consistent with neighbours).

- [ ] **Step 2: Create the schema file**

Create `resources/schemas/automations.json` (model on `mcp-prompt.json`; `triggers` is a deck like `args`, `handler` is an external code field):

```json
{
	"$schema": "https://json-schema.org/draft/2020-12/schema",
	"$id": "https://www.totalcms.co/schemas/automations.json",
	"id": "automations",
	"type": "object",
	"title": "Automation",
	"description": "A user-authored, server-side automation fired by schedule, webhook, or event triggers.",
	"formgrid": "id\nname\ndescription\nenabled\nerrorEmail\ntriggers\nhandler",
	"required": ["id", "name"],
	"index": ["id", "name", "enabled"],
	"properties": {
		"id": {
			"$ref": "https://www.totalcms.co/schemas/properties/slug.json",
			"label": "ID",
			"help": "URL-safe identifier (also the webhook slug + handler folder name).",
			"field": "id",
			"factory": "slug",
			"pattern": "^[a-z][a-z0-9-]*$",
			"maxLength": 64
		},
		"name": {
			"type": "string",
			"label": "Name",
			"field": "text"
		},
		"description": {
			"type": "string",
			"label": "Description",
			"field": "textarea",
			"settings": {"rows": 2}
		},
		"enabled": {
			"type": "boolean",
			"label": "Enabled",
			"field": "toggle",
			"default": true
		},
		"errorEmail": {
			"type": "string",
			"label": "Error notification email",
			"help": "Optional. Sent on uncaught handler exception (Production).",
			"field": "text"
		},
		"triggers": {
			"field": "deck",
			"label": "Triggers",
			"help": "One or more of: schedule (cron), webhook (HTTP), event (T3 events).",
			"schemaref": "https://www.totalcms.co/schemas/automation-trigger.json",
			"$ref": "https://www.totalcms.co/schemas/properties/deck.json"
		},
		"handler": {
			"$ref": "https://www.totalcms.co/schemas/properties/code.json",
			"label": "Handler",
			"help": "PHP returning a closure: <code>return function (AutomationContext $ctx) { ... };</code>",
			"field": "code",
			"settings": {"mode": "php", "rows": 24, "external": true}
		}
	}
}
```

> **Critical (verified during Plan 1 execution):** the `handler` field MUST use the `code.json` `$ref` (no `type`) so it resolves to `CodeData`. A `field: code` with `type: string` resolves to `StringData`, whose constructor HTML-sanitizes and `trim()`s the value — silently corrupting PHP handlers (mangles `<?php`, strips the trailing newline). `PropertyDefinition::resolveType()` reverse-maps the `$ref` to `code` (priority over `type`); `ExternalFieldStore::hydrate()` only repopulates `CodeData`. This is why the integration test `tests/Integration/ExternalFieldRoundTripTest.php` uses the `$ref` form.
>
> **Verify:** the `slug.json` / `deck.json` `$ref` URLs and the deck `schemaref` mechanism resolve the same way `mcp-prompt.json` uses them. Create `resources/schemas/automation-trigger.json` (a deck-item schema) with fields `type` (select: schedule/webhook/event), `cron`, `timezone`, `slug`, `auth` (select: apiKey/none), `methods`, `sync` (toggle), `event` (select of the 17), `collection`, `priority`. Only `type` is required; the rest are conditionally meaningful and validated in Plan 3. Model it on `mcp-prompt-arg.json`.

- [ ] **Step 3: Create the migration**

Create `src/Domain/Migration/Migration/EnsureAutomationsCollectionMigration.php`, mirroring `EnsureMcpPromptCollectionMigration` (read that file first for the exact interface + constructor):

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Migration;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;

final readonly class EnsureAutomationsCollectionMigration implements MigrationInterface
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private EditionFeatureService $editionFeatures,
	) {
	}

	public function id(): string
	{
		return 'ensure-automations-collection';
	}

	public function run(): int
	{
		// EditionFeature::AUTOMATIONS is added in Plan 4. Until then, gate on a
		// feature that already exists or remove this guard; re-add in Plan 4.
		if (!$this->editionFeatures->can(EditionFeature::AUTOMATIONS)) {
			return 0;
		}

		$before = $this->collectionFetcher->collectionExists('automations');
		$this->collectionFetcher->fetchOrCreateReserved('automations');

		return $before ? 0 : 1;
	}
}
```

> **Sequencing note:** `EditionFeature::AUTOMATIONS` is introduced in Plan 4. If executing Plan 2 before Plan 4, drop the edition guard here (always ensure the collection) and re-add it in Plan 4. Register the migration wherever `EnsureMcpPromptCollectionMigration` is registered (find it: `grep -rn EnsureMcpPromptCollectionMigration config src`).

- [ ] **Step 4: Test the schema loads + collection creates**

Create `tests/Integration/AutomationsCollectionTest.php`:

```php
<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;

beforeEach(function (): void {
    recursiveDelete(cmsDataDir());
    $this->setUpApp(bootstrap());
});

it('creates the reserved automations collection on demand', function (): void {
    $fetcher = app()->get(CollectionFetcher::class);
    expect($fetcher->collectionExists('automations'))->toBeFalse();

    $fetcher->fetchOrCreateReserved('automations');

    expect($fetcher->collectionExists('automations'))->toBeTrue();
});
```

- [ ] **Step 5: Run it** — `vendor/bin/pest tests/Integration/AutomationsCollectionTest.php` → PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/schemas/automations.json resources/schemas/automation-trigger.json src/Domain/Schema/Data/SchemaData.php src/Domain/Migration/Migration/EnsureAutomationsCollectionMigration.php tests/Integration/AutomationsCollectionTest.php
git commit -m "feat(automations): reserved automations schema + collection bootstrap"
```

---

## Task 3: `AutomationContext`

**Files:** Create `src/Domain/Automation/Data/AutomationContext.php`; Test `tests/Unit/Domain/Automation/AutomationContextTest.php`

The context is the only API surface a handler sees. Schedule runs leave `request`/`event` null; Plan 3 populates them for webhook/event.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use TotalCMS\Domain\Automation\Data\AutomationContext;

it('exposes injected services and per-trigger payload slots', function (): void {
    $ctx = new AutomationContext(
        indexReader: test()->createMock(\TotalCMS\Domain\Index\Service\IndexReader::class),
        objectFetcher: test()->createMock(\TotalCMS\Domain\Object\Service\ObjectFetcher::class),
        objectSaver: test()->createMock(\TotalCMS\Domain\Object\Service\ObjectSaver::class),
        objectUpdater: test()->createMock(\TotalCMS\Domain\Object\Service\ObjectUpdater::class),
        objectRemover: test()->createMock(\TotalCMS\Domain\Object\Service\ObjectRemover::class),
        mailer: test()->createMock(\TotalCMS\Domain\Mailer\Service\EmailService::class),
        config: (new ReflectionClass(\TotalCMS\Support\Config::class))->newInstanceWithoutConstructor(),
        logger: new \Psr\Log\NullLogger(),
        trigger: ['type' => 'schedule', 'cron' => '0 1 * * *'],
        args: ['foo' => 'bar'],
    );

    expect($ctx->trigger['type'])->toBe('schedule');
    expect($ctx->args['foo'])->toBe('bar');
    expect($ctx->request)->toBeNull();
    expect($ctx->event)->toBeNull();
});
```

- [ ] **Step 2: Run it (fails — class missing).**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Data;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Support\Config;

/**
 * The only API surface an automation handler receives.
 */
final class AutomationContext
{
	/**
	 * @param array<string,mixed> $trigger the trigger row that fired this run
	 * @param array<string,mixed> $args caller inputs (webhook query+body / manual run args)
	 * @param array<string,mixed>|null $event event payload (event triggers only)
	 */
	public function __construct(
		public readonly IndexReader $indexReader,
		public readonly ObjectFetcher $objectFetcher,
		public readonly ObjectSaver $objectSaver,
		public readonly ObjectUpdater $objectUpdater,
		public readonly ObjectRemover $objectRemover,
		public readonly EmailService $mailer,
		public readonly Config $config,
		public readonly LoggerInterface $logger,
		public readonly array $trigger = [],
		public readonly array $args = [],
		public readonly ?ServerRequestInterface $request = null,
		public readonly ?array $event = null,
	) {
	}
}
```

- [ ] **Step 4: Run it (passes).**

- [ ] **Step 5: `composer run stan -- src/Domain/Automation/Data/AutomationContext.php`** → clean.

- [ ] **Step 6: Commit** — `git commit -m "feat(automations): AutomationContext"`.

---

## Task 4: `AutomationStateStore` (per-trigger last-fire + failure counters)

**Files:** Create `src/Domain/Automation/Service/AutomationStateStore.php`; Test `tests/Unit/Domain/Automation/AutomationStateStoreTest.php`

State lives at `.system/automations/<slug>.state.json`: `{ "lastFire": { "<triggerIndex>": "<iso8601>" }, "failures": <int> }`.

- [ ] **Step 1: Write the failing test** (uses the same in-memory `StorageAdapterInterface` fake pattern as Plan 1 Task 3)

```php
it('records and reads per-trigger last-fire timestamps', function (): void {
    $files = [];
    $store = makeStateStore($files); // fake filesystem helper (mirror Plan 1)

    expect($store->lastFire('daily', 0))->toBeNull();

    $store->recordFire('daily', 0, '2026-05-31T01:00:00+00:00');

    expect($store->lastFire('daily', 0))->toBe('2026-05-31T01:00:00+00:00');
});

it('tracks consecutive failure counts and resets on success', function (): void {
    $files = [];
    $store = makeStateStore($files);

    expect($store->incrementFailures('daily'))->toBe(1);
    expect($store->incrementFailures('daily'))->toBe(2);
    $store->resetFailures('daily');
    expect($store->failures('daily'))->toBe(0);
});
```

- [ ] **Step 2: Run it (fails).**

- [ ] **Step 3: Implement** (atomic temp+move like `ExtensionStateRepository::persist`)

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Storage\StorageAdapterInterface;

final class AutomationStateStore
{
	public function __construct(private readonly StorageAdapterInterface $filesystem)
	{
	}

	public function lastFire(string $slug, int $triggerIndex): ?string
	{
		$state = $this->load($slug);
		$value = $state['lastFire'][(string)$triggerIndex] ?? null;

		return is_string($value) ? $value : null;
	}

	public function recordFire(string $slug, int $triggerIndex, string $isoTime): void
	{
		$state = $this->load($slug);
		$state['lastFire'][(string)$triggerIndex] = $isoTime;
		$this->save($slug, $state);
	}

	public function failures(string $slug): int
	{
		return (int)($this->load($slug)['failures'] ?? 0);
	}

	public function incrementFailures(string $slug): int
	{
		$state             = $this->load($slug);
		$count             = (int)($state['failures'] ?? 0) + 1;
		$state['failures'] = $count;
		$this->save($slug, $state);

		return $count;
	}

	public function resetFailures(string $slug): void
	{
		$state             = $this->load($slug);
		$state['failures'] = 0;
		$this->save($slug, $state);
	}

	/** @return array<string,mixed> */
	private function load(string $slug): array
	{
		$path = $this->path($slug);
		if (!$this->filesystem->fileExists($path)) {
			return [];
		}
		$data = json_decode($this->filesystem->read($path), true);

		return is_array($data) ? $data : [];
	}

	/** @param array<string,mixed> $state */
	private function save(string $slug, array $state): void
	{
		$json = (string)json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		$tmp  = $this->path($slug) . '.tmp.' . bin2hex(random_bytes(4));
		$this->filesystem->write($tmp, $json);
		$this->filesystem->move($tmp, $this->path($slug));
	}

	private function path(string $slug): string
	{
		return '.system/automations/' . $slug . '.state.json';
	}
}
```

> **Verify:** `StorageAdapterInterface` exposes `move()` (used by `ExtensionStateRepository`). Confirm the method name; if it's `rename`/`moveFile`, adjust.

- [ ] **Step 4–6:** Run (passes) → `composer run stan` → commit `"feat(automations): per-trigger state store"`.

---

## Task 5: `AutomationLoader` (list enabled + resolve handler closure)

**Files:** Create `src/Domain/Automation/Service/AutomationLoader.php`; Test `tests/Integration/AutomationLoaderTest.php`

Loads automation objects from the `automations` collection and resolves a handler closure by `require`-ing the external handler file (the same path `ExternalFieldStore` writes).

- [ ] **Step 1: Write the failing integration test**

```php
<?php

declare(strict_types=1);

use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Object\Service\ObjectSaver;

beforeEach(function (): void {
    recursiveDelete(cmsDataDir());
    $this->setUpApp(bootstrap());
    app()->get(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('automations');
});

it('loads an enabled automation and resolves its handler closure', function (): void {
    app()->get(ObjectSaver::class)->saveObject('automations', [
        'id'      => 'daily',
        'name'    => 'Daily',
        'enabled' => true,
        'triggers' => ['t0' => ['id' => 't0', 'type' => 'schedule', 'cron' => '0 1 * * *']],
        'handler' => "<?php\n\nreturn function (\$ctx) {\n    return ['ran' => true];\n};\n",
    ]);

    $loader = app()->get(AutomationLoader::class);

    $automations = $loader->enabled();
    expect($automations)->toHaveCount(1);

    $fn = $loader->handler('daily');
    expect($fn)->toBeCallable();
    expect(($fn)(null))->toBe(['ran' => true]);
});
```

- [ ] **Step 2: Run it (fails).**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\ExternalFieldStore;
use TotalCMS\Support\Config;

final readonly class AutomationLoader
{
	public function __construct(
		private IndexReader $indexReader,
		private ObjectFetcher $objectFetcher,
		private ExternalFieldStore $externalFields,
		private Config $config,
	) {
	}

	/**
	 * All enabled automation objects.
	 *
	 * @return list<ObjectData>
	 */
	public function enabled(): array
	{
		$index = $this->indexReader->fetchIndex('automations');
		$result = [];

		foreach ($index->objects as $row) {
			$id = (string)($row['id'] ?? '');
			if ($id === '') {
				continue;
			}
			$object = $this->objectFetcher->fetchObject('automations', $id);
			if ($object instanceof ObjectData && (string)$object->properties->get('enabled') !== '' && $this->isEnabled($object)) {
				$result[] = $object;
			}
		}

		return $result;
	}

	/**
	 * Resolve a handler closure by requiring its external handler file.
	 */
	public function handler(string $slug): callable
	{
		$path = $this->externalFields->sidecarPath('automations', $slug, 'handler', 'php');
		$abs  = rtrim($this->config->datadir, '/') . '/' . $path;

		if (!is_file($abs)) {
			throw new \RuntimeException("Automation handler file not found: {$slug}");
		}

		$fn = require $abs;
		if (!is_callable($fn)) {
			throw new \RuntimeException("Automation '{$slug}' handler did not return a closure.");
		}

		return $fn;
	}

	private function isEnabled(ObjectData $object): bool
	{
		$enabled = $object->properties->get('enabled');

		return (string)$enabled === '1' || (string)$enabled === 'true';
	}
}
```

> **Verify:** (a) `IndexReader::fetchIndex(...)->objects` iteration shape (rows are arrays with `id`) — confirm against `IndexData`/an existing consumer (`JumpStartExporter:165`). (b) `Config::datadir` is the absolute data dir. (c) the `enabled`/toggle value's string form (`'1'`/`'true'`) — confirm how `ToggleData` transforms. Adjust `isEnabled()` accordingly.

- [ ] **Step 4–6:** Run (passes) → stan → commit `"feat(automations): AutomationLoader resolves handler closures"`.

---

## Task 6: `RunRecord` + `AutomationRunner`

**Files:** Create `src/Domain/Automation/Data/RunRecord.php`, `src/Domain/Automation/Service/AutomationRunner.php`; Test `tests/Integration/AutomationRunnerTest.php`

The runner builds an `AutomationContext`, invokes the handler in try/catch, writes a run record to `.system/automations/<slug>/runs/<runId>.json`, prunes to `runHistoryLimit`, and updates failure counters. (Environment-aware notification + auto-disable are Plan 4 — here, just capture + count.)

- [ ] **Step 1: Write the failing test**

```php
it('runs a handler, returns the value, and writes a success run record', function (): void {
    app()->get(\TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('automations', [
        'id' => 'daily', 'name' => 'Daily', 'enabled' => true,
        'triggers' => ['t0' => ['id' => 't0', 'type' => 'schedule', 'cron' => '0 1 * * *']],
        'handler' => "<?php\n\nreturn function (\$ctx) {\n    return ['created' => 7];\n};\n",
    ]);

    $runner = app()->get(\TotalCMS\Domain\Automation\Service\AutomationRunner::class);
    $record = $runner->run('daily', ['type' => 'schedule'], []);

    expect($record->status)->toBe('success');
    expect($record->return)->toBe(['created' => 7]);

    $runsDir = cmsDataDir() . '.system/automations/daily/runs';
    expect(glob($runsDir . '/*.json'))->toHaveCount(1);
});

it('captures an exception as a failed run record and increments failures', function (): void {
    app()->get(\TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('automations', [
        'id' => 'boom', 'name' => 'Boom', 'enabled' => true,
        'triggers' => ['t0' => ['id' => 't0', 'type' => 'schedule', 'cron' => '* * * * *']],
        'handler' => "<?php\n\nreturn function (\$ctx) {\n    throw new \\RuntimeException('nope');\n};\n",
    ]);

    $runner = app()->get(\TotalCMS\Domain\Automation\Service\AutomationRunner::class);
    $record = $runner->run('boom', ['type' => 'schedule'], []);

    expect($record->status)->toBe('failed');
    expect($record->exception)->toContain('nope');
    expect(app()->get(\TotalCMS\Domain\Automation\Service\AutomationStateStore::class)->failures('boom'))->toBe(1);
});
```

- [ ] **Step 2: Run (fails).**

- [ ] **Step 3: Implement `RunRecord`**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Data;

final readonly class RunRecord
{
	/**
	 * @param array<string,mixed> $trigger
	 * @param mixed $return
	 */
	public function __construct(
		public string $runId,
		public string $automation,
		public array $trigger,
		public string $status,        // 'success' | 'failed'
		public string $startedAt,
		public string $finishedAt,
		public int $durationMs,
		public mixed $return,
		public ?string $exception,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'runId'      => $this->runId,
			'automation' => $this->automation,
			'trigger'    => $this->trigger,
			'status'     => $this->status,
			'startedAt'  => $this->startedAt,
			'finishedAt' => $this->finishedAt,
			'durationMs' => $this->durationMs,
			'return'     => $this->return,
			'exception'  => $this->exception,
		];
	}
}
```

- [ ] **Step 4: Implement `AutomationRunner`**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Automation\Data\AutomationContext;
use TotalCMS\Domain\Automation\Data\RunRecord;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Mailer\Service\EmailService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class AutomationRunner
{
	private \Psr\Log\LoggerInterface $logger;

	public function __construct(
		private readonly AutomationLoader $loader,
		private readonly AutomationStateStore $state,
		private readonly StorageAdapterInterface $filesystem,
		private readonly IndexReader $indexReader,
		private readonly ObjectFetcher $objectFetcher,
		private readonly ObjectSaver $objectSaver,
		private readonly ObjectUpdater $objectUpdater,
		private readonly ObjectRemover $objectRemover,
		private readonly EmailService $mailer,
		private readonly Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('automations.log')->createLogger('automations');
	}

	/**
	 * @param array<string,mixed> $trigger
	 * @param array<string,mixed> $args
	 */
	public function run(string $slug, array $trigger, array $args, ?ServerRequestInterface $request = null, ?array $event = null): RunRecord
	{
		$runId     = $this->uuid();
		$startedAt = gmdate('c');
		$start     = hrtime(true);

		$ctx = new AutomationContext(
			indexReader: $this->indexReader,
			objectFetcher: $this->objectFetcher,
			objectSaver: $this->objectSaver,
			objectUpdater: $this->objectUpdater,
			objectRemover: $this->objectRemover,
			mailer: $this->mailer,
			config: $this->config,
			logger: $this->logger,
			trigger: $trigger,
			args: $args,
			request: $request,
			event: $event,
		);

		$status    = 'success';
		$return    = null;
		$exception = null;

		try {
			$fn     = $this->loader->handler($slug);
			$return = $fn($ctx);
			$this->state->resetFailures($slug);
		} catch (\Throwable $e) {
			$status    = 'failed';
			$exception = $e->getMessage() . "\n" . $e->getTraceAsString();
			$this->state->incrementFailures($slug);
			$this->logger->error("Automation '{$slug}' failed: {$e->getMessage()}", ['exception' => $e]);
		}

		$record = new RunRecord(
			runId: $runId,
			automation: $slug,
			trigger: $trigger,
			status: $status,
			startedAt: $startedAt,
			finishedAt: gmdate('c'),
			durationMs: (int)((hrtime(true) - $start) / 1_000_000),
			return: $return,
			exception: $exception,
		);

		$this->persistRun($slug, $record);

		return $record;
	}

	private function persistRun(string $slug, RunRecord $record): void
	{
		$dir = '.system/automations/' . $slug . '/runs';
		$this->filesystem->write($dir . '/' . $record->runId . '.json', (string)json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		$this->prune($dir);
	}

	private function prune(string $dir): void
	{
		$limit = (int)($this->config->automations['runHistoryLimit'] ?? 100);
		$files = $this->filesystem->listContents($dir); // verify API: returns paths/metadata
		// Keep newest $limit by filename/mtime; delete the rest. Implement using
		// whatever listing API StorageAdapterInterface exposes — see ObjectRepository
		// for directory operations. (If listing is awkward, a glob on the absolute
		// path via Config::datadir is acceptable here.)
	}

	private function uuid(): string
	{
		// Match how the codebase mints ids (uuid_create used in LoggerFactory).
		return bin2hex(random_bytes(16));
	}
}
```

> **Verify:** (a) `StorageAdapterInterface` directory-listing API for `prune()` (or glob via `Config::datadir`). (b) `uuid()` — reuse the codebase's id helper if one exists (`SlugData`? `uuid_create`?). (c) `write()` auto-creates the `runs/` dir (Flysystem does).

- [ ] **Step 5: Run (passes) → stan.**

- [ ] **Step 6: Commit** — `"feat(automations): AutomationRunner + RunRecord"`.

---

## Task 7: `ScheduleTicker` (cron due-detection)

**Files:** Create `src/Domain/Automation/Service/ScheduleTicker.php`; Test `tests/Unit/Domain/Automation/ScheduleTickerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Cron\CronExpression;
use TotalCMS\Domain\Automation\Service\ScheduleTicker;

it('reports a schedule due when now is at/after the next run since last fire', function (): void {
    $ticker = new ScheduleTicker();

    // '0 1 * * *' = 01:00 daily. lastFire yesterday → due now if now >= today 01:00.
    expect($ticker->isDue('0 1 * * *', '2026-05-30T01:00:00+00:00', new DateTimeImmutable('2026-05-31T01:05:00+00:00', new DateTimeZone('UTC'))))
        ->toBeTrue();

    // already fired today at 01:00 → not due again until tomorrow
    expect($ticker->isDue('0 1 * * *', '2026-05-31T01:00:00+00:00', new DateTimeImmutable('2026-05-31T01:05:00+00:00', new DateTimeZone('UTC'))))
        ->toBeFalse();
});

it('treats a never-fired schedule as due when its previous slot has passed', function (): void {
    $ticker = new ScheduleTicker();
    expect($ticker->isDue('0 1 * * *', null, new DateTimeImmutable('2026-05-31T02:00:00+00:00', new DateTimeZone('UTC'))))
        ->toBeTrue();
});
```

- [ ] **Step 2: Run (fails).**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use Cron\CronExpression;

final class ScheduleTicker
{
	/**
	 * Due when the most recent scheduled slot (<= now) is strictly after the last
	 * fire. A never-fired schedule is due once its previous slot has passed.
	 */
	public function isDue(string $cron, ?string $lastFireIso, \DateTimeImmutable $now): bool
	{
		if (!CronExpression::isValidExpression($cron)) {
			return false;
		}

		$expression  = new CronExpression($cron);
		$previousRun = \DateTimeImmutable::createFromMutable($expression->getPreviousRunDate($now, 0, true));

		if ($lastFireIso === null) {
			return $previousRun <= $now;
		}

		$lastFire = new \DateTimeImmutable($lastFireIso);

		return $previousRun > $lastFire;
	}
}
```

> **Verify:** `dragonmantank/cron-expression` v3 API — `isValidExpression()`, `getPreviousRunDate(DateTimeInterface $now, int $nth, bool $allowCurrentDate)`. Adjust if the installed major differs.

- [ ] **Step 4–6:** Run (passes) → stan → commit `"feat(automations): ScheduleTicker cron due-detection"`.

---

## Task 8: `tcms automations:process` command

**Files:** Create `src/CLI/Command/AutomationsProcessCommand.php`; Modify `src/CLI/CliApplication.php`; Test `tests/Integration/AutomationsProcessCommandTest.php`

Single-flight lock; iterate enabled automations; for each schedule trigger, `ScheduleTicker::isDue` against `AutomationStateStore`; if due, `AutomationRunner::run` + `recordFire`.

- [ ] **Step 1: Write the failing test** (drive the command via the runner+loader, asserting a due schedule produces a run record)

```php
<?php

declare(strict_types=1);

use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    recursiveDelete(cmsDataDir());
    $this->setUpApp(bootstrap());
    app()->get(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('automations');
});

it('fires a due schedule automation and writes a run record', function (): void {
    app()->get(\TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('automations', [
        'id' => 'minutely', 'name' => 'Minutely', 'enabled' => true,
        'triggers' => ['t0' => ['id' => 't0', 'type' => 'schedule', 'cron' => '* * * * *']],
        'handler' => "<?php\n\nreturn function (\$ctx) { return ['ok' => 1]; };\n",
    ]);

    $command = new \TotalCMS\CLI\Command\AutomationsProcessCommand(testTotalCMS()); // helper that yields a booted TotalCMS
    $tester  = new CommandTester($command);
    $tester->execute(['--json' => true]);

    expect($tester->getStatusCode())->toBe(0);
    expect(glob(cmsDataDir() . '.system/automations/minutely/runs/*.json'))->not->toBeEmpty();
});
```

> **Verify:** how integration tests instantiate a CLI command with a booted `TotalCMS` (there may be a helper; otherwise build from `bootstrap()`/container). Mirror an existing CLI command test under `tests/`.

- [ ] **Step 2: Run (fails).**

- [ ] **Step 3: Implement the command**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Automation\Service\AutomationLoader;
use TotalCMS\Domain\Automation\Service\AutomationRunner;
use TotalCMS\Domain\Automation\Service\AutomationStateStore;
use TotalCMS\Domain\Automation\Service\ScheduleTicker;

class AutomationsProcessCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('automations:process')
			->setDescription('Fire due scheduled automations (run on its own cron line, parallel to jobs:process)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$lockPath = rtrim($this->totalcms->config->datadir, '/') . '/.system/.processAutomations.lock';
		$lock     = @fopen($lockPath, 'c');
		if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
			if ($lock !== false) {
				fclose($lock);
			}
			$output->writeln('Automations processor already running.');

			return Command::SUCCESS;
		}
		register_shutdown_function(function () use ($lock, $lockPath): void {
			flock($lock, LOCK_UN);
			fclose($lock);
			@unlink($lockPath);
		});

		$loader = $this->totalcms->container->get(AutomationLoader::class);
		$runner = $this->totalcms->container->get(AutomationRunner::class);
		$state  = $this->totalcms->container->get(AutomationStateStore::class);
		$ticker = $this->totalcms->container->get(ScheduleTicker::class);

		$now   = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$fired = [];

		foreach ($loader->enabled() as $automation) {
			$slug     = $automation->id;
			$triggers = $automation->properties->get('triggers');
			$index    = 0;

			foreach ($this->scheduleTriggers($triggers) as $triggerIndex => $trigger) {
				if (!$ticker->isDue((string)($trigger['cron'] ?? ''), $state->lastFire($slug, $triggerIndex), $now)) {
					continue;
				}
				$runner->run($slug, $trigger, []);
				$state->recordFire($slug, $triggerIndex, $now->format('c'));
				$fired[] = $slug;
			}
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode(['fired' => $fired], JSON_PRETTY_PRINT));
		} else {
			$output->writeln(sprintf('Fired %d automation(s).', count($fired)));
		}

		return Command::SUCCESS;
	}

	/**
	 * @param mixed $triggers the triggers deck property
	 * @return array<int,array<string,mixed>> schedule triggers, keyed by stable index
	 */
	private function scheduleTriggers(mixed $triggers): array
	{
		$out  = [];
		$rows = is_object($triggers) && method_exists($triggers, 'transform') ? $triggers->transform() : $triggers;
		$i    = 0;

		foreach (is_array($rows) ? $rows : [] as $row) {
			if (is_array($row) && ($row['type'] ?? '') === 'schedule') {
				$out[$i] = $row;
			}
			$i++;
		}

		return $out;
	}
}
```

> **Verify:** how a deck property (`triggers`) exposes its rows — `DeckData::transform()` shape (keyed by item id). The `scheduleTriggers()` index must be stable across runs so `state.lastFire` keys stay consistent; if deck rows are keyed by item id, key state by that id instead of a positional index (preferred — update `AutomationStateStore` calls to pass the trigger's `id`).

- [ ] **Step 4: Register the command** in `src/CLI/CliApplication.php` near the other `addCommand` calls:

```php
		$app->addCommand(new Command\AutomationsProcessCommand($totalcms));
```

- [ ] **Step 5: Run (passes) → stan.**

- [ ] **Step 6: Commit** — `"feat(automations): automations:process scheduled runner"`.

---

## Task 9: Container wiring + docs

**Files:** Modify `config/container.php`; Modify `resources/docs/operations/` (cron setup)

- [ ] **Step 1: Register services** in `config/container.php` so `AutomationLoader`, `AutomationRunner`, `AutomationStateStore`, `ScheduleTicker`, `AutomationContext` deps resolve (autowire if the container autowires; otherwise add definitions mirroring neighbouring services). `AutomationStateStore`/`AutomationRunner` need the `StorageAdapterInterface` already used by repositories.

Run: `grep -n "StorageAdapterInterface\|JobRunner::class" config/container.php` to find the wiring style; mirror it.

- [ ] **Step 2: Document the cron line** in the operations docs next to `jobs:process`:

> Add a second cron entry: `* * * * * cd /path/to/site && php vendor/bin/tcms automations:process` — runs parallel to `jobs:process` so an import backlog never delays a scheduled automation.

- [ ] **Step 3: Run the whole automations suite + stan**

Run: `vendor/bin/pest tests/Unit/Domain/Automation tests/Integration/Automation* && composer run stan`
Expected: PASS, no Level-8 errors.

- [ ] **Step 4: Commit** — `"feat(automations): container wiring + cron docs"`.

---

## Self-Review

**Spec coverage (`automations.md`):** reserved collection ✓ (T2); externalized handler `require`d at runtime, never a container definition ✓ (T5 `AutomationLoader::handler`); `AutomationContext` ✓ (T3); dedicated `automations:process` runner, own lane, single-flight ✓ (T8); per-trigger schedule state ✓ (T4/T8 — see the keyed-by-id verify note); run history + retention ✓ (T6). Webhook/event triggers, error-email/environment-aware handling, auto-disable, admin UI, edition gating, Sync, activity logger → **Plans 3 & 4** (intentionally out of scope here).

**Placeholder scan:** No "TODO". Every "Verify" note is a concrete check against a named file with a fallback. Two are load-bearing: deck-row shape (T8) and the toggle/`enabled` string form (T5) — both have explicit fallback instructions.

**Type consistency:** `AutomationLoader::enabled(): list<ObjectData>`, `::handler(string): callable`; `AutomationRunner::run(string,array,array,?request,?event): RunRecord`; `AutomationStateStore` method names used identically in T4/T6/T8; `ScheduleTicker::isDue(string,?string,DateTimeImmutable): bool`. Consistent across tasks.

**Cross-plan seam:** `AutomationLoader::handler()` reuses `ExternalFieldStore::sidecarPath()` from Plan 1 — confirm Plan 1 is merged first.
