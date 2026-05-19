# Bundled Extensions Roadmap

Tracks what's queued and what's deferred for the bundled-extension family that ships in `resources/extensions/totalcms/`. Companion to the [page-middleware framework](../../resources/docs/builder/overview.md#page-features-middleware).

The framework is small and stable: extensions register a name + container definition + a class implementing `PageMiddlewareInterface`; pages opt in via the **Features** field; the runner invokes the chain with the request + page record and short-circuits on the first response.

Already shipped: `totalcms/auth` (core), `totalcms/ab-split`, and `totalcms/geo-redirect` — see those extensions' source for live patterns to model new middleware against.

## Queued — easy wins worth shipping next

These are sized similarly to ab-split / geo-redirect (~100–200 LOC + tests + dedicated docs page), each demonstrates a distinct middleware shape, and each has clear customer value with no architectural blockers.

### 1. `totalcms/password` — shared-password gate

**Pattern:** Cookie-state stickiness (different from ab-split's random-bucket cookie — this is "visitor proved knowledge once, cookie remembers").

**Use cases:**
- Client previews of work-in-progress pages
- Soft launches before public announcement
- Draft staging URLs shareable via link
- Lightweight gating without integrating with T3's full auth/access-groups system

**Per-page config (`page.data`):**
```json
{
  "password": "preview2026",
  "promptTitle": "Preview Access"
}
```

**Behavior:**
- Visitor without the cookie sees a small inline form ("Enter password to view")
- POST submits → if password matches, set cookie `tcms_pwd_<page-id>=<hash>`, redirect to same URL
- Visitor with the cookie passes through to the page render

**Sketch:**
```php
// On GET without cookie → render password prompt
// On POST → validate, set cookie, 302 to self
// On GET with cookie → return null (proceed)
```

**Estimated effort:** ~200 LOC + tests + docs page. ~half a day.

**Open questions:**
- Hash algorithm for the cookie value? Probably `hash_hmac('sha256', password, page-id)` — deterministic per page so re-entering the password isn't required, but a different page can't trivially reuse another's cookie.
- Cookie TTL? 7 days probably, configurable later.

### 2. `totalcms/scheduled` — time-window gating

**Pattern:** Time-based logic (different from header reads or cookie state).

**Use cases:**
- Holiday landing pages (Black Friday, Cyber Monday)
- Time-limited sales / campaigns
- Embargoed announcements that go live at a specific time
- "Coming soon" pages that reveal content at a configured moment

**Per-page config (`page.data`):**
```json
{
  "scheduledFrom": "2026-11-25T00:00:00Z",
  "scheduledUntil": "2026-12-31T23:59:59Z",
  "outsideWindow": "/sale-ended"
}
```

**Behavior:**
- Inside the window → return null (page renders normally)
- Outside the window → 302 to `outsideWindow` if set, else 404
- Both bounds optional — open-ended ranges work (`scheduledFrom` only = "live after this date forever")

**Estimated effort:** ~100 LOC + tests + docs page. Quick.

**Open questions:**
- Timezone handling. Default to UTC; allow page-level override?
- "Preview as future date" — admin can pass `?_scheduledNow=...` to test? Same security concern as the geo-redirect debug override (skip).

### 3. `totalcms/maintenance` — per-page 503

**Pattern:** Short-circuit with a status code + custom message (simpler than the others).

**Use cases:**
- Surgical "this section is down for an update" without taking the whole site offline
- Different from T3's existing site-wide `MaintenanceModeMiddleware` (which is binary all-or-nothing)

**Per-page config (`page.data`):**
```json
{
  "maintenance": {
    "message": "This section is being updated. Back at 5pm EST.",
    "retryAfter": 3600
  }
}
```

**Behavior:**
- 503 with a custom HTML body containing the message
- `Retry-After` header set
- Could also check an admin allow-list to let logged-in admins bypass the gate (similar to T3's site-wide maintenance)

**Estimated effort:** ~100 LOC + tests + docs page.

## Bigger but high-value

### `totalcms/ratelimit` — per-IP rate limit

**Why bigger:** needs persistent storage for the request counter. Forces a design decision: cache backend? Extension-owned storage? Per-extension data dir? T3 has `CacheManager` available, so probably leverage that with a dedicated namespace. But it's a real architecture choice that the smaller extensions don't need to make.

**Use cases:**
- Contact-form spam prevention (most-requested feature)
- Signup rate-limiting
- API endpoints that route via builder pages

**Estimated effort:** ~300 LOC + tests + docs page + a small storage abstraction. ~1.5 days.

**Worth doing:** yes, but after the easier three are shipped.

## Deferred — needs architectural work first

**Header-injection middleware** (`noindex`, `cache-control`, `csp`):

Each of these is ~30 LOC of logic but they share a common problem: they want to **add headers to the rendered response** without short-circuiting it. Our middleware contract is `(Request, PageData) → ?Response` — return null lets the page render but gives the middleware no hook into the resulting response object.

The current workaround (used in ab-split's variant-A cookie) is to call PHP's `header()` directly. It works but it's fragile: relies on Slim's emitter using `replace=false` for Set-Cookie (true today, not contractually guaranteed), can't be unit-tested without `xdebug_get_headers()` or partial-mock subclasses, and it duplicates per-extension.

### Proposed contract extension

Allow middleware to return a richer result type instead of just `?Response`:

```php
interface PageMiddlewareInterface {
    public function handle(ServerRequestInterface $request, PageData $page): ?MiddlewareResult;
}

final readonly class MiddlewareResult {
    public function __construct(
        public ?ResponseInterface $response = null,    // short-circuit, as today
        public array $headers = [],                    // headers to add to the rendered response
        public array $cookies = [],                    // Set-Cookie strings to add
    ) {}
}
```

Backwards compatibility: middleware that returns `null` works unchanged (no result = proceed, no modifications). Middleware that returns `new MiddlewareResult(response: $r)` short-circuits as today. Middleware that returns `new MiddlewareResult(headers: [...])` proceeds AND `PageRouterMiddleware` adds the headers to the rendered response.

Once shipped, three header-injection bundled extensions become trivial:

- **`totalcms/noindex`** — adds `X-Robots-Tag: noindex, nofollow`
- **`totalcms/cache-control`** — sets `Cache-Control` per page
- **`totalcms/csp`** — sets `Content-Security-Policy` per page

Plus we can clean up ab-split's variant-A cookie hack to use the new mechanism.

**Estimated effort for the contract extension:** ~half a day (small interface change, update existing middleware to use it, update PageRouterMiddleware to honor the additions).

**When to do it:** when the next bundled extension that wants to inject headers comes up. Don't do it speculatively.

## Out of scope / not bundled

These could be useful but don't fit the "bundled with core" criteria (would bloat installs that don't need them, or have heavy deps, or are too niche):

- **`webhook-on-view`** — POST to external URL on each visit. Useful but needs HTTP-client config + retry semantics. Better as a third-party extension.
- **`view-counter`** — log a per-page view counter. Needs storage; better solved with proper analytics.
- **`bot-block`** — block specific User-Agents. Almost always better at the CDN/proxy layer.
- **`require-https`** — redirect HTTP → HTTPS. Almost always better at the web-server level.
- **`ip-allowlist`** — restrict by IP. Niche; admins doing this usually want the proxy / firewall to do it.
- **MaxMind GeoIP database support for `geo-redirect`** — adds a maintained-DB dependency to a bundled extension. Better as an opt-in plugin to geo-redirect, or as a standalone third-party extension.

## Sequencing

If we want to keep shipping bundled extensions: **`password` first** (highest customer value, biggest "wow" factor — every dev shop needs this), then **`scheduled`** (different pattern, marketing-friendly), then **`maintenance`** (simple completion). Could ship all three in 1.5–2 days of focused work.

After that, decide whether to extend the middleware contract for header injection, or move on to other roadmap items. The contract extension pays for itself once 2+ header-injection extensions exist; not before.

## See also

- The bundled-extension family came out of the now-completed Site Builder Refinements project (shipped 2026-05-05).
- [Page Features (Builder)](../../resources/docs/builder/overview.md#page-features-middleware) — user-facing docs for the framework
- [Bundled Extensions](../../resources/docs/extensions/bundled.md) — user-facing docs for the bundled-extension concept and the list of extensions
- [Extension Points → Page Middleware](../../resources/docs/extensions/extension-points.md) — how third-party extensions register their own middleware
