# Automations: Webhook + Event Triggers Implementation Plan (Plan 3 of 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the remaining two trigger types to the automations subsystem: **webhook** (root-level `/automations/<slug>` routes with `apiKey` or `none` auth) and **event** (subscribe to the 17 core `EventDispatcher` events). Both fan into the same handler, with inputs delivered via `AutomationContext` (`$ctx->args` / `$ctx->request` / `$ctx->event`). Async runs use a dedicated automations queue drained by `automations:process`; webhook `sync: true` runs inline and returns the result.

**Architecture:** A new `AutomationQueue` writes pending-run files under `.system/automations/_queue/`; `automations:process` (from Plan 2) gains a drain pass. Webhook routes mount root-level like `/mcp`, gated by `AutomationWebhookAuthMiddleware` (API-key `automations.fire` permission) or rate-limited for `none`. Event triggers are served by a single `AutomationEventSubscriber` registered once per core event at boot; on dispatch it finds matching enabled automations, snapshots the payload, and enqueues a run.

**Tech Stack:** Slim 4 routing/middleware (PSR-15), the existing `ApiKeyAuthenticator` + `ApiKeyPermissionChecker`, `CacheManager` rate limiting, `EventDispatcher`, Pest.

**Depends on:** Plan 1, Plan 2. **Pairs with:** Plan 4 (admin/hardening).

## Schema decisions (locked, applied to `automation-trigger.json` in Plan 2)

- **Webhook endpoint is `POST /automations/<automation-id>`** — no per-trigger `slug`, no `methods`. One automation = one endpoint; a webhook is a trigger, not a queryable API (no GET). `AutomationResolver::webhook()` therefore matches on the **automation id**, not a slug.
- **No per-trigger timezone** (cron runs in `$config->timezone`) and **no per-trigger `priority`** (event runs are queued async). These fields were removed from the trigger schema.
- **`event` and `collection` are single selects.** To react to multiple events, add multiple event triggers — the multi-trigger model covers it; the resolver matches `event === trigger.event`.

---

## File Structure

- **Create** `src/Domain/Automation/Service/AutomationQueue.php` — enqueue/drain pending runs.
- **Modify** `src/CLI/Command/AutomationsProcessCommand.php` — add a queue-drain pass.
- **Create** `src/Domain/Automation/Service/AutomationResolver.php` — look up an automation by webhook slug / by event, read its trigger config.
- **Create** `src/Action/Automation/AutomationWebhookAction.php` — the webhook entrypoint (sync inline vs async 202).
- **Create** `src/Middleware/Automation/AutomationWebhookAuthMiddleware.php` — `apiKey` auth + `automations.fire`.
- **Create** `src/Middleware/Automation/AutomationRateLimitMiddleware.php` — per-IP for `none` auth.
- **Modify** `src/Domain/ApiKey/Service/ApiKeyPermissionChecker.php` — add `canFireAutomations()`.
- **Create** `config/routes/public/automations.php`; **Modify** `config/routes.php` — mount it.
- **Create** `src/Domain/Automation/Service/AutomationEventSubscriber.php` — event → enqueue.
- **Modify** the boot seam where core event listeners are registered — wire the subscriber.
- Tests under `tests/Unit/Domain/Automation/`, `tests/Integration/`, `tests/Feature/`.

---

## Conventions (verified)

- Root-level routes mount on `$app` outside groups, middleware via `->add()` (outermost = last added) — `config/routes/public/mcp.php:12-48`. Included from `config/routes.php:24-25`.
- Path params arrive as `$args['slug']` (Action 3rd arg) — `src/Action/Template/TemplateListAction.php:23-29`.
- API key auth: `ApiKeyAuthenticator::authenticate($request): ?ApiKeyData` (header `X-API-Key` or `Authorization: Bearer`) — `src/Domain/ApiKey/Service/ApiKeyAuthenticator.php:46-62`. Permissions are scopes on `ApiKeyData` — `src/Domain/ApiKey/Service/ApiKeyPermissionChecker.php:15-57`.
- Per-IP rate limit via `CacheManager`, bypass if API key present — `src/Middleware/Security/McpRateLimitMiddleware.php:41-85`.
- JSON response + status: `$renderer->json($response, $data, 202)` — `src/Renderer/JsonRenderer.php:31-48`.
- `EventDispatcher::listen($event, callable, $priority)`; listeners receive the **array** payload (`toArray()`), exceptions are caught — `src/Domain/Event/EventDispatcher.php:73-100`. Extension listeners wired via `registerAll()` in `ExtensionManager::bootAll()` (`:280-288`).

---

## Task 1: `automations.fire` API-key permission

**Files:** Modify `src/Domain/ApiKey/Service/ApiKeyPermissionChecker.php`; Test `tests/Unit/Domain/ApiKey/ApiKeyPermissionCheckerTest.php`

- [ ] **Step 1: Write the failing test**

```php
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyPermissionChecker;

it('grants automations.fire when the scope flag is set', function (): void {
    $checker = new ApiKeyPermissionChecker();

    $with = new ApiKeyData(['id' => 'k1', 'name' => 'k', 'key' => 'x', 'created' => 'now',
        'scopes' => ['methods' => ['POST'], 'paths' => [], 'automations.fire' => true]]);
    $without = new ApiKeyData(['id' => 'k2', 'name' => 'k', 'key' => 'y', 'created' => 'now',
        'scopes' => ['methods' => ['POST'], 'paths' => []]]);

    expect($checker->canFireAutomations($with))->toBeTrue();
    expect($checker->canFireAutomations($without))->toBeFalse();
});
```

- [ ] **Step 2: Run (fails — method missing).**

- [ ] **Step 3: Add the method** to `ApiKeyPermissionChecker`:

```php
	public function canFireAutomations(ApiKeyData $apiKey): bool
	{
		return ($apiKey->scopes['automations.fire'] ?? false) === true;
	}
```

- [ ] **Step 4: Run (passes) → stan.**

- [ ] **Step 5: Commit** — `"feat(automations): automations.fire api-key permission"`.

> **Plan 4 note:** the admin API-key editor must expose this scope as a toggle. Tracked in Plan 4's API-key UI touch-up; here we only add the check.

---

## Task 2: `AutomationQueue` (pending-run lane)

**Files:** Create `src/Domain/Automation/Service/AutomationQueue.php`; Test `tests/Unit/Domain/Automation/AutomationQueueTest.php`

Each pending run is a file `.system/automations/_queue/<runId>.json` carrying `{ runId, slug, trigger, args, event }`. (The PSR-7 request is **not** serialized — async handlers use `$ctx->args`; raw-request access requires `sync: true`.)

- [ ] **Step 1: Write the failing test** (in-memory filesystem fake as in Plan 1/2)

```php
it('enqueues a pending run and drains it exactly once', function (): void {
    $files = [];
    $queue = makeQueue($files); // fake StorageAdapterInterface

    $runId = $queue->enqueue('daily', ['type' => 'event', 'event' => 'object.created'], ['x' => 1], ['collection' => 'orders']);
    expect($runId)->not->toBe('');

    $drained = [];
    $queue->drain(function (array $job) use (&$drained): void {
        $drained[] = $job;
    });

    expect($drained)->toHaveCount(1);
    expect($drained[0]['slug'])->toBe('daily');
    expect($drained[0]['event'])->toBe(['collection' => 'orders']);

    // second drain is empty (file removed)
    $second = [];
    $queue->drain(function (array $job) use (&$second): void { $second[] = $job; });
    expect($second)->toBeEmpty();
});
```

- [ ] **Step 2: Run (fails).**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Storage\StorageAdapterInterface;

final class AutomationQueue
{
	private const DIR = '.system/automations/_queue';

	public function __construct(private readonly StorageAdapterInterface $filesystem)
	{
	}

	/**
	 * @param array<string,mixed> $trigger
	 * @param array<string,mixed> $args
	 * @param array<string,mixed>|null $event
	 */
	public function enqueue(string $slug, array $trigger, array $args, ?array $event = null): string
	{
		$runId = bin2hex(random_bytes(16));
		$job   = ['runId' => $runId, 'slug' => $slug, 'trigger' => $trigger, 'args' => $args, 'event' => $event];
		$this->filesystem->write(self::DIR . '/' . $runId . '.json', (string)json_encode($job, JSON_UNESCAPED_SLASHES));

		return $runId;
	}

	/**
	 * @param callable(array<string,mixed>):void $handle
	 */
	public function drain(callable $handle): void
	{
		foreach ($this->pendingFiles() as $path) {
			$job = json_decode($this->filesystem->read($path), true);
			$this->filesystem->delete($path); // delete before running → no infinite retry loop on a poison job
			if (is_array($job)) {
				$handle($job);
			}
		}
	}

	/** @return list<string> */
	private function pendingFiles(): array
	{
		// Use whatever directory listing StorageAdapterInterface exposes; see
		// ObjectRepository for directory ops. Return relative paths ending in .json.
		// Fallback: glob on Config::datadir . '/' . self::DIR if listing is awkward.
		return [];
	}
}
```

> **Verify:** the directory-listing API (same open item as Plan 2 `AutomationRunner::prune`). Resolve once and reuse. Delete-before-run is deliberate: a handler that throws is captured by `AutomationRunner` as a failed `RunRecord`, not re-queued.

- [ ] **Step 4–5:** Run (passes) → stan → commit `"feat(automations): pending-run queue"`.

---

## Task 3: Drain pass in `automations:process`

**Files:** Modify `src/CLI/Command/AutomationsProcessCommand.php`; Test extends `tests/Integration/AutomationsProcessCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('drains a queued automation run on the next tick', function (): void {
    app()->get(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('automations');
    app()->get(\TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('automations', [
        'id' => 'queued', 'name' => 'Q', 'enabled' => true,
        'triggers' => ['t0' => ['id' => 't0', 'type' => 'webhook', 'auth' => 'none']],
        'handler' => "<?php\n\nreturn function (\$ctx) { return ['args' => \$ctx->args]; };\n",
    ]);
    app()->get(\TotalCMS\Domain\Automation\Service\AutomationQueue::class)
        ->enqueue('queued', ['type' => 'webhook'], ['hello' => 'world']);

    $command = new \TotalCMS\CLI\Command\AutomationsProcessCommand(testTotalCMS());
    (new \Symfony\Component\Console\Tester\CommandTester($command))->execute([]);

    $runs = glob(cmsDataDir() . '.system/automations/queued/runs/*.json');
    expect($runs)->not->toBeEmpty();
    $record = json_decode(file_get_contents($runs[0]), true);
    expect($record['return']['args'])->toBe(['hello' => 'world']);
});
```

- [ ] **Step 2: Run (fails — queue not drained).**

- [ ] **Step 3: Add the drain pass** to `execute()` (before the schedule loop):

```php
		$queue = $this->totalcms->container->get(\TotalCMS\Domain\Automation\Service\AutomationQueue::class);
		$queue->drain(function (array $job) use ($runner): void {
			$runner->run(
				(string)$job['slug'],
				is_array($job['trigger'] ?? null) ? $job['trigger'] : [],
				is_array($job['args'] ?? null) ? $job['args'] : [],
				null,
				is_array($job['event'] ?? null) ? $job['event'] : null,
			);
		});
```

- [ ] **Step 4–5:** Run (passes) → stan → commit `"feat(automations): drain queued runs in automations:process"`.

---

## Task 4: `AutomationResolver` (lookup by webhook slug / event)

**Files:** Create `src/Domain/Automation/Service/AutomationResolver.php`; Test `tests/Integration/AutomationResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('finds the webhook trigger for a slug and lists event triggers for an event', function (): void {
    app()->get(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('automations');
    app()->get(\TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('automations', [
        'id' => 'multi', 'name' => 'Multi', 'enabled' => true,
        'triggers' => [
            't0' => ['id' => 't0', 'type' => 'webhook', 'slug' => 'multi', 'auth' => 'apiKey', 'sync' => false],
            't1' => ['id' => 't1', 'type' => 'event', 'event' => 'object.created', 'collection' => 'orders'],
        ],
        'handler' => "<?php\n\nreturn function (\$ctx) { return true; };\n",
    ]);

    $resolver = app()->get(\TotalCMS\Domain\Automation\Service\AutomationResolver::class);

    $webhook = $resolver->webhook('multi');
    expect($webhook)->not->toBeNull();
    expect($webhook['slug'])->toBe('multi');
    expect($webhook['trigger']['auth'])->toBe('apiKey');

    $listeners = $resolver->eventTriggers('object.created', 'orders');
    expect($listeners)->toHaveCount(1);
    expect($listeners[0]['slug'])->toBe('multi');
});
```

- [ ] **Step 2: Run (fails).**

- [ ] **Step 3: Implement** (uses `AutomationLoader::enabled()` from Plan 2)

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

final readonly class AutomationResolver
{
	public function __construct(private AutomationLoader $loader)
	{
	}

	/**
	 * The endpoint path segment IS the automation id (no per-trigger slug).
	 *
	 * @return array{slug:string, trigger:array<string,mixed>}|null
	 */
	public function webhook(string $automationId): ?array
	{
		foreach ($this->loader->enabled() as $automation) {
			if ($automation->id !== $automationId) {
				continue;
			}
			foreach ($this->triggers($automation) as $trigger) {
				if (($trigger['type'] ?? '') === 'webhook') {
					return ['slug' => $automation->id, 'trigger' => $trigger];
				}
			}
		}

		return null;
	}

	/**
	 * @return list<array{slug:string, trigger:array<string,mixed>}>
	 */
	public function eventTriggers(string $event, string $collection): array
	{
		$out = [];

		foreach ($this->loader->enabled() as $automation) {
			foreach ($this->triggers($automation) as $trigger) {
				if (($trigger['type'] ?? '') !== 'event' || ($trigger['event'] ?? '') !== $event) {
					continue;
				}
				$filter = (string)($trigger['collection'] ?? '');
				if ($filter !== '' && $filter !== $collection) {
					continue;
				}
				$out[] = ['slug' => $automation->id, 'trigger' => $trigger];
			}
		}

		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function triggers(\TotalCMS\Domain\Object\Data\ObjectData $automation): array
	{
		$triggers = $automation->properties->get('triggers');
		$rows     = is_object($triggers) && method_exists($triggers, 'transform') ? $triggers->transform() : $triggers;

		return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
	}
}
```

> **Verify:** deck `transform()` row shape (same open item as Plan 2 Task 8 — resolve consistently). `webhook` slug defaults to the automation id.

- [ ] **Step 4–5:** Run (passes) → stan → commit `"feat(automations): AutomationResolver"`.

---

## Task 5: `AutomationWebhookAuthMiddleware` + `AutomationRateLimitMiddleware`

**Files:** Create both under `src/Middleware/Automation/`; Tests `tests/Unit/Middleware/...`

- [ ] **Step 1: Write the failing auth-middleware test**

```php
it('rejects a webhook with no/invalid automations.fire key as 401', function (): void {
    // Mirror an existing middleware test: build a ServerRequest with no X-API-Key,
    // pass a handler that should NOT be reached, assert 401.
})->todo(); // replace ->todo() with the concrete request/handler harness used by other middleware tests
```

> **Step note:** find an existing PSR-15 middleware test (e.g. for `DualAuthMiddleware` or `CSRFProtectionMiddleware`) and copy its request/handler construction. The assertions: missing/invalid key → 401; valid key without `automations.fire` → 403; valid key with the scope → handler reached.

- [ ] **Step 2: Implement `AutomationWebhookAuthMiddleware`**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Automation;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\ApiKey\Service\ApiKeyAuthenticator;
use TotalCMS\Domain\ApiKey\Service\ApiKeyPermissionChecker;
use TotalCMS\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseFactoryInterface;

final readonly class AutomationWebhookAuthMiddleware implements MiddlewareInterface
{
	public function __construct(
		private ApiKeyAuthenticator $authenticator,
		private ApiKeyPermissionChecker $permissions,
		private JsonRenderer $renderer,
		private ResponseFactoryInterface $responseFactory,
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		$apiKey = $this->authenticator->authenticate($request);

		if (!$apiKey instanceof ApiKeyData) {
			return $this->renderer->json($this->responseFactory->createResponse(401), ['error' => ['message' => 'Invalid or missing API key.']]);
		}
		if (!$this->permissions->canFireAutomations($apiKey)) {
			return $this->renderer->json($this->responseFactory->createResponse(403), ['error' => ['message' => 'API key lacks the automations.fire permission.']]);
		}

		return $handler->handle($request->withAttribute('apiKey', $apiKey));
	}
}
```

- [ ] **Step 3: Implement `AutomationRateLimitMiddleware`** — copy `McpRateLimitMiddleware` almost verbatim, changing the cache prefix to `auto_rl_` and the limit to `(int)($this->config->automations['webhookPublicIpPerMinute'] ?? 60)`. Keep the API-key bypass.

- [ ] **Step 4: Run (passes) → stan.**

- [ ] **Step 5: Commit** — `"feat(automations): webhook auth + rate-limit middleware"`.

---

## Task 6: `AutomationWebhookAction` + routes

**Files:** Create `src/Action/Automation/AutomationWebhookAction.php`, `config/routes/public/automations.php`; Modify `config/routes.php`; Test `tests/Feature/AutomationWebhookTest.php`

- [ ] **Step 1: Write the failing feature test** — POST `/automations/<slug>` with `auth:none` returns 202 + a `runId`, and the run drains on the next tick. (Use the app harness like other Feature tests; `postJson`.)

```php
it('accepts a none-auth webhook and queues a run (202)', function (): void {
    app()->get(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('automations');
    app()->get(\TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('automations', [
        'id' => 'hook', 'name' => 'Hook', 'enabled' => true,
        'triggers' => ['t0' => ['id' => 't0', 'type' => 'webhook', 'auth' => 'none', 'sync' => false]],
        'handler' => "<?php\n\nreturn function (\$ctx) { return \$ctx->args; };\n",
    ]);

    $response = $this->postJson('/automations/hook', ['order_id' => 42]);
    expect($response->getStatusCode())->toBe(202);
    expect(json_decode((string)$response->getBody(), true))->toHaveKey('runId');
});
```

- [ ] **Step 2: Run (fails — 404, no route).**

- [ ] **Step 3: Implement the Action**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Action\Automation;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Automation\Service\AutomationQueue;
use TotalCMS\Domain\Automation\Service\AutomationResolver;
use TotalCMS\Domain\Automation\Service\AutomationRunner;
use TotalCMS\Renderer\JsonRenderer;
use Slim\Exception\HttpNotFoundException;

final readonly class AutomationWebhookAction
{
	public function __construct(
		private AutomationResolver $resolver,
		private AutomationQueue $queue,
		private AutomationRunner $runner,
		private JsonRenderer $renderer,
	) {
	}

	/** @param array<string,mixed> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id      = (string)($args['id'] ?? '');
		$webhook = $this->resolver->webhook($id);

		if ($webhook === null) {
			throw new HttpNotFoundException($request);
		}

		$trigger = $webhook['trigger'];
		$inputs  = array_merge(
			$request->getQueryParams(),
			is_array($request->getParsedBody()) ? $request->getParsedBody() : [],
		);

		// Sync: run inline and return the record. Async: enqueue, 202.
		if (($trigger['sync'] ?? false) === true) {
			$record = $this->runner->run($webhook['slug'], $trigger, $inputs, $request);

			return $this->renderer->json($response, [
				'runId'     => $record->runId,
				'status'    => $record->status,
				'return'    => $record->return,
				'exception' => $record->exception,
			], $record->status === 'success' ? 200 : 500);
		}

		$runId = $this->queue->enqueue($webhook['slug'], $trigger, $inputs);

		return $this->renderer->json($response, ['runId' => $runId, 'status' => 'queued'], 202);
	}
}
```

- [ ] **Step 4: Create the route file** `config/routes/public/automations.php` (model on `mcp.php`; choose auth middleware per-trigger is not possible at mount time, so mount BOTH middlewares and let each short-circuit appropriately — simpler: mount the action with a thin dispatcher middleware that picks auth vs rate-limit based on the resolved trigger). The pragmatic v1:

```php
<?php

declare(strict_types=1);

use Slim\Interfaces\RouteCollectorProxyInterface;
use TotalCMS\Action\Automation\AutomationWebhookAction;
use TotalCMS\Middleware\Automation\AutomationTriggerAuthMiddleware;

return function (RouteCollectorProxyInterface $app): void {
	$prefix = '/automations'; // Config override (automations.urlPrefix) applied at a higher level if set

	$app->post($prefix . '/{id}', AutomationWebhookAction::class)
		->add(AutomationTriggerAuthMiddleware::class) // dispatches to apiKey-auth or rate-limit per resolved trigger
		->setName('automation-webhook');
};
```

> **Decision:** introduce a small `AutomationTriggerAuthMiddleware` that resolves the trigger (via `AutomationResolver`) and delegates to the apiKey check (Task 5 middleware logic) when `auth: 'apiKey'`, or the rate-limiter when `auth: 'none'`. This keeps per-trigger auth without two routes. Build it by composing the two Task-5 middlewares. Add a test for both branches.

- [ ] **Step 5: Mount in `config/routes.php`** alongside the mcp/oauth includes:

```php
(require __DIR__ . '/routes/public/automations.php')($app);
```

- [ ] **Step 6: Run (passes) → stan → commit** `"feat(automations): webhook action + routes"`.

---

## Task 7: `AutomationEventSubscriber` + boot wiring

**Files:** Create `src/Domain/Automation/Service/AutomationEventSubscriber.php`; Modify the core-listener boot seam; Test `tests/Integration/AutomationEventTriggerTest.php`

One subscriber method per event family; registered once per core event at boot. On dispatch it finds matching enabled automations, **snapshots** the payload (so `object.deleted` data + `*.updated` `previous` survive), and enqueues an async run.

- [ ] **Step 1: Write the failing integration test**

```php
it('enqueues an automation run when a matching object.created event fires', function (): void {
    // create the automations collection + a target collection 'orders'
    app()->get(\TotalCMS\Domain\Collection\Service\CollectionFetcher::class)->fetchOrCreateReserved('automations');
    createCollectionWithSchema('orders', /* minimal schema with id + total */);

    app()->get(\TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('automations', [
        'id' => 'on-order', 'name' => 'On order', 'enabled' => true,
        'triggers' => ['t0' => ['id' => 't0', 'type' => 'event', 'event' => 'object.created', 'collection' => 'orders']],
        'handler' => "<?php\n\nreturn function (\$ctx) { return \$ctx->event['collection']; };\n",
    ]);

    // Saving an order dispatches object.created → subscriber enqueues a run.
    app()->get(\TotalCMS\Domain\Object\Service\ObjectSaver::class)->saveObject('orders', ['id' => 'o1', 'total' => 10]);

    $pending = glob(cmsDataDir() . '.system/automations/_queue/*.json');
    expect($pending)->not->toBeEmpty();
    $job = json_decode(file_get_contents($pending[0]), true);
    expect($job['slug'])->toBe('on-order');
    expect($job['event']['collection'])->toBe('orders');
});
```

- [ ] **Step 2: Run (fails — no subscriber wired).**

- [ ] **Step 3: Implement the subscriber**

```php
<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Object\Data\ObjectData;

final readonly class AutomationEventSubscriber
{
	public function __construct(
		private AutomationResolver $resolver,
		private AutomationQueue $queue,
	) {
	}

	/**
	 * Core events automations may subscribe to — the canonical list lives on
	 * CoreEvent (src/Domain/Event/Data/CoreEvent.php), the single source of truth.
	 *
	 * @return list<string>
	 */
	public static function events(): array
	{
		return \TotalCMS\Domain\Event\Data\CoreEvent::ALL;
	}

	/**
	 * @param array<string,mixed> $payload the dispatcher's array payload
	 */
	public function handle(string $event, array $payload): void
	{
		$collection = (string)($payload['collection'] ?? '');
		$snapshot   = $this->snapshot($payload);

		foreach ($this->resolver->eventTriggers($event, $collection) as $match) {
			$this->queue->enqueue($match['slug'], $match['trigger'], [], $snapshot);
		}
	}

	/**
	 * Snapshot the payload into plain arrays so it survives serialization to the
	 * queue (ObjectData → toArray()).
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function snapshot(array $payload): array
	{
		$out = [];
		foreach ($payload as $key => $value) {
			$out[$key] = $value instanceof ObjectData ? $value->toArray() : $value;
		}

		return $out;
	}
}
```

- [ ] **Step 4: Wire at boot**

Register one delegating listener per event. Find where core listeners (`IndexBuildListener`, `CacheInvalidationListener`) are registered into the `EventDispatcher` (grep: `grep -rn "->listen(\|registerAll\|IndexBuildListener" src config`). At that seam, add:

```php
$subscriber = $container->get(\TotalCMS\Domain\Automation\Service\AutomationEventSubscriber::class);
foreach (\TotalCMS\Domain\Automation\Service\AutomationEventSubscriber::events() as $event) {
	$dispatcher->listen($event, static fn (array $payload) => $subscriber->handle($event, $payload), 100); // late priority
}
```

> **Verify:** the exact registration site + whether it runs for CLI saves too (the test saves an order via `ObjectSaver`, which dispatches regardless of HTTP/CLI). Priority `100` = run after core index/cache listeners (lower numbers run first per `EventDispatcher::dispatch` sort).

- [ ] **Step 5: Run (passes) → stan → commit** `"feat(automations): event-trigger subscriber + boot wiring"`.

---

## Task 8: Trigger-input documentation + end-to-end test

**Files:** Test `tests/Integration/AutomationTriggerInputsTest.php`; Docs `resources/docs/...`

- [ ] **Step 1: Write an end-to-end test** proving inputs reach the handler for all three triggers: a webhook (sync) echoing `$ctx->args` + a request header via `$ctx->request`, and an event handler reading `$ctx->event['object']` after a drain. (Compose the harnesses from Tasks 6–7.)

- [ ] **Step 2: Run (passes after Tasks 1–7).**

- [ ] **Step 3: Document** the trigger-input contract in the automations docs (mirror the spec's "Trigger inputs" section): `$ctx->args` (webhook query+body / manual), `$ctx->request` (webhook sync only — async webhooks don't carry the request), `$ctx->event` (event payload incl. `object` snapshot).

- [ ] **Step 4: Commit** — `"test+docs(automations): trigger inputs end-to-end"`.

---

## Self-Review

**Spec coverage:** webhook routing root-level ✓ (T6); `apiKey` + `automations.fire` ✓ (T1/T5); `none` rate-limited ✓ (T5); per-trigger `sync` (webhook) ✓ (T6); event subscription at boot ✓ (T7); event payload snapshot-at-enqueue ✓ (T7 `snapshot()`); async runs via dedicated lane drained by `automations:process` ✓ (T2/T3); inputs on `AutomationContext` ✓ (T8). Retry/back-off and environment-aware notification → Plan 4.

**Placeholder scan:** The middleware test (T5 Step 1) and the order-schema (T7) reference existing harnesses by name with explicit "copy from X" instructions rather than inventing them — the same disciplined verify-step style as Plans 1–2. The one design decision flagged inline (`AutomationTriggerAuthMiddleware`, T6 Step 4) is specified, not left open.

**Type consistency:** `AutomationQueue::enqueue(string,array,array,?array): string` / `drain(callable): void`; `AutomationResolver::webhook(string): ?array` / `eventTriggers(string,string): list`; subscriber `handle(string,array): void`. Used consistently across T2–T7 and into the Plan 2 `AutomationRunner::run(...)` signature.

**Open items to resolve once (shared with Plan 2):** directory-listing API (queue/prune) and deck-row `transform()` shape. Resolve in the first task that hits them and reuse.
