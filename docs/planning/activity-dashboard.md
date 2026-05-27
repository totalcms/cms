# Activity Dashboard — Design Spec

**Status:** Design approved, deferred execution until after Phase 5.
**Phase:** Phase 4.x follow-up (no Phase 5 dependency).
**Effort:** ~1 day total across three phased commits (B.1 / B.2 / B.3).

## Goal

Give T3 operators a queryable admin view of structured event logs so they can answer "what just happened?" without grepping log files. Generalizes beyond OAuth — any T3 subsystem that emits structured events (with a `type` field in the log context) can register as an activity source.

At launch the dashboard covers three core sources:
- **OAuth Activity** — 11 event types from `OAuthActivityLogger` (Phase 4)
- **API Key Activity** — new logger, 4 event types
- **Auth Activity** — refactored existing auth logging to use structured `type` fields

Extensions can register their own sources via `ExtensionContext::registerActivitySource()`.

## Non-goals

- Real-time streaming / SSE updates (operators can manually refresh)
- Aggregation charts (counts over time, etc.) — defer until needed
- Historical retention beyond Monolog's `RotatingFileHandler` default (10 days)
- Cross-source event correlation (e.g., link an OAuth `token.issued` to the auth `login` that preceded it)
- Replacing the existing Log Analyzer (which counts errors; complementary, different purpose)
- Export to CSV (defer until needed)

## Scope decisions (locked)

| Decision | Choice | Rationale |
|---|---|---|
| Page location | Single `/admin/utils/activity` with source dropdown | Simpler nav than three separate pages; mirrors Log Analyzer's logfile dropdown |
| Default time window | Today, with date picker for history | One file per page load; "what just happened" is the common case |
| Security event prominence | Highlighted rows (red bg + warning icon) + sidebar badge with today's warning count | Drives attention without a separate page; consistent with existing T3 dashboards |
| Extension hook | `ExtensionContext::registerActivitySource()` from day 1 | Same shape as MCP extension hooks; small additional surface; biggest ecosystem payoff |

## Architecture

### Core abstractions

**`ActivitySource`** value object — a registered source operators can observe:

```php
final readonly class ActivitySource
{
    public function __construct(
        public string $id,                    // 'oauth', 'apikey', 'auth'
        public string $label,                 // 'OAuth Activity' (translatable key)
        public string $logFilePrefix,         // 'oauth-activity'  (matches Monolog rotation)
        public ?EditionFeature $requiredEdition,  // gates UI + dispatch
        /** @var list<ActivityEventType> */
        public array $eventTypes,             // known types for filter dropdown + badge color
    ) {}
}
```

**`ActivityEventType`** value object — display metadata for a known type:

```php
final readonly class ActivityEventType
{
    public function __construct(
        public string $id,        // 'client.created', 'token.issued', 'auth.login'
        public string $label,     // 'Client created' (translatable key)
        public string $level,     // 'info' | 'warning'
        public string $badgeColor, // 'accent' | 'success' | 'warning' | 'danger'
    ) {}
}
```

**`ActivityEvent`** value object — a parsed log line:

```php
final readonly class ActivityEvent
{
    public function __construct(
        public string $datetime,            // ISO 8601
        public string $level,               // 'info' / 'warning' / 'error' (normalised lowercase)
        public string $channel,             // 'oauth-activity' / 'apikey-activity' / 'auth'
        public string $message,             // 'OAuth client created'
        /** @var array<string,mixed> */
        public array $context,              // {type, client_id, ...} JSON-decoded
        public ?string $type,               // shortcut: context['type'] if set, else null
    ) {}
}
```

Note: Monolog writes `INFO`/`WARNING`/`ERROR` uppercase in the log line; the parser lowercases on read so `ActivityEvent::level` matches `ActivityEventType::level` for direct comparison.

### Services

**`ActivitySourceRegistry`** — singleton, registered in container. Holds the list of sources + provides lookup by id. Pre-populates with the three core sources; extensions register additional sources during the `extension.register` lifecycle.

```php
final class ActivitySourceRegistry
{
    public function register(ActivitySource $source): void;
    public function get(string $id): ?ActivitySource;
    /** @return list<ActivitySource> */
    public function allForEdition(EditionFeatureService $editions): array;
}
```

**`ActivityLogParser`** — pure parser, reads a log file line-by-line:

```php
final readonly class ActivityLogParser
{
    /** @return list<ActivityEvent> */
    public function parseFile(string $path): array;
}
```

Parses Monolog's `LineFormatter` output:

```
[2026-05-26T14:30:22.000000+00:00] oauth-activity.INFO: OAuth client created {"type":"client.created",...} []
```

Regex breaks the line into datetime / channel / level / message / context-json / extra-json. Malformed lines log at debug and skip — never crash on a partial parse.

**`ActivityFetcher`** — high-level service tying source + date → events:

```php
final readonly class ActivityFetcher
{
    public function __construct(
        private ActivitySourceRegistry $registry,
        private ActivityLogParser $parser,
        private Config $config,  // for logs path
    ) {}

    /** @return list<ActivityEvent> */
    public function fetch(string $sourceId, string $date, ?string $typeFilter = null): array;

    public function countWarnings(string $sourceId, string $date): int;
}
```

Resolves the log path as `{logs_dir}/{source.logFilePrefix}-{date}.log`. Returns empty array on missing files (operator visible as "no events" state).

### HTTP layer

**`AdminActivityAction`** at `GET /admin/utils/activity`:

1. Source from `?source=` query (default: first source operator has edition access to)
2. Date from `?date=` query (default: today UTC)
3. Type filter from `?type=` query (default: all)
4. Fetch events via `ActivityFetcher`
5. Compute total warning count across all visible sources for today → sidebar badge
6. Render `admin/utils/activity.twig` with: sources list, current source, current date, current type filter, events list, warning count

Mounted under the existing admin route group (`AuthMiddleware`, `AdminOnlyMiddleware`). No edition middleware on the route itself — edition gating happens per-source inside the action so operators on Standard editions still see auth events (auth source is Lite-tier).

### Template

`resources/templates/admin/utils/activity.twig`:

```
[ Source ▾ ] [ Date 📅 ] [ Type ▾ ]              (filter row)

Activity for OAuth Activity — 2026-05-26
————————————————————————————————————————————————
Time       │ Type             │ Level    │ Details          │ ▾
14:30:22   │ client.created   │ info     │ Test (admin)     │
14:30:55   │ consent.granted  │ info     │ Test → admin     │
🚨 14:31:02│ refresh_replay   │ warning  │ c-abc grant g-xyz│   ← red row
14:31:15   │ token.issued     │ info     │ c-abc            │
...
```

Click a row → expand below with full JSON context. No JS framework required; CSS-only details/summary toggle (or a tiny HTMX swap for the JSON pretty-print).

Empty state when no events: "No events for OAuth Activity on this date."

Empty state when log file missing: "No log file for this date yet."

### Sidebar nav

`resources/templates/admin/utils.twig` System group gains an "Activity" entry:

```twig
{ "title": "Activity", "path": "activity" },
```

Twig computes `cms.admin.activitySources.warningCount(today)` and renders a red badge with count when > 0:

```
Activity  🔴 3
```

Implemented as a new `cms.admin.activitySources` Twig adapter method that delegates to `ActivityFetcher::countWarnings()` for each source the operator has edition access to.

## Phased rollout (separate commits)

### Phase B.1 — Dashboard machinery + OAuth source live

Ships the core infrastructure + makes existing OAuth events viewable.

Files:
- `src/Domain/Activity/Data/ActivitySource.php`, `ActivityEventType.php`, `ActivityEvent.php`
- `src/Domain/Activity/Service/ActivitySourceRegistry.php`, `ActivityLogParser.php`, `ActivityFetcher.php`
- `src/Action/Admin/AdminActivityAction.php`
- `resources/templates/admin/utils/activity.twig`
- Container wiring + route mount
- Sidebar entry in `utils.twig`
- Adapter method `cms.admin.activitySources.warningCount(date)`
- OAuth source registration in container (Pro-edition gated)
- Translations (6 locales)
- Tests: parser unit tests, source registry tests, fetcher tests, action feature test

### Phase B.2 — API Key Activity Logger + source

Ships API key observability.

Files:
- `src/Domain/ApiKey/Service/ApiKeyActivityLogger.php` (new — mirror `OAuthActivityLogger` shape)
- Wire `ApiKeyActivityLogger` into:
  - `ApiKeyCreateAction` → `keyCreated`
  - `ApiKeyDeleteAction` → `keyDeleted`
  - `ApiKeyAuthenticator` → `authFailure` on validation failure
- Container wiring for `apikey-activity.log` channel
- API Key source registration in `ActivitySourceRegistry` (Pro-edition gated to match existing API Keys feature)
- `apikey.key_used` event NOT wired by default (would be too noisy on busy sites); add a setting `apikey.logRequests` that operators can flip on for debugging
- Tests: ApiKeyActivityLogger unit tests; auth-failure integration test

### Phase B.3 — Auth log standardization

Refactor existing free-form auth logging to structured events; register Auth source (no edition gate).

Files touched:
- `src/Domain/Auth/Service/LoginService.php` → `auth.login`, `auth.login_failed`
- `src/Domain/Auth/Service/LogoutService.php` → `auth.logout`
- `src/Domain/Auth/Service/EmailVerificationService.php` → `auth.email_verification_sent`, `auth.email_verified`, `auth.email_verification_failed`
- `src/Domain/Auth/Service/PasswordResetService.php` → `auth.password_reset_requested`, `auth.password_reset_completed`
- `src/Domain/Auth/Service/PasskeyService.php` → `auth.passkey_registered`, `auth.passkey_used`, `auth.passkey_failed`
- Each `logger->info('User X did Y')` becomes:
  ```php
  $logger->info('User logged in', [
      'type'       => 'auth.login',
      'user_id'    => $userId,
      'collection' => $collection,
      'method'     => 'password' | 'passkey',
  ]);
  ```
- Auth source registration (no edition gate — available on Lite+)
- Tests: regression on existing auth tests (verify the new context shape didn't break log assertions)

## Extension hook design

`ExtensionContext::registerActivitySource(ActivitySource $source): void` — extension's `register()` method can register an activity source. Mirrors `registerMcpTool()` / `registerMcpResource()`.

Constraint: the extension is responsible for emitting events to the matching log file via its own logger. The dashboard just reads + displays.

Example extension snippet:

```php
public function register(ExtensionContext $context): void
{
    $context->registerActivitySource(new ActivitySource(
        id: 'webhook-delivery',
        label: 'Webhook deliveries',
        logFilePrefix: 'webhook-activity',
        requiredEdition: EditionFeature::WEBHOOK_ACTIONS,
        eventTypes: [
            new ActivityEventType('delivery.success', 'Delivered',  'info',    'success'),
            new ActivityEventType('delivery.failed',  'Failed',     'warning', 'danger'),
            new ActivityEventType('delivery.retry',   'Retrying',   'info',    'warning'),
        ],
    ));
}
```

Then in the extension's services, log to `webhook-activity.log` with structured `type` + context.

## Edition gating

- OAuth source: requires `EditionFeature::OAUTH_SERVER` (Pro+)
- API Key source: requires `EditionFeature::API_KEYS` (Pro+)
- Auth source: no edition requirement (Lite+)
- Extension sources: each declares its own required edition

`AdminActivityAction` filters the source dropdown to only what the current operator has edition access to. The action's first-available default respects this — operators on Standard edition would see Auth as the default.

## File layout

```
src/Domain/Activity/
    Data/
        ActivityEvent.php
        ActivityEventType.php
        ActivitySource.php
    Service/
        ActivityFetcher.php
        ActivityLogParser.php
        ActivitySourceRegistry.php

src/Domain/ApiKey/Service/
    ApiKeyActivityLogger.php  (Phase B.2)

src/Action/Admin/
    AdminActivityAction.php

src/Domain/Twig/Adapter/
    ActivityTwigAdapter.php   (cms.admin.activitySources)

resources/templates/admin/utils/
    activity.twig

resources/translations/
    admin.{de_DE,en_GB,en_US,es_ES,it_IT,nl_NL}.php
    (new keys under activity.*)
```

## Testing

**Unit tests:**
- `ActivityLogParserTest` — round-trip parse, malformed line handling, multi-line context resilience
- `ActivitySourceRegistryTest` — register, lookup, allForEdition filtering
- `ActivityFetcherTest` — file resolution, date handling, type filtering
- `ApiKeyActivityLoggerTest` — each public method calls logger with right context shape (mirror `OAuthActivityLoggerTest`)

**Feature tests:**
- `AdminActivityActionTest` — renders for each source, date picker round-trip, type filter round-trip, edition gating
- `ActivityExtensionRegistrationTest` — extension can register a source; dashboard surfaces it

**Integration:**
- Verify auth refactor (B.3) — that existing tests using `logger->info('logged out')` still pass after the refactor changes message + adds context (may need test updates)

## Translations

New translation keys (6 locales):

```
activity.title                = "Activity"
activity.desc                 = "Inspect structured events from OAuth, API keys, auth, and extensions."
activity.source_label         = "Source"
activity.date_label           = "Date"
activity.type_label           = "Event type"
activity.type_all             = "All event types"
activity.no_events            = "No events for %source% on %date%."
activity.no_log_file          = "No log file for this date yet."
activity.warning_badge_title  = "%count% security event(s) today"
activity.column_time          = "Time"
activity.column_type          = "Type"
activity.column_level         = "Level"
activity.column_details       = "Details"
```

Per-event-type labels (e.g., `activity.event.oauth.client_created`) live alongside.

## Performance considerations

- Single log file per page load. Largest realistic file: ~10K events/day for high-traffic sites = ~3MB file. Parse + render in < 200ms for that size; well under the 2s admin page budget.
- Sidebar badge requires reading the OTHER source files too for today's warnings. Cache the count via `CacheManager` with a 60s TTL so sidebar rendering doesn't repeatedly scan files. `CacheManager` routes to APCu / Redis / filesystem per T3's standard cache backend chain; no direct APCu dependency. TTL-based expiry only (never explicit invalidation — write-side never touches this cache).
- Bigger sites with > 100K daily events would need pagination — defer until reported.

## Risks

| Risk | Severity | Mitigation |
|---|---|---|
| Log format changes break the parser | Medium | Parser is regex-tolerant + has explicit "skip malformed" path; unit tests pin known good shapes |
| Auth refactor (B.3) breaks existing log-based assertions in tests | Medium | Run full test suite after refactor; fix any pinned-to-message-string assertions |
| File reads block the admin page on slow disks | Low | Files are small; admin pages already do worse I/O |
| Extension activity sources file outside `logs/` dir | Low | `ActivitySource.logFilePrefix` is the filename prefix; we always resolve to `logs/{prefix}-{date}.log`; can't escape the logs dir |

## Out of scope (future work)

- Streaming / SSE updates for live tailing
- Aggregation charts (events-over-time)
- Cross-source correlation (linking related events across sources)
- CSV / JSON export
- Search across event context fields
- Configurable retention (Monolog rotation already handles this)
- Email/Slack alerts on security events (separate notification feature)

## Effort summary

- **Phase B.1** (dashboard + OAuth source): ~4 hours
- **Phase B.2** (API Key logger + source): ~1.5 hours
- **Phase B.3** (auth refactor + source): ~3-4 hours

Total: ~1 day across three independent commits.
