# T3 Automations — 3.5 Plan

**Status:** Planning. Final big feature targeted for 3.5. _Revised 2026-05-31 — storage moved from flat PHP files to a reserved `automations` collection with an externalized handler field; triggers are now plural; execution moved to a dedicated `automations:process` runner; reconciled with beta 12/13 primitives._

User-authored, server-side automations driven by three trigger types — **schedules** (cron), **webhooks** (HTTP), and **events** (T3 EventDispatcher). A single automation can declare **any combination** of triggers, all fanning into one handler. Each automation is one object in the reserved `automations` collection; its handler is real PHP stored in an externalized `code` field (a file on disk), with a pre-injected service context and no bootstrap boilerplate. Async-by-default execution on a **dedicated** automations runner — never blocked by the import job queue. Admin UI for listing, editing, monitoring, and ad-hoc test runs.

## Goal

Give T3 operators a centralized place to author the kind of script that today lives in `bin/processX.php` files alongside a project — eliminate the bootstrap, give them a managed runner, monitor + log + retry every run.

## Motivating Customer Scripts

Three real scripts from production customers, all of which today live as hand-rolled PHP files outside T3 and share an identical bootstrap pattern:

| Script | Trigger today | Lines of boilerplate before business logic |
|---|---|---|
| `processMonthlyInvestments.php` | cron daily | ~20 (autoload, `new TotalCMS()`, `clearCache()`, fetch services from container, set up logger) |
| `processAnnualInvestments.php` | cron yearly | ~20 (same) |
| `processPortfolioProfits.php` | webhook (`/webhook/...`) | ~30 (bootstrap + manual validation + manual JSON-response helpers) |

Every script wraps its main logic in a top-level try/catch that ends with `$totalcms->mailer()->sendEmail('cron-job-errors', ...)`. Every script independently calls `$totalcms->createLogger(...)` with the same arguments. **The boilerplate is the feature gap.** Because the handler stays real, file-backed PHP (see Storage), migrating one of these scripts is: paste the body in, delete the bootstrap.

## Forward-Compatibility Contract

These decisions are load-bearing for any future automation work (visual builder, distributed runners, additional trigger types). 3.5 must respect them, and breaking them in 3.6+ requires a migration plan.

1. **One object per automation.** An automation is a single object in the reserved `automations` collection (`id` = slug). No flat per-automation PHP file as the unit of record — the collection object is the unit, and it carries metadata, the `triggers` deck, and the externalized handler.
2. **Handler is real PHP in an externalized `code` field.** The `handler` property is a `code` field (`mode: php`, `external: true`) whose value lives on disk at `tcms-data/automations/<slug>/handler/handler.php` (the standard T3 file-save layout `<collection-dir>/<slug>/<property>/<property>.<ext>`), not inline in the object JSON. The runner `require`s that file.
3. **The handler is never a container definition.** It is loaded via `require` at request/tick time and invoked directly. It is **never** registered as a PHP-DI definition, and the extension `addAutomation()` API stores the closure in memory, not via the container. This is what keeps automations clear of the compiled-container closure compiler (see Beta 12/13 Reconciliation). `AutomationContext` services resolve from the container; the handler closure does not.
4. **Triggers are plural and declared in-object.** A `triggers` deck on the object — type, params, per-trigger sync flag, etc. One automation may declare any mix of schedule/webhook/event triggers, all firing the same handler.
5. **Run records live in `tcms-data/.system/automations/<slug>/runs/`.** Files, not collection objects. High-volume, ephemeral, pruned on each new run.
6. **AutomationContext is the only API surface for the handler.** Handlers receive `AutomationContext`, never the raw container or `$_GET`/`$_POST` super-globals. Service additions go on the context. Per-trigger payload data is exposed via context fields (e.g. `$ctx->event` only set for event triggers).
7. **URL prefix is configurable.** Default `/automations/<slug>`, override via `$config->automations['urlPrefix']`. Stacks installs need an additional rewrite parallel to MCP / OAuth.
8. **API auth permission is single-scope.** `automations.fire` — one permission row, gates every webhook automation that uses `auth: 'apiKey'`. Per-automation scoping deferred to v1.x if customer demand emerges.
9. **Pro edition only.** Standard customers keep the internal JobQueue for T3-driven work (imports, bulk mailers) but cannot author automations.

## Storage: the `automations` collection + externalized handler

Automations are a **reserved collection**, like `mailer` and `mcp-prompt` — they live purely in the CMS. There is no git overlay or two-tier resolution; the handler is still a real file on disk, so an operator who keeps `tcms-data/` in git gets versioning for free, but git is a non-feature of the system itself (exactly like Mailer).

### Why a collection (and not flat files)

- **Sync.** Sync runs through `JumpStartImporter`. Because the automation is a collection object, it drops straight into the Sync Manager — author on staging, push to production, config **and** handler in one payload (see Sync).
- **Admin-managed operational knobs** — toggle `enabled`, edit a cron, change an error email, "Run now" — without a deploy.
- **Consistency** — events, indexing, list UI, all the standard collection machinery apply.

### Why the handler is externalized (and not an inline JSON string)

Storing 50–150 lines of imperative PHP as an escaped string inside the object JSON would be a bad authoring surface: no IDE, ugly diffs, stack-trace line offsets, an un-hand-editable object file. The **`external: true`** option on the `code` field persists the field's value to a real file on disk instead, so:

- The handler is a real `.php` file — IDE-editable, clean diffs, copy-paste migration, no escaped strings, no compile-to-cache machinery.
- The runner `require`s the file by path. Execution is identical to the original flat-file plan; only the **authoring/storage** moved into a collection field.
- To the rest of the system (admin form, Twig, JumpStart, Sync) the field looks like a normal string property — the externalization is an internal storage detail of the field. The field's get/set transparently read/write the file (lazy-hydrated: the file content is only loaded for admin edit and JumpStart export, not on every index/list read; the runner never reads the property — it `require`s the file directly).

`external: true` is a **general field capability**, not automations-specific. `mcp-prompt` (Twig body) and `mailer` (`bodyHtml`/`bodyText`) store code as escaped JSON strings today and could adopt it later for the same clean files. Automations is the forcing function; the primitive has a life beyond it.

### File layout

```
tcms-data/automations/<slug>/handler/handler.php      # the externalized handler (mode: php → .php)
tcms-data/automations/<slug>.json                     # the collection object (metadata + triggers, handler value externalized)
tcms-data/.system/automations/<slug>/runs/<runId>.json
tcms-data/.system/automations/<slug>.state.json       # per-trigger last-fire, failure counters
```

Lifecycle (delete object → remove the file dir; change `id` → move it) reuses the existing image/file-field machinery.

### The handler file

`tcms-data/automations/<slug>/handler/handler.php` returns the closure:

```php
<?php

return function (AutomationContext $ctx) {
    $usersIndex = $ctx->indexReader->fetchIndex('users');

    $usersIndex->objects->each(function ($user) use ($ctx) {
        // ... business logic
    });

    return ['created' => 42, 'skipped' => 3];
};
```

The handler's return value is captured in the run-history record so the admin UI can show "this automation processed 42 records."

### AutomationContext

Pre-injected services + per-trigger payload data:

| Property | Always set? | Purpose |
|---|---|---|
| `$ctx->logger` | yes | PSR-3 logger, pre-wired to write to the automation's log file. No setup. |
| `$ctx->indexReader` | yes | Same `IndexReader` used by every customer script today. |
| `$ctx->objectFetcher`, `$ctx->objectSaver`, `$ctx->objectUpdater`, `$ctx->objectRemover` | yes | Object CRUD services. |
| `$ctx->deckItemSaver`, `$ctx->indexBuilder` | yes | Deck and index services frequently needed in real scripts. |
| `$ctx->mailer` | yes | Existing `EmailService`. |
| `$ctx->config` | yes | Runtime `Config`. |
| `$ctx->trigger` | yes | The trigger row that fired this run (type + its params) so a handler can branch if it cares. Most don't. |
| `$ctx->args` | yes | Caller-supplied inputs as `array<string,mixed>`: merged query params + parsed body for webhooks; "Run now" / CLI args for manual runs. Empty for schedule triggers. |
| `$ctx->request` | webhook only | PSR-7 `ServerRequestInterface` — headers, raw body, query — for anything beyond `$ctx->args`. |
| `$ctx->event` | event only | The dispatched event payload **array**, exactly as core listeners receive it (the dispatcher passes `EventPayload::toArray()`, not the typed object). For `object.*` events: `['collection' => string, 'id' => string, 'object' => ObjectData, 'previous' => ?ObjectData]` (`previous` only on `*.updated`). Other events carry their own shape (e.g. `user.login` → `['user' => string]`). |
| `$ctx->container` | yes | Escape hatch — raw DI container access for anything else. |

### Trigger inputs (data into the handler)

Every trigger type can hand data to the handler — the handler reads it off `AutomationContext`. No new event plumbing is needed; automations expose what the `EventDispatcher` and PSR-7 request already carry.

**Webhook** — query params and the parsed JSON/form body are merged into `$ctx->args`; the raw request stays on `$ctx->request`:

```php
return function (AutomationContext $ctx) {
    $orderId   = $ctx->args['order_id'] ?? null;            // from ?order_id= or JSON body
    $signature = $ctx->request->getHeaderLine('X-Signature');
    // ...
};
```

**Event** — the dispatched payload is on `$ctx->event`, exactly as core listeners receive it. For object events it carries the **full object** (an `ObjectData`), so an `object.created` handler gets the created object's data without re-fetching:

```php
return function (AutomationContext $ctx) {
    // triggers: [{ type: 'event', event: 'object.created', collection: 'orders' }]
    $collection = $ctx->event['collection'];   // 'orders'
    $order      = $ctx->event['object'];       // ObjectData
    $fields     = $order->toArray();           // ['id' => ..., 'total' => ..., ...]

    if (($fields['total'] ?? 0) > 1000) {
        $ctx->mailer->sendEmail('big-order-alert', /* ... */);
    }
};
```

On `*.updated` events, `$ctx->event['previous']` carries the pre-save `ObjectData` so a handler can diff old vs new. `object.deleted` carries `collection` + `id` only (the object is already gone). The handler can always inspect `$ctx->trigger` to know which trigger fired when an automation has more than one.

## Triggers (plural)

`triggers` is a deck on the automation object (same keyed-deck pattern as `mcp-tool` tools / `mcp-prompt-arg`). Each row is a `type` plus type-specific fields. One automation → one handler → **N triggers**, any mix:

```jsonc
"triggers": [
  { "id": "t0", "type": "schedule", "cron": "0 1 * * *" },
  { "id": "t1", "type": "webhook",  "auth": "apiKey", "sync": false },
  { "id": "t2", "type": "event",    "event": "object.created", "collection": "orders" }
]
```

The trigger editor uses T3's schema **`visibility`** mechanism (`settings.visibility` → `{watch: "type", value, operator}`, which works inside deck dialogs) so each row shows only the fields for its `type`: `cron` for schedule; `auth` + `sync` for webhook; `event` + `collection` for event.

**Schedule:**

- `cron` — standard 5-field expression, parsed by `dragonmantank/cron-expression`, **evaluated in the site timezone** (`$config->timezone`, Settings → General). No per-trigger timezone — one site, one clock. (Add per-trigger tz in v1.x only if a customer needs it.)
- Last-fire timestamp stored **per-trigger** in `tcms-data/.system/automations/<slug>.state.json` keyed by the trigger `id`, so two schedules on one automation never clobber each other.
- Due-detection happens in the dedicated `automations:process` tick (see Execution).

**Webhook:**

- **Endpoint:** `POST /automations/<automation-id>` — the automation's own id, no per-trigger slug (one automation = one endpoint; a second webhook trigger would be a duplicate). **POST only** — a webhook is a trigger, not a queryable API; supporting GET would imply a response contract and turn this into an API builder, which is out of scope.
- `auth` — `'apiKey'` | `'none'`. `apiKey` reuses the existing REST-API API-key system (`X-API-Key` header or `?key=` query param) and verifies the key has the new `automations.fire` permission; returns 401 on failure. Admin-session auth is not accepted (webhooks come from external services, not browsers). `none` is public, rate-limited per IP via the existing rate limiter.
- `sync` — optional per-trigger flag (see Execution). Default `false`.
- **Inputs:** query + parsed body arrive merged as `$ctx->args`; raw request as `$ctx->request` (see Trigger inputs).

**Event:**

- `event` — a **select** of the core events (single event per trigger; to react to several events, add several event triggers — the multi-trigger model already covers it).
- `collection` — a **select** of collection ids; optional filter. Blank = all collections.
- Wired into the existing `EventDispatcher` at boot. T3 events are post-action, so automations are reactive, not transformative. Can subscribe to `import.created` / `import.updated` for importer reactions. (No per-trigger `priority` — event runs are queued async, so listener priority is meaningless.)
- **Inputs:** the full event payload — including the live `ObjectData` on `object.*` events — arrives as `$ctx->event` (see Trigger inputs).

## Execution

**A dedicated `tcms automations:process` runner — separate from `tcms jobs:process`.**

Automations do **not** ride the import job queue. A large import churning through `jobs:process` must not delay a time-sensitive scheduled automation, and the two coupling points (due-detection and execution) are both kept out of the import FIFO:

- `automations:process` runs on its **own cron line** (`* * * * *`), in parallel with `jobs:process`.
- It performs the **find-due-schedules** pass and **executes** due/queued automation work in an automations-only lane (a dedicated queue dir / pending-run records). Imports and automations never share a queue.
- **Single-flight per automation** — the tick will not start an automation whose previous run is still in flight (scoped concurrent-run guard; full distributed locking stays deferred).

Sync vs async:

- **Async (default).** Schedule and event automations, and webhook automations with `sync: false`, run on the next `automations:process` tick. A webhook returns `202 Accepted { runId, status: 'queued' }` immediately.
- **Sync (opt-in, webhook only).** A webhook trigger with `sync: true` runs the handler inside the request and blocks; response body is `{ runId, status: 'success'|'failed', return: ..., exception: ... }`. For response-shaping callers. (Schedule/event are always async.)
- **Event execution.** An event trigger enqueues an automation run on dispatch; it does not run inside the originating request (keeps T3 actions fast and prevents a slow automation from degrading every matching write). The originating action is post-event, so it is never aborted by an automation. The event payload is **snapshotted at enqueue** (serialized into the queued run — `ObjectData` via `toArray()`), so the handler sees the object's state *at event time* and is not re-fetched at tick time. This is what lets `object.deleted` data and `*.updated` `previous` state survive to the next tick.
- **Retry policy.** Async paths get 3 attempts with exponential backoff. Sync webhooks surface failure to the caller immediately (500). Schedules don't retry — the next interval fires again.
- Each run gets a `runId` (uuid) tracked enqueue → execution → record persistence.

Webhook flow (async):

```
POST /automations/<slug>
  → AutomationWebhookAuthMiddleware (verifies X-API-Key has automations.fire)
  → AutomationWebhookAction enqueues an automation run
  → 202 Accepted { runId, status: 'queued' }
[next automations:process tick]
  → AutomationRunner runs the handler in try/catch
  → persists run record (status, duration, return value, exception)
  → on exception: environment-aware handling (see Reconciliation)
```

## Run History

`tcms-data/.system/automations/<slug>/runs/<runId>.json`:

```json
{
  "runId":      "01J9X...",
  "automation": "process-monthly-investments",
  "trigger":    {"type": "schedule", "firedAt": "2026-05-29T01:00:00Z"},
  "status":     "success",
  "startedAt":  "2026-05-29T01:00:01Z",
  "finishedAt": "2026-05-29T01:00:42Z",
  "durationMs": 41280,
  "return":     {"created": 42, "skipped": 3},
  "log":        "...captured log output...",
  "exception":  null
}
```

**Retention:** last N runs per automation on disk (default 100, configurable via `$config->automations['runHistoryLimit']`). Older records pruned on each new run.

## Observability

**v1 ships the per-automation run history and dashboard-ready logging — but not the Activity Dashboard integration itself.**

1. **Per-automation run history** (the Automations admin section, above) — the v1 observability surface: the drill-down for *one* automation: status, duration, args, return value, full log, exception, plus "Replay".
2. **Cross-automation activity stream — future.** When the [Activity Dashboard](activity-dashboard.md) ships, automations will register as an `ActivitySource` for the "what happened across *all* automations today?" fleet view, with security events (e.g. unauthorized `apiKey` webhook hits) highlighted. **Not built in this project.**

**Design-for-future principle:** v1 includes the `AutomationActivityLogger` so the future integration is a pure declaration, not a retrofit. From day one it writes structured, `type`-tagged lines to a rotating `automations-activity.log` channel (mirroring `OAuthActivityLogger`), with event types `run.started`, `run.success`, `run.failed`, `auto_disabled`, `webhook.unauthorized` (warning), `webhook.rate_limited` (warning). When the dashboard lands, registering the source — `requiredEdition: EditionFeature::AUTOMATIONS`, the matching event-type display metadata — is the only remaining work; no logging is retrofitted and no historical activity is lost in the interim.

## Sync

`automations` is added to `SyncableCollections::IDS` (alongside `builder-pages`, `mailer`, `mcp-prompt`, `dataviews`). Because Sync runs through `JumpStartImporter` and the externalized handler field serializes like any normal text property, a Sync push carries **config and handler together** in one payload — including for operators who don't use git.

- **Export/JumpStart.** The externalized field inlines its value into the JSON (read file → string). Unlike binary fields (image/file/depot/gallery), which the exporter nulls because binaries can't travel, the handler is text and travels fine. Default serialization is a plain (readable) JSON string; `base64` encoding is available as a fallback if escaping ever bites.
- **Import.** `JumpStartImporter` writes the field; the field re-externalizes the value back to `tcms-data/automations/<slug>/handler/handler.php` on the receiver. No special-casing in the importer — it sees a normal string property.

## Error Notification

- Optional `errorMailerId` per automation — a **select of a Mailer object** (the `mailer` reserved collection, via `relationalOptions`, same pattern as `auth.forgotPasswordMailerId`). The mailer object owns the recipients, subject, and Twig body; the runner sends through `EmailService::sendEmail($errorMailerId, $context)` where `$context` carries the automation id + exception. Empty = no email.
- New bundled schema `automation-error-notification` powers the email template; customers can customize by redefining it.
- Run record always captures exception + stack regardless of email config.
- Notification behavior is environment-aware (see Reconciliation).

## Beta 12/13 Reconciliation

The original plan predated several beta 12/13 primitives. The revised design wires into them rather than inventing parallels.

1. **Environment-aware failure handling (`EnvironmentResolver`).** Automation handler failures mirror extension crash containment:
   - **Development** — log loudly, surface exception + full stack in the admin run detail. Stay noisy for the author.
   - **Production** — contain quietly, capture exception + stack in the run record, send the selected `errorMailerId` mailer if configured.
2. **`DangerousCodeScanner` on save — warn, don't block.** Saving the `handler` field runs the same scanner extension review uses (`shell_exec`, `eval`, `base64_decode`, raw network, …). Automations are admin-authored and trusted, so flagged patterns surface as an **advisory** in the editor, not a hard gate. This is the deliberate answer to "sandbox / `disabled_functions`": no sandbox, but reuse the scanner for transparency.
3. **Auto-disable on repeated failure (mirrors extension quarantine).** A handler that throws **N consecutive times in Production** auto-disables (`enabled → false`) with an admin banner + one-click re-enable. **Never in Development.** Stops a broken cron from firing its `errorMailerId` notification every minute forever, or a broken event handler from degrading every matching write. Failure counters live in `<slug>.state.json`. (Extension-registered automations inherit their host extension's `ExtensionGuard`/quarantine instead — same outcome, different owner.)
4. **Compiled-container closure contract.** The beta-13 outage came from PHP-DI's compiler rejecting closures referencing `$this`/`self`/`static`. Forward-Compatibility Contract item #3 (handler is `require`d at runtime, never a container definition) is the explicit guard against this class of failure.
5. **`ExtensionProfiler` precedent.** The still-deferred concurrent-run/slow-automation guardrail now has a profiling precedent to model on when we build it.

## Admin UI

**Sidebar:** new "Automations" top-level entry (Pro only).

**List page** (`/admin/automations`) — table: name, trigger-type icons (clock/webhook/event — one automation can show several), enabled toggle, last-run status pill, last-run timestamp, "Run now" button. "New automation" button scaffolds the object + a starter handler chosen by the first trigger type. A banner appears for any auto-disabled automation with a one-click re-enable.

**Editor page** (`/admin/automations/{slug}`) — split: left pane CodeMirror PHP editor for the `handler` field (`mode: php`); right pane metadata form (name, description, `triggers` deck, error email). Lint-on-save validates the returned closure shape + each trigger's config + cron syntax, and runs `DangerousCodeScanner` (advisory). "Run now" button with an args form for ad-hoc testing.

**Run-history pages** — per-automation list of recent runs; per-run detail (status, duration, args, return value, full log, exception + stack). "Replay" re-fires with the same args.

## Extension Integration

Extensions register automations programmatically:

```php
$context->addAutomation('check-stripe-subscriptions', [
    'triggers' => [['type' => 'schedule', 'cron' => '0 */6 * * *']],
    'handle'   => fn (AutomationContext $ctx) => /* ... */,
]);
```

- Extension-registered automations live in memory only (no object in the `automations` collection, no handler file). The closure is held in memory — **never** pushed through the container (contract #3).
- Show in the admin list as read-only with an "Extension:" tag.
- Can be enabled/disabled but not edited inline.
- Adds a new `automations` capability to the extension permission system.
- Failures are contained by the host extension's `ExtensionGuard`; repeated crashes quarantine the extension (not just the automation).

## URL Routing on Stacks Installs

`/automations/<slug>` lives at the site root, so Stacks installs (where T3 is mounted at `/rw_common/plugins/stacks/tcms/`) need an additional rewrite rule in the docroot `.htaccess`, parallel to the MCP / OAuth rules:

```apacheconf
# Total CMS automations
RewriteRule ^automations/.+$ rw_common/plugins/stacks/tcms/public/index.php [QSA,L]
```

The Option 2 catch-all rule (anything not on disk routes through T3) covers this automatically, so a customer using the catch-all gets MCP, OAuth, and automations in one stroke.

## CLI Commands

- `tcms automations:process` — the dedicated runner. Find-due + execute the automations lane. Cron'd `* * * * *` on its own line, parallel to `jobs:process`.
- `tcms automations:list` — list automations with status, last-run summary.
- `tcms automations:run <slug>` — fire one manually with optional args.

## Edition Gating

- `EditionFeature::AUTOMATIONS` — new feature flag. Required edition: **Pro**.
- Routes, admin UI, CLI commands all gated. The internal JobQueue stays available to all editions (it's infrastructure, not a customer-authored surface).

## What Ships in v1

- The `automations` reserved collection + `external: true` capability on the `code` field
- Schedule / webhook / event triggers, plural per automation (`triggers` deck)
- Handler as externalized file-backed PHP, `require`d by the runner; admin CodeMirror editor
- Dedicated `automations:process` runner on its own cron line; async-by-default, per-webhook sync opt-in
- Run history with disk-based persistence + retention
- `AutomationActivityLogger` (structured `automations-activity.log`) — Activity Dashboard-ready logging; source registration deferred
- Sync support (config + handler in one JumpStart payload)
- Environment-aware error handling + `errorMailerId` (select a Mailer object) sent via `EmailService`
- `DangerousCodeScanner` advisory on save; auto-disable on repeated Production failure
- "Run now" / "Replay" buttons for ad-hoc execution
- Extension `addAutomation()` API
- CLI: `automations:process`, `automations:list`, `automations:run`
- Apache + Nginx docs updated for `/automations/` URL space
- New API-key permission: `automations.fire`
- `AutomationsEditionMiddleware` gating Pro
- Test fixtures for cron parsing, externalized-field round-trip, JumpStart/Sync of the handler, webhook routing, event subscription, single-flight

## Explicitly NOT in v1

- **Twig-based automations** — Twig is poor at imperative logic and exception handling; file-backed PHP is the right surface.
- **Visual builder** — Zapier-style "if X then Y" is a much larger subproject. 3.6+ at earliest.
- **Activity Dashboard integration** — the `ActivitySource` registration + dashboard wiring is deferred until the dashboard ships. v1 writes the structured `automations-activity.log` from day one (see Observability), so wiring it up later is a drop-in, not a retrofit.
- **Git overlay / two-tier resolution for handlers** — automations live in the CMS like Mailer. Handler files are real on disk, so wholesale `tcms-data/` git versioning works, but there's no special read-only-when-git-managed mode.
- **Per-automation API-key scoping** — single `automations.fire` permission for v1.
- **HMAC signature verification middleware** — too provider-specific. Handlers can call `$ctx->verifyHmac($secret, $algo)` themselves.
- **Distributed locks** — single-instance only; the per-automation single-flight guard is in-node. Multi-instance deployments must run the automations cron from a single node.
- **Sandbox / `disabled_functions` enforcement** — admin-authored, trusted; `DangerousCodeScanner` provides transparency instead.
- **Cron-expression UI builder** — text input with validation only.

## Estimated Effort

~3.5–4 weeks of focused work:

| Chunk | Days |
|---|---|
| `external: true` code-field capability (storage, lazy hydration, lifecycle, JumpStart round-trip) | 3 |
| `automations` reserved schema + `AutomationLoader` + `AutomationContext` | 3 |
| `automations:process` runner: find-due, automations lane, single-flight, per-trigger schedule state | 3 |
| Webhook routing + `AutomationWebhookAuthMiddleware` + new permission | 2 |
| Event subscription wiring at boot | 1 |
| Run history persistence + admin list page + status pill + auto-disable banner | 3 |
| Admin editor (CodeMirror handler field + `triggers` deck + lint + scanner advisory) | 3 |
| Environment-aware error handling + retry + run-record finalization | 2 |
| Extension `addAutomation()` API + read-only list rendering | 1 |
| `AutomationActivityLogger` (structured, dashboard-ready logging) | 1 |
| Sync (`SyncableCollections` + JumpStart serialization) | 1 |
| Apache/Nginx doc updates + CLI commands | 2 |
| Test coverage + fixtures | 3 |
| **Total** | **28 days** |

## Open Questions

Not blockers — defensible defaults — but worth deciding before locking the spec:

1. **Run-history visibility for `none`-auth public webhooks.** A spammy public endpoint can churn out failed-auth runs. Suggested default: only record runs that pass auth + validation; rate-limited drops don't persist.
2. **Default error mailer.** Should v1 seed a bundled "Automation Error" mailer object so `errorMailerId` has something to select out of the box (and ship empty-recipient so it's deliberately configured)? Recipients live on the mailer object, so there's no silent-misdirection risk — but an empty mailer list is also fine. Suggested: ship one optional bundled mailer, selection still opt-in.
3. **Run-record format on disk.** JSON-per-run is simple but makes "list last 10" a directory scan. If some customers exceed ~1000 runs/day, an append-only NDJSON file per automation could be faster. Defer until real load data.

## Decisions Locked During Brainstorming

| Decision | Considered alternative | Why we picked this |
|---|---|---|
| `automations` reserved collection, handler externalized to a file | Flat `tcms-data/automations/<slug>.php` files | Collection unlocks Sync (via JumpStart), admin-managed knobs, events; externalizing the handler keeps code in real files (no escaped JSON, IDE-editable, copy-paste migration). |
| `external: true` as an option on the existing `code` field | A brand-new field type | Smallest surface; reuses the `code` editor and existing file-save layout; a primitive `mcp-prompt`/`mailer` can adopt later. |
| Handler `require`d at runtime, never a container definition | Register via `addContainerDefinition()` / DI | Beta 13 proved PHP-DI's compiler rejects closures referencing `$this`/`self`/`static`. Runtime `require` sidesteps it entirely. |
| Plural `triggers` deck, one handler | One trigger per automation | The patterns we're consolidating often want the same logic reachable by cron *and* webhook. |
| Per-trigger `sync` (webhook only) | One global sync flag | With plural triggers a single flag is ambiguous; only webhooks have a caller to respond to. |
| Dedicated `automations:process` runner on its own cron line | Bolt onto `jobs:process` (with `--skip-automations`) | A large import backlog must not delay a scheduled automation on either due-detection or execution. Dedicated runner = inherent parallelism, no double-fire guard, simpler code. The one extra cron line is trivial for the Pro audience. |
| Reuse `EnvironmentResolver` / `DangerousCodeScanner` / quarantine pattern | Bespoke automation error + safety behavior | Consistency with the beta-12 extension-stability model; no parallel primitives. |
| Ship dashboard-ready logging now, defer the dashboard integration | Build the `ActivitySource` now, or a bespoke Automations dashboard | Run history covers v1 observability; the structured `automations-activity.log` makes the future `ActivitySource` a drop-in with no retrofit, and avoids a parallel dashboard. |
| Plain readable string for the externalized handler in JumpStart | base64 encoding | JSON escapes PHP fine (proven by the playground and mailer code fields); keeps Sync diffs reviewable. base64 stays an opt-in fallback. |
| Auto-disable after 5 consecutive Production failures | An automation-specific threshold | Reuse the extension-quarantine default for consistency. |
| PHP only | Add Twig as a second handler language | Twig is poor at imperative logic + exception handling; file-backed PHP with trust covers the use case. |

## Related Work

- `src/Domain/JobQueue/Service/JobRunner.php` — pattern reference for the new `AutomationRunner` (automations use a separate lane, not this queue).
- `src/CLI/Command/JobsProcessCommand.php` — pattern reference for `AutomationsProcessCommand`.
- `src/Domain/Event/EventDispatcher.php` — the event-trigger source.
- `src/Domain/Sync/Data/SyncableCollections.php` — add `automations` to `IDS`.
- `src/Domain/JumpStart/` — externalized-field serialization round-trip.
- `src/Domain/Extension/ExtensionContext.php` — where `addAutomation()` is added; `DangerousCodeScanner`, `ExtensionGuard`, quarantine, `EnvironmentResolver` are the beta-12 primitives reused.
- `resources/schemas/mcp-prompt.json`, `resources/schemas/mailer.json` — precedent for a code-bearing reserved schema (and future `external: true` adopters).
- `docs/planning/activity-dashboard.md` — automations register as an `ActivitySource`; see Observability. Pattern reference: `OAuthActivityLogger` for `AutomationActivityLogger`.
- `resources/docs/operations/apache.md` — Stacks rewrite section needs an `^automations/` row.
- `docs/planning/orphan-automation.md` — unrelated "cleanup orphan refs via the JobQueue" plan. Not coupled.
