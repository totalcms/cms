# Extension Stability — Plan 2: Performance Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make extension performance cost visible and impossible to ignore — per-extension timing surfaced in the admin UI, plus soft budget warnings — without the measurement itself taxing production.

**Architecture:** A single sampling dial (`extensionProfileSampleRate`, 0 = off, default 50, 1 = every request) governs whether a request is profiled in production; dev/preview always profile. An `ExtensionProfiler` accumulates per-extension hook durations into request-local memory (cheap), and flushes rolling averages to the APCu-first cache once per request, only on sampled requests. Timing brackets live inside the same `ExtensionGuard::run()` wrap points built in Plan 1 — no new wrapping. The extensions admin page reads the cached metrics and renders them inline per extension, with a banner when an extension or the whole stack exceeds a configurable budget.

**Tech Stack:** PHP 8.2+, Slim 4, PHP-DI, Twig 3, Pest, PHPStan Level 8, `CacheManager` (APCu-first), `hrtime(true)` for timing.

**Spec:** `docs/planning/extension-stability-guardrails.md`
**Depends on:** Plan 1 (ExtensionGuard, EnvironmentResolver, the Site Environment setting, the extensions admin card).

---

## Design decisions locked from the spec (read before starting)

- **Profiling overhead is confined to the timing half.** Crash containment (Plan 1) is untouched and stays always-on.
- **Sampling dial governs PRODUCTION only.** Dev/preview always profile fully. On a non-sampled prod request, timers never start (zero cost).
- **Default sample rate: 50** (1-in-50). `0` = off. `1` = every request.
- **No auto-action on slowness.** Soft budget warnings only — the operator decides. (Quarantine stays crash-only, from Plan 1.)
- **Default soft budgets:** per-extension ~200ms/request, per-stack ~500ms/request. Configurable.
- **Hot-path discipline:** per-call timing is ~2 `hrtime()` calls accumulated into an in-memory counter — no cache I/O per call. Flush once at end of request.

## File Structure

**Create:**
- `src/Domain/Extension/Service/ExtensionProfiler.php` — request-local timing accumulator + sampling decision + end-of-request flush.
- `src/Middleware/Development/ExtensionProfileFlushMiddleware.php` — flushes the profiler at end of request (the terminating hook).
- `tests/Unit/Domain/Extension/ExtensionProfilerTest.php`

**Modify:**
- `resources/schemas/settings/general.json` — add `extensionProfileSampleRate` + the two budget fields.
- `src/Domain/Extension/Service/ExtensionGuard.php` — bracket the guarded call with the profiler.
- `src/Domain/Extension/Service/ExtensionManager.php` — time `boot()` per extension; expose metrics to the admin card.
- `resources/templates/admin/extensions.twig` — render per-extension metrics + budget warning.
- DI definitions — register `ExtensionProfiler`; wire it into `ExtensionGuard`.
- The middleware stack registration — add the flush middleware (outermost, so it runs last).

---

## Phase 1 — Settings: sampling dial + budgets

### Task 1: Add profiling + budget settings to general settings

**Files:**
- Modify: `resources/schemas/settings/general.json`

- [ ] **Step 1: Add three number fields**

```json
"extensionProfileSampleRate": {
    "type": "number",
    "field": "number",
    "label": "Extension profiling (1 in N requests)",
    "help": "Production only. 0 disables profiling entirely. 50 samples 1 in 50 requests. 1 profiles every request. Development and preview always profile fully.",
    "default": 50,
    "min": 0
},
"extensionBudgetMsPerExtension": {
    "type": "number",
    "field": "number",
    "label": "Per-extension slow warning (ms / request)",
    "help": "Warn when a single extension adds more than this many milliseconds to a request. 0 disables the warning.",
    "default": 200,
    "min": 0
},
"extensionBudgetMsPerStack": {
    "type": "number",
    "field": "number",
    "label": "Total extension slow warning (ms / request)",
    "help": "Warn when all extensions combined add more than this many milliseconds to a request. 0 disables the warning.",
    "default": 500,
    "min": 0
}
```

- [ ] **Step 2: Expose on Config**

Add the three properties to `src/Support/Config.php` (defaults 50 / 200 / 500), read from `$settings` in the constructor, matching the existing pattern (e.g. `$this->extensionProfileSampleRate = (int)($settings['extensionProfileSampleRate'] ?? 50);`).

- [ ] **Step 3: Verify render + persist**

Load `/admin/settings` general section; confirm the three numeric fields render and save into `settings.json`.

- [ ] **Step 4: Commit**

```bash
git add resources/schemas/settings/general.json src/Support/Config.php
git commit -m "feat(settings): add extension profiling sample rate + slow-warning budgets"
```

---

## Phase 2 — The profiler

### Task 2: `ExtensionProfiler` — sampling decision, accumulation, flush

**Files:**
- Create: `src/Domain/Extension/Service/ExtensionProfiler.php`
- Test: `tests/Unit/Domain/Extension/ExtensionProfilerTest.php`

Behavior:
- On construction, decide once whether **this request** is profiled: `true` if `EnvironmentResolver::shouldSurfaceErrors()` (i.e. dev/preview), else (prod) `true` with probability `1/sampleRate` when `sampleRate >= 1`, never when `sampleRate === 0`. Use `random_int(1, sampleRate) === 1`.
- `record(extensionId, micros)` adds to an in-memory per-extension accumulator (only when profiled).
- `time(extensionId, callable)` brackets a call with `hrtime(true)` and records the delta (only when profiled; when not profiled it just calls through with zero overhead beyond the active-flag check).
- `flush()` writes rolling metrics to the cache (per-extension: last-request total ms, a running average, sample count) — called once per request by the middleware.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Service\ExtensionProfiler;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;
use TotalCMS\Support\Config;

function profEnv(string $env, bool $preview = false): EnvironmentResolver
{
	$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->env = $env;
	return new EnvironmentResolver($config, $preview);
}

describe('ExtensionProfiler', function () {
	test('dev always profiles', function () {
		$p = new ExtensionProfiler(profEnv('dev'), fakeCache(), sampleRate: 0, logger: new NullLogger());
		expect($p->isProfiling())->toBeTrue();
	});

	test('prod with sampleRate 0 never profiles', function () {
		$p = new ExtensionProfiler(profEnv('prod'), fakeCache(), sampleRate: 0, logger: new NullLogger());
		expect($p->isProfiling())->toBeFalse();
	});

	test('prod with sampleRate 1 always profiles', function () {
		$p = new ExtensionProfiler(profEnv('prod'), fakeCache(), sampleRate: 1, logger: new NullLogger());
		expect($p->isProfiling())->toBeTrue();
	});

	test('time() accumulates per extension when profiling', function () {
		$p = new ExtensionProfiler(profEnv('dev'), fakeCache(), sampleRate: 1, logger: new NullLogger());
		$p->time('acme/widget', fn () => usleep(1000));
		expect($p->totalMicrosFor('acme/widget'))->toBeGreaterThan(0);
	});

	test('time() returns the callable result', function () {
		$p = new ExtensionProfiler(profEnv('dev'), fakeCache(), sampleRate: 1, logger: new NullLogger());
		expect($p->time('acme/widget', fn () => 7))->toBe(7);
	});

	test('flush writes per-extension metrics to cache', function () {
		$cache = fakeCache();
		$p = new ExtensionProfiler(profEnv('dev'), $cache, sampleRate: 1, logger: new NullLogger());
		$p->time('acme/widget', fn () => usleep(1000));
		$p->flush();
		expect($cache->wrote('extprof:acme/widget'))->toBeTrue();
	});
});
```

`fakeCache()` is an anonymous in-test double matching the real `CacheManager` surface (`get`, `set`/`put` with TTL); add a `wrote(key)` spy helper.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer run test -- tests/Unit/Domain/Extension/ExtensionProfilerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\Service\CacheManager;

/**
 * Per-request timing for extension hooks. Cheap and sampled.
 *
 * In dev/preview: always profiles. In prod: profiles 1-in-N requests
 * (sampleRate), or never when sampleRate is 0. On a non-profiled request
 * time() is a near-zero pass-through.
 */
final class ExtensionProfiler
{
	private readonly bool $profiling;

	/** @var array<string,int> extensionId => accumulated microseconds */
	private array $totals = [];

	public function __construct(
		EnvironmentResolver $env,
		private readonly CacheManager $cache,
		private readonly int $sampleRate,
		private readonly LoggerInterface $logger,
	) {
		$this->profiling = $this->decide($env, $sampleRate);
	}

	private function decide(EnvironmentResolver $env, int $sampleRate): bool
	{
		if ($env->shouldSurfaceErrors()) {
			return true; // dev / preview — always profile
		}
		if ($sampleRate <= 0) {
			return false; // prod, profiling disabled
		}
		return random_int(1, $sampleRate) === 1; // prod, 1-in-N
	}

	public function isProfiling(): bool
	{
		return $this->profiling;
	}

	/**
	 * @template T
	 * @param callable():T $callable
	 * @return T
	 */
	public function time(string $extensionId, callable $callable): mixed
	{
		if (!$this->profiling) {
			return $callable();
		}
		$start  = hrtime(true);
		$result = $callable();
		$this->totals[$extensionId] = ($this->totals[$extensionId] ?? 0) + (int)((hrtime(true) - $start) / 1000);
		return $result;
	}

	public function record(string $extensionId, int $micros): void
	{
		if ($this->profiling) {
			$this->totals[$extensionId] = ($this->totals[$extensionId] ?? 0) + $micros;
		}
	}

	public function totalMicrosFor(string $extensionId): int
	{
		return $this->totals[$extensionId] ?? 0;
	}

	/** Flush rolling per-extension metrics to the cache. Call once per request. */
	public function flush(): void
	{
		if (!$this->profiling || $this->totals === []) {
			return;
		}
		foreach ($this->totals as $extensionId => $micros) {
			$key  = "extprof:{$extensionId}";
			$prev = $this->cache->get($key);
			$ms   = $micros / 1000;

			if (is_array($prev)) {
				$samples = (int)$prev['samples'] + 1;
				$avg     = ((float)$prev['avgMs'] * (int)$prev['samples'] + $ms) / $samples;
			} else {
				$samples = 1;
				$avg     = $ms;
			}

			$this->cache->set($key, [
				'lastMs'   => round($ms, 1),
				'avgMs'    => round($avg, 1),
				'samples'  => $samples,
			], 86400);
		}
	}
}
```

Adjust `$this->cache->get/set` to the real `CacheManager` method names + TTL signature (confirm against `src/Domain/Cache/Service/CacheManager.php`).

- [ ] **Step 4: Run test to verify it passes**

Run: `composer run test -- tests/Unit/Domain/Extension/ExtensionProfilerTest.php`
Expected: PASS (all 6).

- [ ] **Step 5: Register in DI**

Register `ExtensionProfiler::class` wiring `EnvironmentResolver`, `CacheManager`, `Config->extensionProfileSampleRate`, logger. It must be request-scoped (one instance per request) so `isProfiling()` is decided once and `$totals` accumulate correctly — confirm the container shares a single instance per request (default PHP-DI shared behavior is fine since each request is a fresh container build).

- [ ] **Step 6: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Service/ExtensionProfiler.php tests/Unit/Domain/Extension/ExtensionProfilerTest.php config/
git commit -m "feat(extensions): add sampled ExtensionProfiler for per-extension timing"
```

---

## Phase 3 — Bracket the Guard + time boot

### Task 3: Time guarded hook calls inside `ExtensionGuard`

**Files:**
- Modify: `src/Domain/Extension/Service/ExtensionGuard.php`
- Test: extend `ExtensionGuardTest.php`

- [ ] **Step 1: Write the failing test**

```php
test('guard times the call through the profiler', function () {
	$profiler = new ExtensionProfiler(guardEnv('dev'), fakeCache(), sampleRate: 1, logger: new NullLogger());
	$guard = new ExtensionGuard(guardEnv('prod'), fakeCache(), fakeStateRepo(), new NullLogger(), profiler: $profiler);
	$guard->run('acme/widget', 'twig:fn', fn () => usleep(500), fallback: null);
	expect($profiler->totalMicrosFor('acme/widget'))->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run to verify it fails**

Run the filtered test. Expected: FAIL — `ExtensionGuard` has no `profiler` constructor arg.

- [ ] **Step 3: Inject the profiler and bracket the call**

Add `private readonly ExtensionProfiler $profiler,` to the `ExtensionGuard` constructor (after the logger; default-construct a no-op profiler is unnecessary — make it required and update the DI definition + Plan 1 tests' guard construction). Change `run()` to time the call:

```php
public function run(string $extensionId, string $hookType, callable $callable, mixed $fallback): mixed
{
	try {
		return $this->profiler->time($extensionId, $callable);
	} catch (\Throwable $e) {
		$this->recordFailure($extensionId, $hookType, $e);
		return $fallback;
	}
}
```

Timing wraps inside the try, so a thrown call is both timed-up-to-throw-safe (the profiler's post-increment line is skipped on throw — acceptable; failed calls don't pollute timing) and still caught.

- [ ] **Step 4: Run to verify it passes**

Run the filtered test (and the existing Guard tests — update their constructor calls to pass a profiler). Expected: PASS.

- [ ] **Step 5: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Service/ExtensionGuard.php tests/ config/
git commit -m "feat(extensions): time guarded hook calls via the profiler"
```

### Task 4: Time `boot()` per extension

**Files:**
- Modify: `src/Domain/Extension/Service/ExtensionManager.php` (boot loop ~line 171-189)

- [ ] **Step 1: Bracket each extension's boot**

In the boot loop, wrap the `$extension->boot($context)` call:

```php
$start = hrtime(true);
try {
	$extension->boot($context);
} catch (\Throwable $e) {
	// existing catch/log/state-record stays
}
$this->profiler->record($id, (int)((hrtime(true) - $start) / 1000));
```

Inject `ExtensionProfiler` into `ExtensionManager` (constructor + DI). Boot timing records even on the non-profiled fast path is a no-op (profiler guards internally), so no extra branch needed.

- [ ] **Step 2: Verify existing extension tests still pass**

Run: `composer run test -- tests/Unit/Domain/Extension`. Expected: PASS (update any direct `ExtensionManager` construction to pass a profiler double).

- [ ] **Step 3: Run stan + commit**

```bash
composer run stan
git add src/Domain/Extension/Service/ExtensionManager.php config/ tests/
git commit -m "feat(extensions): record per-extension boot time in the profiler"
```

---

## Phase 4 — Flush + surface

### Task 5: End-of-request flush middleware

**Files:**
- Create: `src/Middleware/Development/ExtensionProfileFlushMiddleware.php`
- Modify: the middleware registration (find via `grep -rn "DevModeMiddleware" config/`)

- [ ] **Step 1: Write the middleware**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Development;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\Extension\Service\ExtensionProfiler;

/**
 * Flushes accumulated extension timing to the cache after the response is built.
 * Registered outermost so it runs last (after all extension hooks have fired).
 */
final readonly class ExtensionProfileFlushMiddleware implements MiddlewareInterface
{
	public function __construct(private ExtensionProfiler $profiler)
	{
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$response = $handler->handle($request);
		$this->profiler->flush();
		return $response;
	}
}
```

- [ ] **Step 2: Register it outermost in the middleware stack**

Add it to the middleware registration next to `DevModeMiddleware`. In Slim, middleware added *last* runs *outermost* — ensure flush wraps the whole pipeline so all hook timing is captured. Confirm ordering by reading the existing registration.

- [ ] **Step 3: Manually verify**

Load a page that uses an extension Twig function (dev env → always profiles). Inspect the cache (or add a temporary log) and confirm an `extprof:{id}` entry appears with `lastMs`/`avgMs`/`samples`.

- [ ] **Step 4: Commit**

```bash
git add src/Middleware/Development/ExtensionProfileFlushMiddleware.php config/
git commit -m "feat(extensions): flush extension timing to cache at end of request"
```

### Task 6: Read metrics into the admin extension info

**Files:**
- Modify: `src/Domain/Extension/Service/ExtensionManager.php` (`buildExtensionInfo()`)

- [ ] **Step 1: Add metrics to the per-extension array**

In `buildExtensionInfo()`, read the cached metrics and attach them:

```php
$metrics = $this->cache->get("extprof:{$id}");
'health' => is_array($metrics) ? [
	'lastMs'  => $metrics['lastMs'] ?? null,
	'avgMs'   => $metrics['avgMs'] ?? null,
	'samples' => $metrics['samples'] ?? 0,
] : null,
'errorCount' => (int)($this->cache->get("extguard:fail:{$id}") ?? 0),
```

Inject `CacheManager` into `ExtensionManager` if not already present.

- [ ] **Step 2: Verify**

Run the extensions admin page; dump `extensions` to confirm `health` + `errorCount` are present for an extension that has run.

- [ ] **Step 3: Commit**

```bash
git add src/Domain/Extension/Service/ExtensionManager.php config/
git commit -m "feat(extensions): expose health metrics to the extensions admin view"
```

### Task 7: Render Extension Health inline + budget warning

**Files:**
- Modify: `resources/templates/admin/extensions.twig` (the `extensionCard` macro)

- [ ] **Step 1: Render metrics inline**

In the card, near the version/author row, add:

```twig
{% if ext.health %}
<div class="ext-health" style="display:flex; gap:1rem; font-size:0.85em;">
	<span title="Average ms this extension adds per request">~{{ ext.health.avgMs }}ms avg</span>
	<span title="Most recent request">{{ ext.health.lastMs }}ms last</span>
	{% if ext.errorCount > 0 %}<span class="dash-badge warning">{{ ext.errorCount }} recent errors</span>{% endif %}
</div>
{% endif %}
```

- [ ] **Step 2: Render the per-extension budget warning**

When the average exceeds the per-extension budget (passed into the template from `Config->extensionBudgetMsPerExtension`, `0` = disabled):

```twig
{% if ext.health and budgets.perExtension > 0 and ext.health.avgMs > budgets.perExtension %}
<div class="dash-callout warning">
	{{ ext.name }} is adding ~{{ ext.health.avgMs }}ms per request — above the {{ budgets.perExtension }}ms guideline.
</div>
{% endif %}
```

Pass `budgets` (`{perExtension, perStack}`) from `AdminExtensionsAction` into the template.

- [ ] **Step 3: Render the per-stack warning (page-level)**

Above the extension grid, sum `avgMs` across extensions and warn if over `budgets.perStack`:

```twig
{% set totalAvg = 0 %}
{% for ext in extensions %}{% if ext.health %}{% set totalAvg = totalAvg + ext.health.avgMs %}{% endif %}{% endfor %}
{% if budgets.perStack > 0 and totalAvg > budgets.perStack %}
<div class="dash-callout warning">
	Your enabled extensions add ~{{ totalAvg|round }}ms per request combined — above the {{ budgets.perStack }}ms guideline. Consider disabling unused extensions.
</div>
{% endif %}
```

- [ ] **Step 4: Manually verify**

In dev, with a deliberately slow test extension (add a `usleep`), confirm the avg/last render and the warning appears when over budget; set the budget to 0 and confirm the warning disappears.

- [ ] **Step 5: Commit**

```bash
git add resources/templates/admin/extensions.twig src/Action/Admin/AdminExtensionsAction.php
git commit -m "feat(extensions): show extension health + soft budget warnings in admin"
```

---

## Phase 5 — Verification

### Task 8: Profiler integration test + quality gate

**Files:**
- Test: `tests/Feature/ExtensionProfilingTest.php`

- [ ] **Step 1: Write the integration test**

Using a fixture extension with a Twig function that `usleep`s a known amount: in a forced `dev` env, render a template that calls it, run the flush, and assert the cached `extprof:{id}` metric reflects roughly the sleep. In a forced `prod` env with `sampleRate: 0`, assert no metric is written.

- [ ] **Step 2: Run + iterate to green**

Run: `composer run test -- tests/Feature/ExtensionProfilingTest.php`. Expected: PASS.

- [ ] **Step 3: Full quality gate**

```bash
composer run stan
composer run test
```

Expected: clean.

- [ ] **Step 4: Commit**

```bash
git add tests/
git commit -m "test(extensions): profiling integration coverage"
```

---

## Self-review notes (for the implementer)

- **Spec coverage:** sampling dial (Task 1), profiler with sampling + accumulation + flush (Task 2), timing inside the Guard + boot (Tasks 3-4), end-of-request flush (Task 5), health surfaced inline in the existing UI (Tasks 6-7), soft budget warnings per-extension + per-stack (Task 7). No auto-action on slowness — warnings only. ✔ matches spec.
- **Confirm before trusting copy-paste:** the `CacheManager` get/set signature + TTL (Task 2 Step 3), and the middleware ordering so flush is outermost (Task 5 Step 2).
- **Request-scoping:** `ExtensionProfiler` must be one shared instance per request. Verify the container shares it (don't register as a factory that returns a fresh instance per `get()`).
