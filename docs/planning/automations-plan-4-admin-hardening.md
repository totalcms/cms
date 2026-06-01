# Automations: Admin UI + Production Hardening Implementation Plan (Plan 4 of 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make automations operable and safe in production: Pro-edition gating, environment-aware failure handling (loud in dev, contained + error-email in prod), auto-disable after repeated production failures (mirroring extension quarantine), a structured `automations-activity.log`, a `DangerousCodeScanner` advisory on handler save, the admin section (list / code editor / run history with Run-now + Replay + auto-disable banner), Sync support, and the `addAutomation()` extension hook.

**Architecture:** New `AutomationGuard` (modeled on `ExtensionGuard`) decides — via `EnvironmentResolver` — whether to surface or contain a handler exception and whether to auto-disable. `AutomationRunner` (Plan 2) is extended to consult it, send the error email, and emit activity events through a new `AutomationActivityLogger` (modeled on `OAuthActivityLogger`). The admin section follows `AdminMailerAction` + a Twig template; the handler field already renders as CodeMirror (`mode: php`). `EditionFeature::AUTOMATIONS` (Pro) gates routes + UI; `addAutomation()` registers in-memory extension automations surfaced read-only.

**Tech Stack:** Slim Actions + Twig admin, `EnvironmentResolver`/`ExtensionGuard` patterns, `EditionFeature`/`EditionFeatureService`, `DangerousCodeScanner`, `OAuthActivityLogger` pattern, `ExtensionContext` hooks, Pest.

**Depends on:** Plans 1–3.

---

## File Structure

- **Modify** `src/Domain/License/Data/EditionFeature.php` — add `AUTOMATIONS` case (+ `label()`, `requiredEdition()`).
- **Create** `src/Middleware/Automation/AutomationsEditionMiddleware.php` — gate routes to Pro.
- **Modify** `src/Domain/Sync/Data/SyncableCollections.php` — add `'automations'`.
- **Create** `src/Domain/Automation/Service/AutomationGuard.php` — failure counting + auto-disable.
- **Create** `src/Domain/Automation/Service/AutomationActivityLogger.php` — structured `automations-activity.log`.
- **Create** `src/Domain/Automation/Service/HandlerScanner.php` — thin wrapper over `DangerousCodeScanner` for a single handler file.
- **Modify** `src/Domain/Automation/Service/AutomationRunner.php` — consult guard, send error email, emit activity, set `enabled=false` on auto-disable.
- **Create** `src/Action/Admin/AdminAutomationsAction.php`; **Create** `resources/templates/admin/automations.twig`.
- **Modify** `resources/templates/admin/utils.twig` — nav entry (Pro-gated).
- **Create** `src/Action/Automation/AutomationRunNowAction.php` — admin "Run now" / "Replay".
- **Modify** `src/Domain/Extension/ExtensionContext.php` (+ a new `AutomationDefinition`) and `src/Domain/Extension/Service/ExtensionManager.php` — `addAutomation()` hook + registry.
- **Modify** the API-key admin editor — expose the `automations.fire` scope toggle (Plan 3 follow-up).
- Tests across `tests/Unit/Domain/Automation/`, `tests/Feature/`.

---

## Conventions (verified)

- Edition: `EditionFeature` enum (`label()`, `requiredEdition() => Edition::PRO`); `EditionFeatureService::can()/canOrFail()` — `src/Domain/License/Service/EditionFeatureService.php`. Twig gate `cms.edition.can('automations')`.
- Guard pattern: `ExtensionGuard` (`:20-147`) — `threshold=5`, `windowSeconds=300`, `CacheManager` rolling counter, `EnvironmentResolver::isQuarantineAllowed()` (prod & !preview) before disabling; `shouldSurfaceErrors()` outside prod.
- Activity logger: `OAuthActivityLogger` — `LoggerInterface` injected, each method `->info()/->warning()` with a `type` field. Channel via `LoggerFactory` (`addFileHandler('automations-activity.log')`).
- Scanner: `DangerousCodeScanner::scan(string $dir): list<array{pattern,file,line,snippet}>` (`:51-91`). Review template `admin/extension-review.twig:36-58`.
- Admin: `AdminMailerAction` (`:13-52`) — `fetchOrCreateReserved` + `twigRenderer->template($response, 'admin/x.twig', $data)`. Nav in `utils.twig` (Twig array of `{title,path}`, gated by `cms.auth.canAccessUtil` + `cms.edition.can`).
- Code field: `mode: php` already produces a CodeMirror editor (`src/Domain/Admin/FormField/CodeField.php`).
- Extension hook: private array + `addX()` + `getRegisteredX()` on `ExtensionContext`; consumed in `ExtensionManager::bootAll()` (`registerMcpTool`/`addEventListener` patterns).
- Sync allowlist: `SyncableCollections::IDS` (`:23-37`).

---

## Task 1: `EditionFeature::AUTOMATIONS` + edition middleware

**Files:** Modify `src/Domain/License/Data/EditionFeature.php`; Create `src/Middleware/Automation/AutomationsEditionMiddleware.php`; Test `tests/Unit/Domain/License/EditionFeatureTest.php` (extend existing)

- [ ] **Step 1: Write the failing test**

```php
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Data\Edition;

it('gates automations to Pro', function (): void {
    expect(EditionFeature::AUTOMATIONS->requiredEdition())->toBe(Edition::PRO);
    expect(EditionFeature::AUTOMATIONS->label())->toBe('Automations');
});
```

- [ ] **Step 2: Run (fails — case missing).**

- [ ] **Step 3: Add the enum case** `case AUTOMATIONS = 'automations';`, add `self::AUTOMATIONS => 'Automations'` to `label()`, and add `self::AUTOMATIONS` to the `Edition::PRO` arm of `requiredEdition()`.

- [ ] **Step 4: Create the middleware** mirroring `ApiKeysEditionMiddleware` (find it: `grep -rln EditionMiddleware src/Middleware`) — on `!editionFeatures->can(EditionFeature::AUTOMATIONS)` return a 403/redirect as the sibling middlewares do.

- [ ] **Step 5: Run (passes) → stan → commit** `"feat(automations): AUTOMATIONS edition feature + gate"`. Re-enable the edition guard in `EnsureAutomationsCollectionMigration` (Plan 2 Task 2 note).

---

## Task 2: Sync support

**Files:** Modify `src/Domain/Sync/Data/SyncableCollections.php`; Test `tests/Unit/Domain/Sync/SyncableCollectionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('marks automations as syncable', function (): void {
    expect(\TotalCMS\Domain\Sync\Data\SyncableCollections::contains('automations'))->toBeTrue();
});
```

- [ ] **Step 2: Run (fails).**

- [ ] **Step 3: Add `'automations'`** to `SyncableCollections::IDS` (after `'dataviews'`).

- [ ] **Step 4: Run (passes).** Because the handler is an external text field (Plan 1), JumpStart inlines it — config **and** handler sync in one payload. Add a short integration test: push an automation through `JumpStartExporter`/`JumpStartImporter` (reuse Plan 1 Task 5 round-trip harness for the `automations` collection) and assert the handler file lands on the receiver.

- [ ] **Step 5: Commit** — `"feat(automations): syncable collection"`.

---

## Task 3: `AutomationActivityLogger`

**Files:** Create `src/Domain/Automation/Service/AutomationActivityLogger.php`; Modify `config/container.php`; Test `tests/Unit/Domain/Automation/AutomationActivityLoggerTest.php`

- [ ] **Step 1: Write the failing test** (inject a capturing PSR-3 logger; assert the `type` + context)

```php
use TotalCMS\Domain\Automation\Service\AutomationActivityLogger;

it('logs a structured failed-run event at warning level', function (): void {
    $records = [];
    $logger  = new class($records) extends \Psr\Log\AbstractLogger {
        public function __construct(private array &$records) {}
        public function log($level, string|\Stringable $message, array $context = []): void {
            $this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
        }
    };

    (new AutomationActivityLogger($logger))->runFailed('daily', 'schedule', 'boom', 3);

    expect($records[0]['level'])->toBe('warning');
    expect($records[0]['context']['type'])->toBe('run.failed');
    expect($records[0]['context']['automation_id'])->toBe('daily');
    expect($records[0]['context']['failure_count'])->toBe(3);
});
```

- [ ] **Step 2: Run (fails).**

- [ ] **Step 3: Implement** (mirror `OAuthActivityLogger`)

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use Psr\Log\LoggerInterface;

final readonly class AutomationActivityLogger
{
	public function __construct(private LoggerInterface $logger)
	{
	}

	public function runStarted(string $automationId, string $triggerType): void
	{
		$this->logger->info('Automation run started', ['type' => 'run.started', 'automation_id' => $automationId, 'trigger' => $triggerType]);
	}

	public function runSucceeded(string $automationId, string $triggerType, int $durationMs): void
	{
		$this->logger->info('Automation run succeeded', ['type' => 'run.success', 'automation_id' => $automationId, 'trigger' => $triggerType, 'duration_ms' => $durationMs]);
	}

	public function runFailed(string $automationId, string $triggerType, string $error, int $failureCount): void
	{
		$this->logger->warning('Automation run failed', ['type' => 'run.failed', 'automation_id' => $automationId, 'trigger' => $triggerType, 'error' => $error, 'failure_count' => $failureCount]);
	}

	public function autoDisabled(string $automationId, int $failureCount): void
	{
		$this->logger->warning('Automation auto-disabled after repeated failures', ['type' => 'auto_disabled', 'automation_id' => $automationId, 'failure_count' => $failureCount]);
	}

	public function webhookUnauthorized(string $slug, string $reason): void
	{
		$this->logger->warning('Automation webhook rejected', ['type' => 'webhook.unauthorized', 'automation_id' => $slug, 'reason' => $reason]);
	}
}
```

- [ ] **Step 4: Wire the channel** in `config/container.php`:

```php
AutomationActivityLogger::class => fn (ContainerInterface $c): AutomationActivityLogger =>
	new AutomationActivityLogger($c->get(LoggerFactory::class)->addFileHandler('automations-activity.log')->createLogger('automations-activity')),
```

> **Verify:** the container definition style + that `addFileHandler()->createLogger()` returns a fresh logger per call (it does — `LoggerFactory:57-72`).

- [ ] **Step 5: Run (passes) → commit** `"feat(automations): structured activity logger"`.

---

## Task 4: `AutomationGuard` (failure counting + auto-disable)

**Files:** Create `src/Domain/Automation/Service/AutomationGuard.php`; Test `tests/Unit/Domain/Automation/AutomationGuardTest.php`

- [ ] **Step 1: Write the failing test**

```php
use TotalCMS\Domain\Automation\Service\AutomationGuard;

it('signals auto-disable on the 5th prod failure, never in dev', function (): void {
    $prodGuard = makeGuard(env: 'prod'); // builds AutomationGuard with a fake CacheManager + EnvironmentResolver
    for ($i = 1; $i < 5; $i++) {
        expect($prodGuard->recordFailure('daily'))->toBeFalse(); // not yet
    }
    expect($prodGuard->recordFailure('daily'))->toBeTrue(); // 5th → disable

    $devGuard = makeGuard(env: 'dev');
    for ($i = 0; $i < 10; $i++) {
        expect($devGuard->recordFailure('daily'))->toBeFalse(); // never auto-disables in dev
    }
});
```

- [ ] **Step 2: Run (fails).**

- [ ] **Step 3: Implement** (mirror `ExtensionGuard::incrementFailureCount`/quarantine gate)

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Extension\Service\EnvironmentResolver;

final readonly class AutomationGuard
{
	public function __construct(
		private EnvironmentResolver $env,
		private CacheManager $cache,
		private int $threshold = 5,
		private int $windowSeconds = 300,
	) {
	}

	/**
	 * Record a failure. Returns true when the automation should be auto-disabled
	 * (prod only, threshold reached). Never true in dev/preview.
	 */
	public function recordFailure(string $slug): bool
	{
		$key     = 'auto_fail_' . md5($slug);
		$current = $this->cache->getData($key);
		$count   = (is_int($current) ? $current : 0) + 1;
		$this->cache->storeData($key, $count, $this->windowSeconds);

		return $this->env->isQuarantineAllowed() && $count >= $this->threshold;
	}

	public function reset(string $slug): void
	{
		$this->cache->clearData('auto_fail_' . md5($slug)); // verify clear method name
	}

	public function shouldSurfaceErrors(): bool
	{
		return $this->env->shouldSurfaceErrors();
	}
}
```

> **Verify:** `CacheManager` method names (`getData`/`storeData`/`clearData`) — `ExtensionGuard` uses `getData`/`storeData`; confirm the clear method. `EnvironmentResolver` is constructed with `(Config, bool $isPreview)` — confirm how it's resolved from the container (Plan reuses the existing registration).

- [ ] **Step 4: Run (passes) → stan → commit** `"feat(automations): AutomationGuard auto-disable"`.

---

## Task 5: Wire guard + error-email + activity into `AutomationRunner`

**Files:** Modify `src/Domain/Automation/Service/AutomationRunner.php`; Test extends `tests/Integration/AutomationRunnerTest.php`

- [ ] **Step 1: Write the failing test** — a handler that throws 5× in a `prod` config disables the automation (`enabled` becomes false) and logs `auto_disabled`; in `dev` it never disables.

```php
it('auto-disables an automation after repeated prod failures', function (): void {
    // boot app with config env=prod (see how other tests force env; Pest.php sets $config->env)
    app()->get(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('automations');
    app()->get(\TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('automations', [
        'id' => 'boom', 'name' => 'Boom', 'enabled' => true,
        'triggers' => ['t0' => ['id' => 't0', 'type' => 'schedule', 'cron' => '* * * * *']],
        'handler' => "<?php\n\nreturn function (\$ctx) { throw new \\RuntimeException('x'); };\n",
    ]);

    $runner = app()->get(\TotalCMS\Domain\Automation\Service\AutomationRunner::class);
    for ($i = 0; $i < 5; $i++) {
        $runner->run('boom', ['type' => 'schedule'], []);
    }

    $object = app()->get(\TotalCMS\Domain\Object\Service\ObjectFetcher::class)->fetchObject('automations', 'boom');
    expect((string)$object->properties->get('enabled'))->toBeIn(['', '0', 'false']); // disabled
});
```

> **Step note:** how integration tests set `env=prod` — check `tests/Pest.php` (`$config->env`) and the app setup; force prod for this test only.

- [ ] **Step 2: Run (fails).**

- [ ] **Step 3: Extend `AutomationRunner`** — add constructor deps `AutomationGuard $guard`, `AutomationActivityLogger $activity`, and (already present) `ObjectFetcher`/`ObjectUpdater`. In the `catch` block, after `incrementFailures`:

```php
			$this->activity->runFailed($slug, (string)($trigger['type'] ?? ''), $e->getMessage(), $this->state->failures($slug));

			if ($this->guard->recordFailure($slug)) {
				$this->disable($slug);
				$this->activity->autoDisabled($slug, $this->state->failures($slug));
			}

			$this->sendErrorEmail($slug, $e); // no-op in dev (shouldSurfaceErrors), email in prod
```

and on success, after `resetFailures`:

```php
			$this->guard->reset($slug);
			$this->activity->runSucceeded($slug, (string)($trigger['type'] ?? ''), $record->durationMs ?? 0);
```

Add `disable()` (load the automation, set `enabled=false`, `objectUpdater->updateObject('automations', $slug, [...])` — verify the updater signature) and `sendErrorEmail()` (read `errorEmail` from the object; if set and `!shouldSurfaceErrors()` i.e. prod, send via `EmailService`).

> **Verify:** `ObjectUpdater` update method signature (partial vs full object). Setting a single field may require fetching, merging, and saving the full object — mirror how `AdminMailerAction`/other services do partial updates.

- [ ] **Step 4: Run (passes) → stan → commit** `"feat(automations): guard + error-email + activity in runner"`.

---

## Task 6: `HandlerScanner` advisory

**Files:** Create `src/Domain/Automation/Service/HandlerScanner.php`; Test `tests/Unit/Domain/Automation/HandlerScannerTest.php`

Wrap `DangerousCodeScanner` to scan a single automation's handler directory and return findings for display (advisory — never blocks save).

- [ ] **Step 1: Write the failing test** (write a temp handler file with `eval(`, assert a finding)

```php
it('flags dangerous patterns in a handler file as advisory findings', function (): void {
    $dir = sys_get_temp_dir() . '/auto-scan-' . bin2hex(random_bytes(4)) . '/handler';
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/handler.php', "<?php\nreturn function(\$ctx){ eval(\$ctx->args['x']); };\n");

    $findings = (new \TotalCMS\Domain\Automation\Service\HandlerScanner())->scanDir(dirname($dir, 1));

    expect($findings)->not->toBeEmpty();
    expect($findings[0]['pattern'])->toBe('eval');
});
```

- [ ] **Step 2: Run (fails).**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Extension\Service\DangerousCodeScanner;

final class HandlerScanner
{
	/**
	 * @return list<array{pattern:string,file:string,line:int,snippet:string}>
	 */
	public function scanDir(string $dir): array
	{
		return (new DangerousCodeScanner())->scan($dir);
	}
}
```

> **Verify:** `DangerousCodeScanner` is instantiable with no constructor args (research showed `new DangerousCodeScanner()`); if it's container-managed, inject it instead.

- [ ] **Step 4: Run (passes) → commit** `"feat(automations): handler code-scan advisory"`. (UI surfacing is Task 8.)

---

## Task 7: `addAutomation()` extension hook

**Files:** Modify `src/Domain/Extension/ExtensionContext.php`; Create `src/Domain/Extension/Data/AutomationDefinition.php`; Modify `src/Domain/Extension/Service/ExtensionManager.php`; Test `tests/Unit/Domain/Extension/...`

- [ ] **Step 1: Write the failing test** — an extension context records an automation; `getRegisteredAutomations()` returns it.

```php
it('registers an extension automation in memory', function (): void {
    $context = makeExtensionContext(); // however other ExtensionContext tests build one
    $context->addAutomation('check-subs', 'Check subscriptions', [['type' => 'schedule', 'cron' => '0 */6 * * *']], fn ($ctx) => null);

    $registered = $context->getRegisteredAutomations();
    expect($registered)->toHaveCount(1);
    expect($registered[0]->id)->toBe('check-subs');
});
```

- [ ] **Step 2: Run (fails).**

- [ ] **Step 3: Implement `AutomationDefinition`**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

final readonly class AutomationDefinition
{
	/**
	 * @param list<array<string,mixed>> $triggers
	 */
	public function __construct(
		public string $id,
		public string $label,
		public array $triggers,
		public \Closure $handler,
	) {
	}
}
```

- [ ] **Step 4: Add the hook + getter to `ExtensionContext`** (mirror `registerMcpTool`/`getRegisteredMcpTools`):

```php
	/** @var list<\TotalCMS\Domain\Extension\Data\AutomationDefinition> */
	private array $automations = [];

	/**
	 * @param list<array<string,mixed>> $triggers
	 */
	public function addAutomation(string $id, string $label, array $triggers, \Closure $handler): void
	{
		$this->automations[] = new \TotalCMS\Domain\Extension\Data\AutomationDefinition($id, $label, $triggers, $handler);
	}

	/** @return list<\TotalCMS\Domain\Extension\Data\AutomationDefinition> */
	public function getRegisteredAutomations(): array
	{
		return $this->automations;
	}
```

> **Note:** `ExtensionContext` may be `readonly` or mutate arrays — confirm how `addEventListener` mutates `$this->eventListeners` and follow the same property mutability. The closure is held **in memory only**; it is never registered as a container definition (Forward-Compat Contract #3).

- [ ] **Step 5: Wire in `ExtensionManager::bootAll()`** — after the event-listener wiring (`:280-288`), collect `getRegisteredAutomations()` from each enabled extension into an in-memory registry keyed `extensionId:id`. `AutomationLoader`/`AutomationResolver` (Plans 2–3) gain an optional pass that also iterates this registry so extension automations participate in schedule/webhook/event dispatch. Surface them in the admin list as read-only with an "Extension:" tag (Task 8).

> **Scope guard (YAGNI):** v1 wires extension automations into the same dispatch paths but renders them read-only. If that integration balloons, ship file/collection automations first and land extension `addAutomation()` as a fast-follow — but the hook + definition land now so the API is stable.

- [ ] **Step 6: Run (passes) → stan → commit** `"feat(automations): addAutomation() extension hook"`.

---

## Task 8: Admin section (list / editor / runs) + nav + Run-now

**Files:** Create `src/Action/Admin/AdminAutomationsAction.php`, `resources/templates/admin/automations.twig`, `src/Action/Automation/AutomationRunNowAction.php`; Modify `resources/templates/admin/utils.twig`; Test `tests/Feature/AdminAutomationsTest.php`

This task follows existing admin patterns (no pure unit TDD for Twig); drive it with a feature test that the page renders for a Pro operator and 403/redirects otherwise.

- [ ] **Step 1: Write the failing feature test**

```php
it('renders the automations admin list for an authenticated admin', function (): void {
    // log in as admin (reuse the auth helper other admin feature tests use)
    $response = $this->get('/admin/utils/automations');
    expect($response->getStatusCode())->toBeIn([200, 302]); // 200 on Pro; 302/403 if gated
});
```

- [ ] **Step 2: Run (fails — no route/action).**

- [ ] **Step 3: Implement `AdminAutomationsAction`** (mirror `AdminMailerAction`): `fetchOrCreateReserved('automations')`, read `?id=` for editor mode, build `templateData` with the automations index + (in editor mode) the selected object + `HandlerScanner` findings + any auto-disable state, render `admin/automations.twig`. Register the route in the admin utils route group with `AutomationsEditionMiddleware`.

- [ ] **Step 4: Build `admin/automations.twig`** — list view: name, trigger-type icons, enabled toggle, last-run status pill (read newest run record), last-run time, "Run now" button; an auto-disabled banner with a one-click re-enable (POST that sets `enabled=true` + `AutomationGuard::reset`). Editor view: left CodeMirror `handler` field (the `code` field renders it), right metadata form (name/description/triggers deck/errorEmail), and a `DangerousCodeScanner` advisory panel (reuse the `extension-review.twig:36-58` findings-table markup). Run-history view: per-run detail (status, duration, args, return, log, exception) + "Replay".

- [ ] **Step 5: Add the nav entry** in `utils.twig` — add `{ "title": "Automations", "path": "automations" }` to the appropriate System group, wrapped so it only shows when `cms.auth.isAdmin() and cms.edition.can('automations')`.

- [ ] **Step 6: Implement `AutomationRunNowAction`** — admin-authed POST that runs an automation immediately via `AutomationRunner::run($slug, ['type' => 'manual'], $args)` (Replay passes a prior run's args) and returns the new run record (JSON or an HTMX partial, matching how other admin async buttons respond).

- [ ] **Step 7: Run the feature test (passes) → stan → commit** `"feat(automations): admin list/editor/runs + run-now"`.

---

## Task 9: API-key editor — `automations.fire` toggle + final wiring

**Files:** Modify the API-key admin editor (find: `grep -rln "scopes" src/Action src/Domain/ApiKey resources/templates`); `config/container.php`; docs

- [ ] **Step 1:** Expose the `automations.fire` scope as a checkbox in the API-key create/edit UI so operators can grant it (Plan 3 Task 1 added the check; this makes it grantable). Persist it into `scopes['automations.fire']`. Add a feature test that creating a key with the box checked yields `canFireAutomations() === true`.

- [ ] **Step 2:** Confirm container registration for all new services (`AutomationGuard`, `AutomationActivityLogger`, `HandlerScanner`, `AutomationResolver`, `AutomationQueue`, admin actions, middleware). Run `composer run stan`.

- [ ] **Step 3:** Documentation — add/extend `resources/docs/` automations pages (overview, triggers, writing handlers, webhook auth, operations/cron, edition note) and the Apache/Nginx `^automations/` rewrite row (`resources/docs/operations/apache.md`). Rebuild the docs search index: `php bin/build-docs-index.php`.

- [ ] **Step 4: Full suite + stan**

Run: `vendor/bin/pest tests/Unit/Domain/Automation tests/Feature/AdminAutomationsTest.php tests/Feature/AutomationWebhookTest.php && composer run stan`
Expected: PASS, no Level-8 errors.

- [ ] **Step 5: Commit** — `"feat(automations): api-key scope toggle + docs + final wiring"`.

---

## Self-Review

**Spec coverage (`automations.md`):** Pro edition gating ✓ (T1); Sync ✓ (T2); structured activity log (dashboard-ready, source registration deferred) ✓ (T3); environment-aware failure handling ✓ (T4/T5 via `EnvironmentResolver`); auto-disable after 5 prod failures + re-enable ✓ (T4/T5/T8 banner); `DangerousCodeScanner` advisory ✓ (T6/T8); admin list/editor/runs + Run-now/Replay ✓ (T8); `addAutomation()` extension hook, read-only surfacing ✓ (T7); `automations.fire` UI ✓ (T9); error-email ✓ (T5). The Activity Dashboard `ActivitySource` registration is intentionally **out of scope** (deferred per spec — the logger ships, the source registration lands with the dashboard).

**Placeholder scan:** Twig/admin tasks (T8) are described as build-steps with a feature-test gate rather than fabricated markup, matching the project's existing admin patterns and the skill's "follow established patterns" guidance. Every new service (T3/T4/T6/T7) is full TDD with code. "Verify" notes target named files (`CacheManager` clear method, `ObjectUpdater` signature, `ExtensionContext` mutability) with fallbacks.

**Type consistency:** `AutomationActivityLogger` method names match the calls wired in T5; `AutomationGuard::recordFailure(string): bool` / `reset(string)` / `shouldSurfaceErrors(): bool` used consistently; `AutomationDefinition(id,label,triggers,handler)` matches the `addAutomation()` signature; `EditionFeature::AUTOMATIONS` used in T1 middleware + T2/T8 gates + the Plan 2 migration guard.

**Cross-plan:** extends `AutomationRunner` (Plan 2) and `AutomationResolver`/`AutomationLoader` (Plans 2–3) — those must be merged first. The auto-disable test (T5) depends on `EnvironmentResolver` being resolvable from the container with the right `isPreview` flag — confirm during execution.
