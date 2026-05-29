# T3 Automations — 3.5 Plan

**Status:** Planning. Final big feature targeted for 3.5.

User-authored, server-side automations driven by three trigger types — **schedules** (cron), **webhooks** (HTTP), and **events** (T3 EventDispatcher). Each automation is a single PHP file with a pre-injected service context; no bootstrap boilerplate. Async-by-default execution on the existing JobQueue. Admin UI for listing, editing, monitoring, and ad-hoc test runs.

## Goal

Give T3 operators a centralized place to author the kind of script that today lives in `bin/processX.php` files alongside a project — eliminate the bootstrap, give them a managed runner, monitor + log + retry every run.

## Motivating Customer Scripts

Three real scripts from production customers, all of which today live as hand-rolled PHP files outside T3 and share an identical bootstrap pattern:

| Script | Trigger today | Lines of boilerplate before business logic |
|---|---|---|
| `processMonthlyInvestments.php` | cron daily | ~20 (autoload, `new TotalCMS()`, `clearCache()`, fetch services from container, set up logger) |
| `processAnnualInvestments.php` | cron yearly | ~20 (same) |
| `processPortfolioProfits.php` | webhook (`/webhook/...`) | ~30 (bootstrap + manual validation + manual JSON-response helpers) |

Every script wraps its main logic in a top-level try/catch that ends with `$totalcms->mailer()->sendEmail('cron-job-errors', ...)`. Every script independently calls `$totalcms->createLogger(...)` with the same arguments. **The boilerplate is the feature gap.**

## Forward-Compatibility Contract

These decisions are load-bearing for any future automation work (Twig automations, visual builder, distributed runners). 3.5 must respect them, and breaking them in 3.6+ requires a migration plan.

1. **One file per automation.** `tcms-data/automations/<slug>.php` returns a config array containing the handler closure. Slug is derived from filename.
2. **Trigger declared in-file.** Type, params, sync flag, error notification — all in the returned array. Not in a separate registry. Add an automation by writing a file.
3. **Run records live in `tcms-data/.system/automations/<slug>/runs/`.** Files, not collections. Pruned on each new run.
4. **AutomationContext is the only API surface for the handler.** Handlers receive `AutomationContext`, never the raw container or `$_GET`/`$_POST` super-globals. Service additions go on the context. New trigger types add new context fields (e.g. `$ctx->event` only set for event triggers).
5. **URL prefix is configurable.** Default `/automations/<slug>`, override via `$config->automations['urlPrefix']`. Stacks installs need an additional rewrite parallel to MCP / OAuth.
6. **API auth permission is single-scope.** `automations.fire` — one permission row, gates every webhook automation that uses `auth: 'apiKey'`. Per-automation scoping deferred to v1.x if customer demand emerges.
7. **Pro edition only.** Standard customers keep the internal JobQueue for T3-driven work (imports, bulk mailers) but cannot author automations.

## Architecture

### The Automation File

Single PHP file, returns metadata + handler closure:

```php
<?php

return [
    'name'        => 'Process Monthly Investments',
    'description' => 'Distributes monthly profit-sharing returns to investor deck items.',
    'enabled'     => true,
    'sync'        => false,                  // async-by-default; flip to opt in to inline execution
    'errorEmail'  => 'admin@example.com',    // optional; sends on uncaught exception
    'trigger'     => [
        'type' => 'schedule',
        'cron' => '0 1 * * *',
    ],
    'handle' => function (AutomationContext $ctx) {
        $usersIndex = $ctx->indexReader->fetchIndex('users');

        $usersIndex->objects->each(function ($user) use ($ctx) {
            // ... business logic
        });

        return ['created' => 42, 'skipped' => 3];
    },
];
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
| `$ctx->args` | yes | Inputs: CLI args for manual runs, merged query+body for webhooks, event payload for event triggers. |
| `$ctx->request` | webhook only | PSR-7 `ServerRequestInterface` for full access to headers, raw body, etc. |
| `$ctx->event` | event only | The dispatched event payload. |
| `$ctx->container` | yes | Escape hatch — raw DI container access for anything else. |

### Triggers

**Schedule:**

```php
'trigger' => [
    'type'     => 'schedule',
    'cron'     => '0 1 * * *',           // standard 5-field cron expression
    'timezone' => 'America/New_York',    // optional; defaults to site timezone
],
```

- Parsed by `dragonmantank/cron-expression` (mature, widely-used PHP library).
- Last-fire timestamp stored in `tcms-data/.system/automations/<slug>.state.json`.
- A "find-due-schedules" pass is added to the start of `tcms jobs:process`. Customers reuse their existing cron line; no new cron entry needed.

**Webhook:**

```php
'trigger' => [
    'type'    => 'webhook',
    'slug'    => 'process-portfolio-profits',  // optional; defaults to filename
    'auth'    => 'apiKey',                     // 'apiKey' | 'none'
    'methods' => ['POST'],                     // optional; default ['POST']
],
```

- Mounted at `<urlPrefix>/<slug>` (default `/automations/<slug>`).
- `apiKey` mode: reuses the existing REST-API API-key system (`X-API-Key` header or `?key=` query param) and verifies the key has the new `automations.fire` permission. Returns 401 on failure. Admin-session auth is not accepted on these routes — webhooks come from external services, not browsers.
- `none` mode: public, rate-limited per IP via the existing rate limiter.

**Event:**

```php
'trigger' => [
    'type'       => 'event',
    'event'      => 'object.created',
    'collection' => 'orders',     // optional filter; only fire for this collection
    'priority'   => 0,            // optional EventDispatcher priority
],
```

- Wired into the existing `EventDispatcher` at boot. Subscribes to any of the 17 core events.
- T3 events are post-action, not pre-action — automations are reactive, not transformative.
- Can subscribe to `import.created` / `import.updated` for importer reactions.

### Execution

**Reuses the existing JobQueue + `tcms jobs:process`.**

- Async automations enqueue an `AutomationJob` carrying `{automationSlug, runId, trigger, args}`.
- `tcms jobs:process` (already cron'd by every T3 install) processes them.
- Sync automations bypass the queue and run inline. "Inline" means:
  - **Schedule + sync** — the `jobs:process` worker runs the handler during the find-due-schedules pass instead of enqueueing. Identical observable behavior to async since the same worker is doing the work; this exists for API symmetry.
  - **Webhook + sync** — the request handler runs the automation and waits; response body is `{runId, status: 'success'|'failed', return: ..., exception: ...}`. Caller blocks until done.
  - **Event + sync** — the event listener runs the handler inside the originating request. Exception propagates to the event-dispatcher's existing try/catch and is logged but does not abort the originating action (T3 events are post-action).
- Retry policy applies to **async paths only**. 3 attempts with exponential backoff for webhook + event automations queued via JobQueue. Sync paths surface the failure to the caller immediately (webhook gets 500; event-listener exception is logged). Schedule automations don't retry — cron fires again next interval.
- Each run gets a `runId` (uuid) tracked from enqueue → execution → record persistence.

Webhook flow (async):

```
POST /automations/<slug>
  → AutomationWebhookAuthMiddleware (verifies X-API-Key has automations.fire)
  → AutomationWebhookAction enqueues AutomationJob
  → 202 Accepted { runId, status: 'queued' }
[next jobs:process tick]
  → JobRunner.runAutomation()
  → instantiates AutomationContext
  → executes handler in try/catch
  → persists run record (status, duration, return value, exception)
  → on exception with errorEmail: sends notification via Mailer
```

### Run History

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

### Error Notification

- Optional `errorEmail` per automation (string or array of recipients).
- New bundled schema `automation-error-notification` powers the email template; customers can customize by redefining it.
- Run record always captures exception + stack regardless of email config.

### Admin UI

**Sidebar:** new "Automations" top-level entry (Pro only).

**List page** (`/admin/automations`) — table: name, type icon (clock/webhook/event), trigger summary, enabled toggle, last-run status pill, last-run timestamp, "Run now" button. "New automation" button opens editor with a starter template chosen by trigger type.

**Editor page** (`/admin/automations/{slug}`) — split: left pane CodeMirror PHP editor; right pane metadata form (name, description, trigger config, sync flag, error email). Lint-on-save validates the returned array shape + trigger config + cron expression syntax. "Run now" button with args form for ad-hoc testing.

**Run-history pages** — per-automation list of recent runs; per-run detail (status, duration, args, return value, full log, exception + stack). "Replay" button re-fires with the same args.

### Extension Integration

Extensions register automations programmatically:

```php
$context->addAutomation('check-stripe-subscriptions', [
    'trigger' => ['type' => 'schedule', 'cron' => '0 */6 * * *'],
    'handle'  => fn(AutomationContext $ctx) => /* ... */,
]);
```

- Extension-registered automations live in memory only (no file in `tcms-data/automations/`).
- Show in the admin list as read-only with an "Extension:" tag.
- Can be enabled/disabled but not edited inline.
- Adds a new `automations` capability to the extension permission system.
- The bundled `scheduled` extension becomes a thin wrapper around this API.

### URL Routing on Stacks Installs

`/automations/<slug>` lives at the site root, so Stacks installs (where T3 is mounted at `/rw_common/plugins/stacks/tcms/`) need an additional rewrite rule in the docroot `.htaccess`, parallel to the MCP / OAuth rules. The Apache doc's "Root-level Endpoints" section will be extended:

```apacheconf
# Total CMS automations
RewriteRule ^automations/.+$ rw_common/plugins/stacks/tcms/public/index.php [QSA,L]
```

The same caveat applies — the Option 2 catch-all rule (anything not on disk routes through T3) covers this automatically, so a customer using the catch-all gets MCP, OAuth, and automations all in one stroke.

### CLI Commands

- `tcms automations:list` — list automations with status, last-run summary.
- `tcms automations:run <slug>` — fire one manually with optional args.

### Edition Gating

- `EditionFeature::AUTOMATIONS` — new feature flag.
- Required edition: **Pro**.
- Routes, admin UI, CLI commands all gated. Existing JobQueue stays available to all editions (it's internal infrastructure, not a customer-authored surface).

## What Ships in v1

- Schedule / webhook / event triggers
- File-based storage (`tcms-data/automations/<slug>.php`) + admin code editor (CodeMirror)
- Async-by-default execution on existing JobQueue, sync opt-in
- Run history with disk-based persistence + retention
- Error notification via Mailer with customizable schema
- "Run now" / "Replay" buttons for ad-hoc execution
- Extension `addAutomation()` API
- CLI: `automations:list`, `automations:run`
- Apache + Nginx docs updated for `/automations/` URL space
- New API-key permission: `automations.fire`
- `AutomationsEditionMiddleware` gating Pro
- Test fixtures for cron-expression parsing, file loading, webhook routing, event subscription

## Explicitly NOT in v1

- **Twig-based automations** — Twig is bad at imperative logic and exception handling; PHP-only is the right surface for v1.
- **Visual builder** — Zapier-style "if X then Y" is a much larger subproject. 3.6+ candidate at earliest.
- **Per-automation API-key scoping** — single `automations.fire` permission for v1. Per-slug scopes add admin friction with little real security benefit for the typical customer (5–10 automations, not 50). Add on demand.
- **HMAC signature verification middleware** — too provider-specific (Stripe vs GitHub vs Twilio each use different formats). Handlers can call `$ctx->verifyHmac($secret, $algo)` themselves.
- **Distributed locks** — single-instance only. Multi-instance T3 deployments must run cron from a single node. Distributed-lock support is a 3.6+ candidate if/when horizontal scaling becomes a customer ask.
- **Concurrent-run prevention per automation** — a slow automation triggered every minute can pile up. Adding a `concurrency: 1` flag is a v1.x add-on.
- **Cron-expression UI builder** — text input with validation only. Common-pattern preset dropdown is a v1.x add-on.

## Estimated Effort

~3 weeks of focused work:

| Chunk | Days |
|---|---|
| `AutomationLoader` + `AutomationContext` + file scanner | 3 |
| Schedule tick: cron parsing, due-job detection, JobQueue enqueue | 2 |
| Webhook routing + `AutomationWebhookAuthMiddleware` + new permission | 2 |
| Event subscription wiring at boot | 1 |
| Run history persistence + admin list page + status pill | 3 |
| Admin editor with CodeMirror + metadata form + lint | 3 |
| Error mailer + retry policy + run-record finalization | 2 |
| Extension `addAutomation()` API + read-only list rendering | 1 |
| Apache/Nginx doc updates + CLI commands | 2 |
| Test coverage + fixtures | 3 |
| **Total** | **22 days** |

## Open Questions

These are not blockers — they have defensible defaults — but worth deciding before locking the spec:

1. **Run-history visibility for `none`-auth public webhooks.** A spammy public endpoint can churn out hundreds of failed-auth runs per minute. Suggested default: only record runs that pass auth + validation; rate-limited drops don't persist. Override per automation if needed.
2. **Default `errorEmail` recipient.** First admin user? Site `mail.from` address? Or require explicit per-automation config? Suggested default: require explicit config — silent fallback to a "default admin" risks misdirected error reports.
3. **Sandbox / `disabled_functions` enforcement.** None in v1 — automations are admin-authored and trusted. Worth a note in the security doc.
4. **Run-record format on disk.** JSON-per-run files are simple but make "list last 10 runs" a directory scan. If we expect >1000 runs/day for some customers, an append-only NDJSON file per automation could be faster. Defer until we have real load data.

## Decisions Locked During Brainstorming

For audit / future-me: each of these was an explicit choice during the spec brainstorm, with the alternative considered.

| Decision | Considered alternative | Why we picked this |
|---|---|---|
| All three triggers in v1 (schedule, webhook, event) | Schedule only, then layer on | The whole point is one home for the patterns. Shipping one trigger gives an incomplete story. |
| PHP only | Add Twig as opt-in second language | Twig is poor at imperative logic + exception handling; doubles surface area; no clear use case for a sandboxed automation that PHP-with-trust can't already serve. |
| Files in `tcms-data/automations/` with admin editor | Database-stored / external-edit only | Customer expectation is "manage everything in the admin"; XSS risk is bounded by existing admin auth. |
| Async-by-default, sync opt-in | Sync-by-default, async opt-in | Davide's webhook ran for 5 minutes — most webhook senders time out long before that. Async is the safer default; sync is opt-in for response-shaping cases. |
| Webhook auth via `apiKey` (existing system) or `none` | Build a new HMAC + token + IP allowlist auth surface | Reuse existing API-key infrastructure; HMAC is per-provider and better handled in handler code. |
| Single `automations.fire` permission | Per-automation slug-scoped permissions | Real customers have <20 automations; per-slug scope is permission-row sprawl with marginal security upside. Add on demand. |

## Related Work

- `src/Domain/JobQueue/Service/JobRunner.php` — the queue runner we extend.
- `src/CLI/Command/JobsProcessCommand.php` — the cron tick we hook into.
- `src/Domain/Event/EventDispatcher.php` — the event-trigger source.
- `src/Domain/Extension/ExtensionContext.php` — where `addAutomation()` is added.
- `resources/docs/operations/apache.md` — Stacks rewrite section needs an `^automations/` row.
- `docs/planning/orphan-automation.md` — unrelated, "cleanup orphan refs via the JobQueue" plan. Both rely on JobQueue; not coupled.
