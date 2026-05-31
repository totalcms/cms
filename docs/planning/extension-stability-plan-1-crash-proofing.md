# Extension Stability — Plan 1: Crash-Proofing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make it impossible for a single extension to white-screen a Total CMS site, and have repeat-crasher extensions self-heal (auto-disable) on production while staying loudly visible in dev/preview.

**Architecture:** A central `ExtensionGuard` service owns try/catch + per-request-deduped failure counting + the prod-only quarantine decision. Extension runtime hooks are wrapped in guarded closures at *collection* time (in `ExtensionManager`), before they reach their consumers (`EventDispatcher`, Twig). Quarantine state lives in a distinct `quarantine` block on `ExtensionState` (persisted to `extensions.json`), separate from operator-disable. A new "Site Environment" general setting drives the prod/dev split, with `APP_ENV` winning over the UI toggle and `TotalCMS::isPreview()` excluded from quarantine.

**Tech Stack:** PHP 8.2+, Slim 4, PHP-DI, Twig 3, Pest tests, PHPStan Level 8. APCu-first cache (`CacheManager`) for the rolling failure window.

**Spec:** `docs/planning/extension-stability-guardrails.md`

---

## Design decisions locked from the spec (read before starting)

- **Crash containment is always on, every env.** PHP zero-cost exceptions → no happy-path overhead.
- **Auto-quarantine fires ONLY when `Config->env === 'prod'` AND NOT `TotalCMS::isPreview()`.** Every other case = contain + surface, never disable. (Preview is handled separately from `Config->env`, so it must be excluded explicitly — otherwise quarantine wrongly fires during Stacks preview.)
- **Per-request crash de-dup:** a given extension's failure counter increments **at most once per request**, so the threshold means "N bad requests."
- **Defaults:** 5 failures / 5-minute window → quarantine. Configurable.
- **Quarantine is a distinct state**, not operator-disable. Survives cache clears (persisted). Re-enable clears it and resets the counter.
- **This plan does NOT include performance profiling** (Plan 2) or the transparency quick wins (Plan 3). It *does* include displaying quarantine status in the existing extensions UI, since that's just reading state.

## File Structure

**Create:**
- `src/Domain/Extension/Service/ExtensionGuard.php` — the safety brain (guard a callable, count failures, decide quarantine).
- `src/Domain/Extension/Service/EnvironmentResolver.php` — single source of truth for "effective environment" (prod/dev/preview), honoring APP_ENV-wins.
- `tests/Unit/Domain/Extension/ExtensionGuardTest.php`
- `tests/Unit/Domain/Extension/EnvironmentResolverTest.php`
- `tests/Unit/Domain/Extension/Data/ExtensionStateQuarantineTest.php`

**Modify:**
- `resources/schemas/settings/general.json` — add the Site Environment select.
- `config/settings.php` — make `APP_ENV` win over the merged settings.json `env`.
- `src/Domain/Extension/Data/ExtensionState.php` — add the `quarantine` block + helpers.
- `src/Domain/Extension/Service/ExtensionManager.php` — wrap hooks via the Guard in the `getAll*()` collectors; skip loading quarantined extensions; expose quarantine info to the UI; add a re-enable/clear path.
- `src/Domain/Event/EventDispatcher.php` — attribute listener exceptions back to the Guard (it already catches).
- `resources/templates/admin/extensions.twig` — show quarantine status + "Re-enable" action.
- `config/dependencies.php` (or wherever DI definitions live) — register `ExtensionGuard` + `EnvironmentResolver`.

---

## Phase 1 — Site Environment setting + environment resolution

Goal of phase: a discoverable Production/Development toggle in general settings that feeds `Config->env`, with `APP_ENV` winning, and a single `EnvironmentResolver` that answers "should self-healing run?" correctly (prod and not preview).

### Task 1: Make `APP_ENV` win over settings.json `env`

**Files:**
- Modify: `config/settings.php` (after the settings.json merge block, ~line 54)

- [ ] **Step 1: Read the current merge block**

Open `config/settings.php`. Confirm the settings.json deep-merge block ends around line 54, and that the `test`-env validation block follows. The new code goes **between** the settings.json merge and the `test` validation block.

- [ ] **Step 2: Add the APP_ENV-wins override**

After the `settings.json` deep-merge block (the `if (file_exists($settingsJsonFile)) { ... }` closing brace), insert:

```php
// Environment variable wins over the settings.json toggle.
// (settings.json was merged last above; re-apply APP_ENV so a server-level
// APP_ENV — including Stacks' 'preview' — always overrides the UI setting.)
$appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
if (is_string($appEnv) && $appEnv !== '') {
	$settings['env'] = strtolower($appEnv);
}
```

- [ ] **Step 3: Manually verify resolution order**

Run: `APP_ENV=dev php -r "require 'config/settings.php';" ` is not directly assertable here; instead verify by reading: with no APP_ENV, `$settings['env']` comes from settings.json/defaults; with APP_ENV set, it is forced. Confirm by code inspection that the new block runs after the settings.json merge and before the `test` validation. (The `test` validation still gets the last word, intentionally.)

- [ ] **Step 4: Commit**

```bash
git add config/settings.php
git commit -m "feat(settings): APP_ENV wins over settings.json env toggle"
```

### Task 2: Add the Site Environment select to general settings schema

**Files:**
- Modify: `resources/schemas/settings/general.json`

- [ ] **Step 1: Read the schema to match existing field shape**

Open `resources/schemas/settings/general.json`. Note the existing `select` field shape and where top-level fields are keyed.

- [ ] **Step 2: Add the `env` field**

Add this field entry (keyed `env` so it maps straight onto `$settings['env']`):

```json
"env": {
    "type": "string",
    "field": "select",
    "label": "Site Environment",
    "help": "Production protects live visitors and auto-disables repeatedly crashing extensions. Development surfaces extension errors loudly and never auto-disables. Overridden when an APP_ENV environment variable is set.",
    "default": "prod",
    "options": [
        {"value": "prod", "label": "Production"},
        {"value": "dev", "label": "Development"}
    ]
}
```

Note: values are `prod`/`dev` to match `Config->env` directly — do **not** use `production`/`development`. `preview` is intentionally absent (secret, env-var only).

- [ ] **Step 3: Verify the settings page renders the field**

Run the app (or load `/admin/settings` general section) and confirm a "Site Environment" dropdown appears with Production/Development. Saving it writes `env` into `tcms-data/.system/settings.json` via `SettingsSaver::saveSection('general', ...)`.

- [ ] **Step 4: Commit**

```bash
git add resources/schemas/settings/general.json
git commit -m "feat(settings): add Site Environment toggle to general settings"
```

### Task 3: Disable the toggle in the UI when APP_ENV is set

**Files:**
- Modify: the general settings template/renderer (find via `grep -rn "settings/general\|general.json" resources/templates src/Action/Admin`)

- [ ] **Step 1: Locate where general settings fields render**

Find the settings form rendering for the general section (`resources/templates/admin/settings.twig` + the form helper). Determine how to conditionally pass a "locked" flag for the `env` field.

- [ ] **Step 2: Expose an `appEnvLocked` flag to the template**

In the action that renders settings (the one calling the general schema), compute:

```php
$appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
$appEnvLocked = is_string($appEnv) && $appEnv !== '';
```

Pass `appEnvLocked` (and the resolved value) into the template render data.

- [ ] **Step 3: Render a locked notice**

In the general settings template, when `appEnvLocked`, render the Site Environment control as disabled with helper text: `Controlled by the APP_ENV environment variable ({{ appEnvValue }}).` so the operator is never silently overridden.

- [ ] **Step 4: Manually verify**

With `APP_ENV=dev` set, load general settings → the Site Environment control is disabled and shows the notice. Unset → it's editable.

- [ ] **Step 5: Commit**

```bash
git add resources/templates/admin/ src/Action/Admin/
git commit -m "feat(settings): lock Site Environment toggle when APP_ENV is set"
```

### Task 4: `EnvironmentResolver` — single source of truth for "is self-healing allowed"

**Files:**
- Create: `src/Domain/Extension/Service/EnvironmentResolver.php`
- Test: `tests/Unit/Domain/Extension/EnvironmentResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Support\Config;

function configWithEnv(string $env): Config
{
	$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = $env;
	return $config;
}

describe('EnvironmentResolver', function () {
	test('quarantine allowed only in prod', function () {
		$resolver = new EnvironmentResolver(configWithEnv('prod'), isPreview: false);
		expect($resolver->isQuarantineAllowed())->toBeTrue();
	});

	test('quarantine blocked in dev', function () {
		$resolver = new EnvironmentResolver(configWithEnv('dev'), isPreview: false);
		expect($resolver->isQuarantineAllowed())->toBeFalse();
	});

	test('quarantine blocked when preview, even if env is prod', function () {
		$resolver = new EnvironmentResolver(configWithEnv('prod'), isPreview: true);
		expect($resolver->isQuarantineAllowed())->toBeFalse();
	});

	test('unknown env is treated as non-prod', function () {
		$resolver = new EnvironmentResolver(configWithEnv('staging'), isPreview: false);
		expect($resolver->isQuarantineAllowed())->toBeFalse();
	});

	test('errors surface loudly when not prod', function () {
		$resolver = new EnvironmentResolver(configWithEnv('dev'), isPreview: false);
		expect($resolver->shouldSurfaceErrors())->toBeTrue();
	});

	test('errors do not surface loudly in prod', function () {
		$resolver = new EnvironmentResolver(configWithEnv('prod'), isPreview: false);
		expect($resolver->shouldSurfaceErrors())->toBeFalse();
	});
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer run test -- tests/Unit/Domain/Extension/EnvironmentResolverTest.php`
Expected: FAIL — class `EnvironmentResolver` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Support\Config;

/**
 * Single source of truth for environment-dependent extension safety behavior.
 *
 * Auto-quarantine is destructive (it disables a customer's extension), so it
 * fires ONLY on a true production request: env === 'prod' AND not a Stacks
 * preview. preview is tracked separately from Config->env, so it must be
 * excluded explicitly here.
 */
final readonly class EnvironmentResolver
{
	public function __construct(
		private Config $config,
		private bool $isPreview,
	) {
	}

	/** Repeat-crasher auto-disable is allowed only on true production. */
	public function isQuarantineAllowed(): bool
	{
		return $this->config->env === 'prod' && !$this->isPreview;
	}

	/** Outside prod, surface extension errors loudly so builders see them. */
	public function shouldSurfaceErrors(): bool
	{
		return !($this->config->env === 'prod') || $this->isPreview;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer run test -- tests/Unit/Domain/Extension/EnvironmentResolverTest.php`
Expected: PASS (all 6).

- [ ] **Step 5: Register in DI**

In the DI definitions file (find with `grep -rln "ExtensionManager::class" config/`), add:

```php
EnvironmentResolver::class => function (ContainerInterface $c): EnvironmentResolver {
	return new EnvironmentResolver(
		$c->get(Config::class),
		\TotalCMS\TotalCMS::isPreview(),
	);
},
```

- [ ] **Step 6: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Service/EnvironmentResolver.php tests/Unit/Domain/Extension/EnvironmentResolverTest.php config/
git commit -m "feat(extensions): add EnvironmentResolver for env-aware safety gating"
```

---

## Phase 2 — Quarantine state on `ExtensionState`

### Task 5: Add the `quarantine` block to `ExtensionState`

**Files:**
- Modify: `src/Domain/Extension/Data/ExtensionState.php`
- Test: `tests/Unit/Domain/Extension/Data/ExtensionStateQuarantineTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use TotalCMS\Domain\Extension\Data\ExtensionState;

describe('ExtensionState quarantine', function () {
	test('defaults to not quarantined', function () {
		$state = new ExtensionState(enabled: true);
		expect($state->isQuarantined())->toBeFalse();
	});

	test('quarantine round-trips through toArray/fromArray', function () {
		$state = new ExtensionState(enabled: true);
		$state->quarantine = [
			'reason'        => 'crashed',
			'failureCount'  => 5,
			'lastError'     => 'Boom',
			'quarantinedAt' => '2026-05-30T10:00:00+00:00',
		];

		$restored = ExtensionState::fromArray($state->toArray());

		expect($restored->isQuarantined())->toBeTrue();
		expect($restored->quarantine['failureCount'])->toBe(5);
		expect($restored->quarantine['lastError'])->toBe('Boom');
	});

	test('clearQuarantine removes the block', function () {
		$state = new ExtensionState(enabled: true);
		$state->quarantine = ['reason' => 'crashed', 'failureCount' => 5, 'lastError' => 'x', 'quarantinedAt' => 'now'];
		$state->clearQuarantine();
		expect($state->isQuarantined())->toBeFalse();
		expect($state->quarantine)->toBeNull();
	});
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer run test -- tests/Unit/Domain/Extension/Data/ExtensionStateQuarantineTest.php`
Expected: FAIL — `isQuarantined()` undefined.

- [ ] **Step 3: Implement on `ExtensionState`**

Add the property to the constructor (after `array $permissions = []`):

```php
		/** @var array{reason:string,failureCount:int,lastError:string,quarantinedAt:string}|null */
		public ?array $quarantine = null,
```

In `fromArray()`, after building `$permissions`, add:

```php
		$quarantine = null;
		if (isset($data['quarantine']) && is_array($data['quarantine'])) {
			$q = $data['quarantine'];
			$quarantine = [
				'reason'        => (string)($q['reason'] ?? ''),
				'failureCount'  => (int)($q['failureCount'] ?? 0),
				'lastError'     => (string)($q['lastError'] ?? ''),
				'quarantinedAt' => (string)($q['quarantinedAt'] ?? ''),
			];
		}
```

and pass `quarantine: $quarantine,` into the `new self(...)` call.

In `toArray()`, add `'quarantine' => $this->quarantine,` to the returned array.

Add the helper methods:

```php
	public function isQuarantined(): bool
	{
		return $this->quarantine !== null;
	}

	public function clearQuarantine(): void
	{
		$this->quarantine = null;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer run test -- tests/Unit/Domain/Extension/Data/ExtensionStateQuarantineTest.php`
Expected: PASS (all 3).

- [ ] **Step 5: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Data/ExtensionState.php tests/Unit/Domain/Extension/Data/ExtensionStateQuarantineTest.php
git commit -m "feat(extensions): add quarantine block to ExtensionState"
```

---

## Phase 3 — The `ExtensionGuard` service

### Task 6: `ExtensionGuard` — guard a callable, count failures, decide quarantine

**Files:**
- Create: `src/Domain/Extension/Service/ExtensionGuard.php`
- Test: `tests/Unit/Domain/Extension/ExtensionGuardTest.php`

Dependencies the Guard needs: `EnvironmentResolver` (gate), `CacheManager` (rolling counter, find the interface via `grep -rn "class CacheManager" src/Domain/Cache`), `ExtensionStateRepository` (persist quarantine), `LoggerInterface`. The per-request dedup uses an in-memory set on the Guard instance (one instance per request in PHP).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Service\ExtensionGuard;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Support\Config;

function guardEnv(string $env, bool $preview = false): EnvironmentResolver
{
	$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = $env;
	return new EnvironmentResolver($config, $preview);
}

describe('ExtensionGuard', function () {
	test('returns the callable result on success', function () {
		$guard = new ExtensionGuard(guardEnv('prod'), fakeCache(), fakeStateRepo(), new NullLogger());
		$result = $guard->run('acme/widget', 'twig:fn', fn () => 42, fallback: null);
		expect($result)->toBe(42);
	});

	test('catches a throw and returns the fallback', function () {
		$guard = new ExtensionGuard(guardEnv('prod'), fakeCache(), fakeStateRepo(), new NullLogger());
		$result = $guard->run('acme/widget', 'twig:fn', fn () => throw new RuntimeException('boom'), fallback: 'safe');
		expect($result)->toBe('safe');
	});

	test('counts at most one failure per extension per request', function () {
		$cache = fakeCache();
		$guard = new ExtensionGuard(guardEnv('prod'), $cache, fakeStateRepo(), new NullLogger());
		// Two throws in the same request from the same extension.
		$guard->run('acme/widget', 'twig:fn', fn () => throw new RuntimeException('a'), fallback: null);
		$guard->run('acme/widget', 'twig:fn', fn () => throw new RuntimeException('b'), fallback: null);
		expect($cache->incrementCalls('extguard:fail:acme/widget'))->toBe(1);
	});

	test('quarantines after threshold on prod', function () {
		$repo  = fakeStateRepo('acme/widget', enabled: true);
		$cache = fakeCache(['extguard:fail:acme/widget' => 5]); // already at threshold
		$guard = new ExtensionGuard(guardEnv('prod'), $cache, $repo, new NullLogger(), threshold: 5);
		$guard->run('acme/widget', 'twig:fn', fn () => throw new RuntimeException('boom'), fallback: null);
		$state = $repo->getState('acme/widget');
		expect($state->isQuarantined())->toBeTrue();
	});

	test('never quarantines outside prod', function () {
		$repo  = fakeStateRepo('acme/widget', enabled: true);
		$cache = fakeCache(['extguard:fail:acme/widget' => 50]);
		$guard = new ExtensionGuard(guardEnv('dev'), $cache, $repo, new NullLogger(), threshold: 5);
		$guard->run('acme/widget', 'twig:fn', fn () => throw new RuntimeException('boom'), fallback: null);
		expect($repo->getState('acme/widget')->isQuarantined())->toBeFalse();
	});
});
```

Note: `fakeCache()`, `fakeStateRepo()` are small in-test doubles. Write them as plain anonymous classes at the top of the test file implementing the minimal surface the Guard uses (`increment(key, ttl): int`, `get(key): ?int` for cache; `getState`, `setState` for repo). Match the real `CacheManager`/`ExtensionStateRepository` method names discovered in Step 3.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer run test -- tests/Unit/Domain/Extension/ExtensionGuardTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Confirm the real cache + repo method names**

Read `src/Domain/Cache/CacheManager.php` for the increment/get/TTL surface, and `src/Domain/Extension/Repository/ExtensionStateRepository.php` for `getState()` + the setter that triggers `persist()`. Adjust the Guard (and the in-test doubles) to call those exact methods.

- [ ] **Step 4: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\Service\CacheManager;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;

/**
 * Central safety wrapper for extension runtime hooks.
 *
 * - try/catch every guarded call; on throw return the fallback so the page
 *   still renders (crash containment — always on, every env).
 * - count failures in a rolling cache window, de-duplicated to at most one per
 *   extension per request.
 * - on prod (and not preview), auto-quarantine an extension that crosses the
 *   failure threshold.
 */
final class ExtensionGuard
{
	/** @var array<string,true> Extensions already counted this request. */
	private array $countedThisRequest = [];

	public function __construct(
		private readonly EnvironmentResolver $env,
		private readonly CacheManager $cache,
		private readonly ExtensionStateRepository $stateRepository,
		private readonly LoggerInterface $logger,
		private readonly int $threshold = 5,
		private readonly int $windowSeconds = 300,
	) {
	}

	/**
	 * Run an extension callable inside the safety net.
	 *
	 * @template T
	 * @param callable():T $callable
	 * @param T $fallback
	 * @return T
	 */
	public function run(string $extensionId, string $hookType, callable $callable, mixed $fallback): mixed
	{
		try {
			return $callable();
		} catch (\Throwable $e) {
			$this->recordFailure($extensionId, $hookType, $e);
			return $fallback;
		}
	}

	private function recordFailure(string $extensionId, string $hookType, \Throwable $e): void
	{
		$this->logger->error("Extension '{$extensionId}' crashed in {$hookType}: {$e->getMessage()}", [
			'extension' => $extensionId,
			'hook'      => $hookType,
			'exception' => $e,
		]);

		// De-dup: count at most once per extension per request.
		if (isset($this->countedThisRequest[$extensionId])) {
			return;
		}
		$this->countedThisRequest[$extensionId] = true;

		$key   = "extguard:fail:{$extensionId}";
		$count = $this->cache->increment($key, $this->windowSeconds);

		if ($this->env->isQuarantineAllowed() && $count >= $this->threshold) {
			$this->quarantine($extensionId, $count, $e);
		}
	}

	private function quarantine(string $extensionId, int $count, \Throwable $e): void
	{
		$state = $this->stateRepository->getState($extensionId);
		if ($state === null || $state->isQuarantined()) {
			return;
		}

		$state->quarantine = [
			'reason'        => 'Crashed repeatedly',
			'failureCount'  => $count,
			'lastError'     => $e->getMessage(),
			'quarantinedAt' => gmdate('c'),
		];
		$this->stateRepository->setState($extensionId, $state);

		$this->logger->warning("Extension '{$extensionId}' auto-quarantined after {$count} failures.");
	}
}
```

Note: `gmdate('c')` is allowed (not `Date.now()`/`new Date()` — those are JS-runtime restrictions, irrelevant in PHP). Adjust `$this->cache->increment(...)` and `$this->stateRepository->setState(...)` to the real method names found in Step 3 (e.g. the repo setter might be `save()` / `setState()`).

- [ ] **Step 5: Run test to verify it passes**

Run: `composer run test -- tests/Unit/Domain/Extension/ExtensionGuardTest.php`
Expected: PASS (all 5).

- [ ] **Step 6: Register in DI**

Add an `ExtensionGuard::class` definition wiring `EnvironmentResolver`, `CacheManager`, `ExtensionStateRepository`, the logger, and the configurable `threshold`/`windowSeconds` (read from config if a knob exists; otherwise the defaults).

- [ ] **Step 7: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Service/ExtensionGuard.php tests/Unit/Domain/Extension/ExtensionGuardTest.php config/
git commit -m "feat(extensions): add ExtensionGuard crash-containment + quarantine service"
```

---

## Phase 4 — Wire the Guard into hook collection

The Guard wraps callables at *collection* time in `ExtensionManager`, so consumers stay unaware. `ExtensionManager` needs the Guard injected — add it to the constructor and DI definition first.

### Task 7: Inject `ExtensionGuard` into `ExtensionManager`

**Files:**
- Modify: `src/Domain/Extension/Service/ExtensionManager.php` (constructor) + its DI definition.

- [ ] **Step 1: Add the constructor dependency**

Add `private readonly ExtensionGuard $guard,` (with the `use` import) to the `ExtensionManager` constructor. Update the DI definition to pass `$c->get(ExtensionGuard::class)`.

- [ ] **Step 2: Verify the container still builds**

Run: `composer run test -- tests/Unit/Domain/Extension` (the existing ExtensionManager tests). Expected: PASS — if any test constructs `ExtensionManager` directly, add a guard double there.

- [ ] **Step 3: Commit**

```bash
git add src/Domain/Extension/Service/ExtensionManager.php config/ tests/
git commit -m "chore(extensions): inject ExtensionGuard into ExtensionManager"
```

### Task 8: Guard event listeners in `getAllEventListeners()`

**Files:**
- Modify: `src/Domain/Extension/Service/ExtensionManager.php:938-959`
- Test: extend `tests/Unit/Domain/Extension` with a listener-guard test.

Context: `EventDispatcher` already try/catches listeners (`EventDispatcher.php:138`), so the win here is **attribution + crash-counting** (feeding quarantine), not first-line containment. Wrap each listener callable in `$this->guard->run(...)` when collecting.

- [ ] **Step 1: Write the failing test**

```php
test('event listeners are wrapped so a throwing listener is counted, not fatal', function () {
	// Build a manager with one extension whose listener throws; collect listeners,
	// invoke the collected callable, and assert it does not throw and the guard counted it.
	// (Construct ExtensionManager with a real ExtensionGuard backed by fakeCache/fakeStateRepo.)
	$collected = $manager->getAllEventListeners();
	[$listener] = $collected['object.created'][0];
	expect(fn () => $listener(['id' => 'x']))->not->toThrow(Throwable::class);
});
```

Flesh out the manager construction matching the existing `ExtensionManager` test setup (reuse the patterns in the current extension tests).

- [ ] **Step 2: Run test to verify it fails**

Run: `composer run test -- tests/Unit/Domain/Extension` filtered to the new test. Expected: FAIL — the unwrapped listener throws.

- [ ] **Step 3: Wrap the listener in the collector**

In `getAllEventListeners()`, change the inner append from `$listeners[$event][] = $listener;` to wrap the callable while preserving the `[callable, priority]` shape:

```php
foreach ($eventListeners as $listener) {
	[$callable, $priority] = $listener;
	$guarded = fn (mixed ...$args) => $this->guard->run(
		$id,
		"event:{$event}",
		fn () => $callable(...$args),
		fallback: null,
	);
	$listeners[$event][] = [$guarded, $priority];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run the filtered test. Expected: PASS.

- [ ] **Step 5: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Service/ExtensionManager.php tests/
git commit -m "feat(extensions): guard extension event listeners for attribution + counting"
```

### Task 9: Guard Twig functions/filters at collection

**Files:**
- Modify: `src/Domain/Extension/Service/ExtensionManager.php` (the Twig collector that feeds `TwigExtensionRegistrar::filterAndRegister`)
- Test: a Twig-function-guard test.

Context: extension Twig functions currently propagate (Twig has no sandbox here) — this is the **primary white-screen source** and the highest-value wrap. Re-create each `TwigFunction`/`TwigFilter` with a guarded callable. Twig's `TwigFunction` exposes `getName()`, `getCallable()`, and `getOptions()`.

- [ ] **Step 1: Find the Twig collector**

Run: `grep -n "getRegisteredTwigFunctions\|twigFunctions\|filterAndRegister\|getAllTwig" src/Domain/Extension/Service/ExtensionManager.php`. Identify where extension `TwigFunction[]`/`TwigFilter[]` are gathered before `filterAndRegister`.

- [ ] **Step 2: Write the failing test**

```php
test('a throwing extension twig function returns the fallback instead of throwing', function () {
	// Collect the guarded twig functions, find the one whose raw callable throws,
	// invoke it, and assert it returns '' (fallback) rather than throwing.
	$fns = $manager->getAllTwigFunctions(); // or the real collector name from Step 1
	$boom = collect($fns)->first(fn ($f) => $f->getName() === 'acme_boom');
	expect(($boom->getCallable())())->toBe('');
});
```

- [ ] **Step 3: Run test to verify it fails**

Run the filtered test. Expected: FAIL — the raw callable throws.

- [ ] **Step 4: Wrap each TwigFunction/TwigFilter**

Where the collector gathers items per extension `$id`, rebuild each with a guarded callable (fallback `''` so templates render an empty string for the broken call):

```php
use Twig\TwigFunction;
use Twig\TwigFilter;

// functions
foreach ($context->getRegisteredTwigFunctions() as $fn) {
	$callable = $fn->getCallable();
	$guarded  = fn (mixed ...$args): mixed => $this->guard->run(
		$id,
		"twig:fn:{$fn->getName()}",
		fn () => $callable === null ? '' : $callable(...$args),
		fallback: '',
	);
	$functions[] = new TwigFunction($fn->getName(), $guarded, $fn->getOptions());
}

// filters (identical shape with TwigFilter)
foreach ($context->getRegisteredTwigFilters() as $filter) {
	$callable = $filter->getCallable();
	$guarded  = fn (mixed ...$args): mixed => $this->guard->run(
		$id,
		"twig:filter:{$filter->getName()}",
		fn () => $callable === null ? '' : $callable(...$args),
		fallback: '',
	);
	$filters[] = new TwigFilter($filter->getName(), $guarded, $filter->getOptions());
}
```

Use the real collector/accessor names confirmed in Step 1. Preserve `getOptions()` so flags like `is_safe`/`needs_environment` survive — but note: if a function declares `needs_environment`/`needs_context`, Twig prepends those args, and the guarded closure passes them through transparently via `...$args`, so this is safe.

- [ ] **Step 5: Run test to verify it passes**

Run the filtered test. Expected: PASS.

- [ ] **Step 6: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Service/ExtensionManager.php tests/
git commit -m "feat(extensions): guard extension Twig functions/filters against white-screens"
```

### Task 10: Add `EventDispatcher` attribution hand-off (optional hardening)

**Files:**
- Modify: `src/Domain/Event/EventDispatcher.php:130-143`

Because Task 8 already wraps listeners before they reach the dispatcher, the dispatcher's own catch becomes a redundant backstop — which is fine. No behavior change is required here. **Skip unless** you discover a path where extension listeners reach the dispatcher unwrapped; if so, the cleanest fix is ensuring all extension listeners flow through `getAllEventListeners()`. Document the decision in the commit if you touch it; otherwise omit this task.

---

## Phase 5 — Enforce quarantine + recovery UI

### Task 11: Skip loading quarantined extensions

**Files:**
- Modify: `src/Domain/Extension/Service/ExtensionManager.php` (the register/load gate around line 84-87 where `stateRepository->isEnabled()` is checked)
- Test: a load-skip test.

- [ ] **Step 1: Write the failing test**

```php
test('a quarantined extension is not loaded', function () {
	// State: enabled true, but quarantine block present.
	// Run the manager's load/register pass; assert the extension is absent from getLoadedExtensions().
	expect($manager->getLoadedExtensions())->not->toHaveKey('acme/widget');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run the filtered test. Expected: FAIL — quarantined extension still loads.

- [ ] **Step 3: Add the quarantine gate**

At the load gate, alongside the existing enabled check, skip quarantined extensions:

```php
$state = $this->stateRepository->getState($m->id);
if ($state instanceof ExtensionState && $state->isQuarantined()) {
	continue; // auto-quarantined: do not load until operator re-enables
}
```

Place this next to the existing `isEnabled($m->id)` check so a quarantined-but-enabled extension is held back.

- [ ] **Step 4: Run test to verify it passes**

Run the filtered test. Expected: PASS.

- [ ] **Step 5: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Service/ExtensionManager.php tests/
git commit -m "feat(extensions): skip loading auto-quarantined extensions"
```

### Task 12: Re-enable clears quarantine + resets the counter

**Files:**
- Modify: `src/Domain/Extension/Service/ExtensionManager.php` (the `enable()` flow, lines 370-417)
- Test: re-enable-clears test.

- [ ] **Step 1: Write the failing test**

```php
test('enabling a quarantined extension clears the quarantine and counter', function () {
	// Pre-state: quarantined. Call enable(). Assert state no longer quarantined and the
	// cache failure key was deleted.
	$manager->enable('acme/widget');
	$state = $repo->getState('acme/widget');
	expect($state->isQuarantined())->toBeFalse();
	expect($cache->get('extguard:fail:acme/widget'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run the filtered test. Expected: FAIL — quarantine persists after enable.

- [ ] **Step 3: Clear quarantine in `enable()`**

In `enable()`, after loading/creating the state and before/after saving, add:

```php
if ($state->isQuarantined()) {
	$state->clearQuarantine();
	$this->cache->delete("extguard:fail:{$id}");
}
```

Use the real cache delete method name. Ensure this runs whether the extension is being freshly enabled or re-enabled from quarantine.

- [ ] **Step 4: Run test to verify it passes**

Run the filtered test. Expected: PASS.

- [ ] **Step 5: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Service/ExtensionManager.php tests/
git commit -m "feat(extensions): clear quarantine + reset counter on re-enable"
```

### Task 13: Surface quarantine in the extensions admin UI

**Files:**
- Modify: `src/Domain/Extension/Service/ExtensionManager.php` (`buildExtensionInfo()` / `listExtensions()` — add quarantine fields to the per-extension array)
- Modify: `resources/templates/admin/extensions.twig` (the `extensionCard` macro, ~line 94-180)

- [ ] **Step 1: Add quarantine data to the extension info array**

In `buildExtensionInfo()` (the method assembling the per-extension array consumed by `extensions.twig`), add from the extension's `ExtensionState`:

```php
'quarantined'      => $state instanceof ExtensionState && $state->isQuarantined(),
'quarantineReason' => $state?->quarantine['lastError'] ?? null,
```

- [ ] **Step 2: Render the quarantine banner + Re-enable in the card**

In `extensionCard`, after the existing `ext.error` block (~line 121), add:

```twig
{% if ext.quarantined %}
<div class="dash-callout warning" role="alert">
	<strong>Auto-disabled for stability.</strong>
	Total CMS disabled this extension after it crashed repeatedly{% if ext.quarantineReason %}: {{ ext.quarantineReason }}{% endif %}.
	<form method="post" action="{{ cms.dashboard }}/extensions/{{ ext.id }}/enable">
		{{ csrf_field() }}
		<button type="submit" class="btn btn-sm">Re-enable</button>
	</form>
</div>
{% endif %}
```

Confirm the enable route is `POST /admin/extensions/{id}/enable` (mirror the existing disable form's action). If no enable route exists yet, add it next to the disable route in the admin route definitions, pointing at the action that calls `ExtensionManager::enable()`.

- [ ] **Step 3: Manually verify**

Force a quarantine (set a `quarantine` block in `tcms-data/.system/extensions.json` for a test extension), load `/admin/extensions`, confirm the warning + Re-enable button render, click Re-enable, confirm the block clears and the extension loads again.

- [ ] **Step 4: Commit**

```bash
git add src/Domain/Extension/Service/ExtensionManager.php resources/templates/admin/extensions.twig config/
git commit -m "feat(extensions): show auto-quarantine status + re-enable in admin UI"
```

---

## Phase 6 — Integration verification

### Task 14: End-to-end crash-proofing test

**Files:**
- Test: `tests/Feature/ExtensionCrashProofingTest.php` (or Integration, matching the project's feature-test location)

- [ ] **Step 1: Write an integration test**

Build a throwaway fixture extension (under `tests/fixtures/extensions/`) whose Twig function throws. Enable it, render a template that calls the function, and assert:
1. The render succeeds (no exception, page renders with empty output for that call).
2. The failure counter incremented once.
3. With env forced to `prod` and the threshold lowered, repeated renders across simulated requests eventually quarantine it; with env `dev`, it never quarantines.

Match the existing feature/integration test harness (look at `tests/Feature/` for the bootstrapping pattern).

- [ ] **Step 2: Run + iterate to green**

Run: `composer run test -- tests/Feature/ExtensionCrashProofingTest.php`
Expected: PASS.

- [ ] **Step 3: Full quality gate**

```bash
composer run stan
composer run test
```

Expected: PHPStan Level 8 clean; tests green.

- [ ] **Step 4: Commit**

```bash
git add tests/
git commit -m "test(extensions): end-to-end crash-proofing + quarantine coverage"
```

---

## Self-review notes (for the implementer)

- **Spec coverage:** Site Environment setting (Tasks 1-3), env-aware quarantine gate incl. preview exclusion (Task 4), distinct quarantine state (Task 5), Guard with per-request dedup + threshold (Task 6), wrap-at-registration for events + Twig (Tasks 8-9), skip-loading + recovery + UI (Tasks 11-13). Page-middleware and form-action wrapping are **deliberately deferred** — research showed form-action registration is already try/caught and page middleware is less common; if you want full coverage, add analogous guard-wrapping tasks in `getAll*` for those two, fallback = a pass-through/no-op. Note this gap in the PR description so it's a conscious choice, not a silent cap.
- **Cache method names** (`increment`/`get`/`delete` + TTL) and the **state repository setter** name are the two things to confirm against the real classes in Task 6 Step 3 before trusting the copy-paste code.
- **`gmdate('c')`** is the timestamp source (PHP); fine to use.
