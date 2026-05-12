# T3 Service Worker — Feature Plan

**Status:** Planning (2026-04-23, refined 2026-05-01) — candidate for 3.5 (post-i18n)
**Refinement notes (2026-05-01):** Folded in i18n coupling (cache keys, version header, locale-aware manifest), lifecycle/kill-switch story, quota and eviction policy, HTMX-aware routing, range request handling, multi-tab coordination, dev-mode behavior. Observability moved from Phase 4 polish to Phase 1 foundation.
**Related:** HTMX Load More (shipped 3.2), Tiptap (shipped 3.2), Site Builder plan (`docs/planning/5-brief-builder.md`), Browser Extension plan (`docs/planning/browser-extension.md`), **Internationalization plan (`docs/planning/internationalization.md`) — lands first in 3.4**

## Vision

Introduce a service worker layer into T3 that is additive, opt-in where it affects public sites, and solves real user pain — not a tech-for-tech's-sake PWA badge. The headline wins:

- **Admin stays usable on flaky connections.** Saves queue locally; the admin replays them when the network returns. No more "I lost five minutes of edits when my coffee-shop wifi dropped."
- **Public sites feel instant.** Shell assets (JS/CSS/fonts) precached and served from disk. HTMX Load More responses cached with server-driven invalidation. Navigation between pages stops waiting on the network for the shell.
- **Stacks and Site Builder sites can opt in to PWA behavior** — installable, offline-capable — without bolting on another plugin.
- **Push notifications become possible** for admin events (form submissions, comments, jobs) without polling.

The service worker is a **performance and resilience layer**, not a replacement for T3's existing server-side caching. It sits in the browser; APCu / Redis / OPcache / Twig cache all stay where they are.

## Framework Choice: Workbox

**Use Workbox, not a from-scratch service worker.** The Service Worker API looks simple but the failure modes (update races, `skipWaiting` / `clients.claim` timing, split-brain caches, stale precache manifests, client message contracts) are where hand-rolled SWs quietly break. Workbox is:

- Google-maintained, battle-tested at scale, stable since 2017
- Tree-shakeable, roughly 20–30KB gzipped for the modules T3 would use
- Designed around the exact patterns T3 needs (precache, runtime routing, background sync, broadcast updates)
- Well-documented with clear migration paths across versions

Workbox handles the plumbing. T3-specific logic (draft queue, conflict resolution, admin-aware routing, locale-aware caching) sits on top of Workbox primitives.

The page-side counterpart `workbox-window` is used explicitly for SW registration, update lifecycle, and `postMessage` contracts — not hand-rolled.

## Architecture

### One Service Worker Per Origin

A single SW served at the site root (scope `/`) handles both admin and public routes via route-based strategies. Simpler than split admin/public SWs and avoids the Workbox setup cost of two manifests.

Served by T3 at `/sw.js` with the correct `Service-Worker-Allowed` header so scope can cover the whole origin.

### Source Layout

```
javascript/service-worker/
    index.js              # SW entry — imports Workbox modules, registers routes
    routes/
        public-shell.js   # CacheFirst for /css, /js, /fonts, /images
        public-htmx.js    # SWR for HTMX Load More + listing fragments (locale + HX-Request keyed)
        public-stream.js  # Range request handling for /stream/ (NetworkOnly, no caching)
        admin-shell.js    # Precache admin assets
        admin-writes.js   # BackgroundSync queue for admin saves
        push.js           # Push notification event handlers
    lib/
        cache-keys.js     # Locale, user, HTMX-aware cache key generator
        version.js        # Collection version header parsing (locale-aware)
        draft-queue.js    # IndexedDB queue for offline admin saves
        messaging.js      # postMessage + BroadcastChannel contracts
        observability.js  # Cache hit rates, error counts, posted to admin dashboard
        kill-switch.js    # No-op SW path for emergency unregistration
javascript/service-worker-client/
    register.js           # workbox-window-based registration with update toast
    health-tile.js        # Admin dashboard health indicator
    queue-inspector.js    # Admin pending-sync UI
```

### Build Integration

ESBuild doesn't have an official Workbox plugin. The pipeline:

1. `yarn build` compiles the SW source via ESBuild like the rest of T3's JavaScript.
2. A post-build step runs `workbox-build` in `injectManifest` mode: it scans the built asset directory and injects the precache manifest (file → revision hash) into the compiled SW.
3. The final SW lands at `public/sw.js` for Composer-installed T3, served by Slim as a static file with the correct headers.
4. A second build target produces `public/sw-killswitch.js` — a tiny SW that unregisters itself and clears all caches. T3 can swap which file is served at `/sw.js` via config without any code change.

`bin/watch.sh` extended to regenerate the SW manifest on asset changes during development.

### Cache Strategy Per Route

| Route pattern | Strategy | Why |
|---|---|---|
| `/css/*`, `/js/*`, `/fonts/*` (hashed filenames) | **CacheFirst** + precache | Immutable; version is in the filename |
| Public images from `/images/` | **StaleWhileRevalidate** + ExpirationPlugin (max 200 entries, 30 days) | Usually stable, occasional replacement |
| Public HTMX fragments (Load More, `/partial/*`) | **StaleWhileRevalidate** keyed by locale + collection version | Instant from cache; background revalidate when content changed |
| Public listing/detail pages with `Cache-Control: private` or `no-store` | **NetworkOnly** | Never cache personalized output |
| `/stream/*` (media with Range requests) | **NetworkOnly** with `RangeRequestsPlugin` | Range requests break standard caching strategies |
| Admin static assets (`/admin/assets/*`) | **CacheFirst** + precache | Ships with T3 release |
| Admin HTMX partials | **NetworkFirst** with 3s timeout, keyed by user ID | Freshness matters for admin; cache is fallback; no cross-user leakage |
| Admin writes (`PUT`/`POST`/`DELETE`) | **NetworkOnly** + BackgroundSync fallback | Online: straight through. Offline: queue and replay. |
| Admin read endpoints returning JSON | **NetworkFirst**, keyed by user ID | Admin usually wants fresh data |
| Extension routes | **NetworkFirst** by default; extension can opt into other strategies via hook | Extensions shouldn't get cached aggressively without consent |
| `/api/*` | **NetworkFirst** with locale + auth keys | API consumers expect freshness |
| Push endpoints | N/A — handled by `push` event listener | |

All caches have an `ExpirationPlugin` configured with explicit `maxEntries` and `maxAgeSeconds`. No unbounded caches. Quota exhaustion triggers eviction of the least-recently-used cache, never the IndexedDB draft queue.

### Cache Keys: Locale, HTMX, and Per-User

Cache keys are generated by `lib/cache-keys.js` and combine the URL with cache-relevant request properties:

- **Locale** — every cache key for a localized-collection response includes the active locale. Without this, the first visitor of any locale poisons the cache for everyone else. Source: `X-Locale` header, `?locale=` query, or URL prefix in that order.
- **HTMX** — requests with `HX-Request: true` cache separately from full-page requests. Caching an HTMX fragment and serving it to a browser navigation breaks the page (returns a fragment instead of a full document).
- **User ID** — admin endpoints include the authenticated user ID in the cache key. Agency workflows where multiple admins share a browser must not leak data between sessions.

Server responses must emit appropriate `Vary` headers so HTTP caches and CDNs honor the same isolation:

```
Vary: X-Locale, Cookie, HX-Request
```

### Collection Version Header

The runtime cache needs a freshness signal for content that changes:

- T3 emits `X-TCMS-Version: {collection}:{locale}:{last-modified-unix}` on responses derived from a specific collection (listing fragments, object pages, RSS/sitemap).
- For pages composing multiple collections, header carries a comma-separated list with the latest-modified of any contributor.
- SW stores this header alongside the cached response.
- On request, SW checks the cached version against a lightweight `HEAD` probe (or piggybacks on the SWR revalidation) and invalidates when mismatched.

APCu already tracks collection last-modified timestamps internally for its own invalidation — exposing one header is cheap and makes the SW cache genuinely correct rather than time-based-and-hope.

### Range Requests for `/stream/` Media

T3 has `/stream/` endpoints for audio/video playback that use HTTP Range requests. `CacheFirst` and `StaleWhileRevalidate` mishandle these by default — partial responses get cached as if they were complete and subsequent range requests serve corrupted data.

Policy: `/stream/*` is `NetworkOnly`. If media caching becomes valuable later, Workbox's `RangeRequestsPlugin` is the right tool, but it's deferred until a real customer asks.

### Cookies, Credentials, and CSRF

Explicit, written contract for SW request handling:

- **Cookies** — never stripped or modified by SW. Always `credentials: 'include'`.
- **CSRF tokens** — `X-CSRF-Token` header passes through unchanged on writes. BackgroundSync replay refreshes the token from the response of the first successful re-auth before replaying queued writes.
- **Authenticated responses** — never cached unless the route explicitly opts in. Default deny.
- **`Authorization` header** — passes through unchanged; not part of the cache key (auth is per-user via cookie session in T3, but the policy holds for any extension that uses bearer tokens).
- **SameSite** — SW does not change SameSite behavior; cookies behave identically with or without SW installed.

### Admin Offline — Draft Queue

The hard problem, worth dedicating architecture to:

- Admin save intercepted by the SW. If the network is available, it goes through as-is.
- If the network is unavailable (or a timeout fires), the SW pushes the request into an IndexedDB queue with: URL, method, body, `Content-Type`, timestamp, user ID, original `updatedAt` of the object being edited.
- Admin UI shows a "Pending sync (N)" pill when the queue is non-empty. Clicking it reveals the queued edits.
- Workbox BackgroundSync replays the queue on reconnect. Each replay:
    - Checks the current server `updatedAt` against the stored original.
    - On match: save goes through, item removed from queue.
    - On mismatch (someone else edited): item flagged as conflict. Admin UI surfaces the conflict with both versions side-by-side and the admin chooses how to resolve.
- Auth token expiry during offline: the replay catches 401s, prompts the admin to re-authenticate, then resumes the queue.

**Persistent storage.** The queue calls `navigator.storage.persist()` on first use to request that the browser not evict it under quota pressure. If the request is denied (some browsers gate this on PWA install or engagement metrics), an admin-visible warning surfaces in the queue inspector.

**Backup/export escape hatch.** "Download queued saves as JSON" button in the queue inspector — for catastrophic recovery before clearing browser storage or switching machines.

Scope for v1: queue supports object saves and single-field edits. Bulk operations (imports, mass updates) stay online-only — replaying them offline is a different problem class.

### Multi-Tab Coordination

Two admin tabs on the same site, both editing different objects, both go offline. Without coordination they get inconsistent UI: tab A shows the conflict resolver mid-edit on tab B; tab B drains the queue without telling tab A.

Standard fix: `BroadcastChannel('tcms-sw')`. Page-side `register.js` listens for SW events:

- `queue-changed` — repaint the pending-sync pill across all open tabs
- `replay-started` — disable destructive UI in all tabs until done
- `conflict-detected` — show conflict resolver in the foreground tab only (tracked via `Page Visibility API`)
- `cache-cleared` — soft-reload all tabs

### Quota and Eviction Policy

Browsers cap origin storage at roughly 10% of available disk on desktop, 6% on mobile. Without explicit limits, an image-heavy site can fill the SW cache, and the browser will then evict the **entire origin's storage** — including the IndexedDB draft queue. That's silent data loss.

Policy:

- Every Workbox runtime cache has an `ExpirationPlugin` with explicit `maxEntries` and `maxAgeSeconds`.
- Total runtime cache budget targeted at ~50MB across all caches; precache budget ~10MB for admin shell.
- IndexedDB draft queue is on `navigator.storage.persist()` (asks the browser not to evict).
- A periodic SW task checks `navigator.storage.estimate()` against thresholds and proactively trims caches before the browser does it indiscriminately.

### Lifecycle, Kill-Switch, and Dev Mode

**Kill-switch.** `public/sw-killswitch.js` is a tiny SW that, on `install`, unregisters itself and deletes all caches. T3 config exposes `serviceWorker.killSwitch: true` which causes the route at `/sw.js` to serve the kill-switch instead of the real SW. Already-installed clients update on next page load, unregister cleanly, and stop intercepting requests. This is the single most-painful learn-the-hard-way SW gotcha and shipping without a kill-switch is not an option.

**`serviceWorker.enabled: false`.** Setting this in config doesn't just stop new registrations — it actively serves the kill-switch SW. Without this, disabling SW does nothing for already-installed clients.

**Localhost auto-disable.** SW registration is skipped when `window.location.hostname` is `localhost`, `127.0.0.1`, or any `*.test` / `*.local` TLD. Developers don't fight stale caches during dev. Override available via `serviceWorker.devMode: 'enabled'`.

**Bypass-for-developer toggle.** Admin profile setting "Bypass service worker" — adds `?nosw=1` to all in-page links and instructs the SW to passthrough for the current session. Useful for support/debugging without forcing browser-level workarounds.

**Update flow timing.** When workbox-window detects an update:
- New SW enters `installing` → `installed` (waiting state)
- Page-side toast appears: "A new version is available. Reload when convenient."
- User clicks reload → page sends `SKIP_WAITING` message → new SW activates with `clients.claim()` → page reloads
- "Critical update" flag in the new SW (set in source for security fixes) escalates the toast to a 30-second auto-reload timer

### Cache Reset Broadcast

The `/emergency/cache/clear` endpoint needs a way to tell already-running SW clients to purge. `BroadcastChannel` is local to one browser, so it can't reach across sessions.

Mechanism: T3 maintains a monotonically increasing `cacheGeneration` integer in config. SW fetches `/sw-meta.json` (a tiny endpoint, NetworkFirst with very short cache) on every navigation and compares the returned `cacheGeneration` to its stored value. Mismatch triggers a full cache purge and a `cache-cleared` broadcast to all tabs.

This adds one cheap request per navigation but gives operators a real "purge all client caches" signal. The endpoint is served from APCu and is sub-millisecond.

### Extension Service Worker Routes

Extensions register routes via `RouteCollector` (per CLAUDE.md). Without a SW hook, extension routes get default cache behavior that may be wrong.

New `ExtensionContext` method:

```php
$context->registerServiceWorkerRoute(
    pattern: '/my-extension/api/*',
    strategy: 'networkFirst',
    cacheKey: ['locale', 'user'],
    expiration: ['maxEntries' => 50, 'maxAgeSeconds' => 3600]
);
```

These declarations are collected at build time and baked into the SW manifest. Extensions installed at runtime trigger a SW manifest regeneration via the existing event system.

### Stacks Page Caching Policy

Stacks-rendered pages are served from T3 but generated outside the Twig engine. They can include:

- Inline session-specific tokens
- Visitor-tracking pixels with timestamps in URLs
- User-personalized content (when stacks support auth)

Policy: respect HTTP cache directives aggressively. `Cache-Control: private`, `no-store`, or `max-age=0` → `NetworkOnly`. Anything else (typical anonymous public output) → `NetworkFirst` with a short cache fallback.

Stacks plugin gets documentation: "If your stack outputs personalized content, emit `Cache-Control: private` and the SW will not cache it." This is also good general advice for cache layers above the SW.

### CDN / Reverse Proxy Interaction

T3 sites behind Cloudflare, Varnish, or similar:

- `Vary: X-Locale, Cookie, HX-Request` headers must propagate through the CDN. Misconfigured CDNs strip `Vary` and serve cross-locale content. Documented in deployment guide.
- `X-TCMS-Version` headers must not be stripped — they're how the SW knows when to invalidate.
- For assets the CDN already serves with far-future `Cache-Control: max-age`, the SW uses CacheFirst-with-revalidation rather than precaching, deferring to the CDN as the upstream cache.
- The cache reset broadcast endpoint (`/sw-meta.json`) is `Cache-Control: no-store` and bypasses the CDN — without this, operators can't actually purge.

### Observability (Phase 1, not deferred)

Cache hit rates, install success/failure, replay success/failure, push delivery rate. Without these, "saves are missing" or "site feels slow" reports become unanswerable.

What ships in Phase 1:

- SW posts metrics to `/admin/api/sw-metrics` on a 60-second debounce when the page is visible (no metrics traffic from idle background tabs).
- Admin dashboard "Service Worker Health" tile: install version, cache hit rate (last 7 days), queue depth, last replay outcome, recent errors.
- Per-route hit/miss counts visible in a drilldown, scoped to the active user (no cross-user metric leakage).
- Workbox already emits all the events; Phase 1 wires them to the metrics pipeline.

This is small (probably 1–2 days of work) but transformative for operability.

### PWA Toggle for Public Sites

Opt-in per-site via an admin setting. When enabled:

- T3 generates `/manifest.webmanifest` with site name, theme color, icons, start URL
- **Locale-aware manifest** — when i18n is enabled, manifest is generated per locale and served from `/{locale}/manifest.webmanifest`. The HTML `<link rel="manifest">` in the base template points to the active locale's manifest. App name, description, and start URL are localized.
- Public SW registers on visitor pages via a small inline script in the base template
- Admin UI: icon upload + auto-generation of the full icon set (192, 512, maskable, monochrome for badging), theme color, display mode (`standalone`, `minimal-ui`, `browser`), offline fallback page (locale-aware)
- iOS splash screen generation (separate spec from Android maskable icons; ImageWorks handles both)
- Works equally for Site Builder templates and Stacks-rendered pages

Off by default. Users who opt in get: installable site, cached shell, SWR for listings, offline fallback. No changes for anyone who doesn't flip the toggle.

### Push Notifications

Requires a VAPID key pair generated once per T3 install and stored in config.

- Server-side: a `WebPushService` that maintains subscriptions (user + endpoint + keys + **locale preference**) and sends pushes using `minishlink/web-push`.
- SW: `push` event listener decodes the payload and shows a notification with optional **action buttons** ("View", "Dismiss", "Mark read").
- Admin-side: subscription UI in the admin profile. Notification preferences per event type. Locale selection (push payloads localize their title/body using the chosen locale's translations dictionary).
- Event hooks on the existing T3 event system: `object.created` (for form submissions), `collection.created`, custom hooks extensions can trigger.
- **Periodic background sync** (where supported) runs a weekly housekeeping task: refresh push subscription, prune expired cache entries, report metrics summary.
- Cross-browser: Chrome, Firefox, Edge have had web push for years. Safari added it in 16.4 (desktop + iOS 16.4+) — requires the site to be installed as a PWA on iOS.

### Test Infrastructure

SWs are notoriously hard to test. Strategy:

- **Unit tests** — SW routing logic extracted into pure modules tested against fake `Request` objects. No browser required, runs in standard Pest/Vitest.
- **Workbox built-in test helpers** — for cache strategies, use Workbox's testing utilities to verify hit/miss/expiration behavior without real network.
- **Playwright E2E** — full SW lifecycle in a real browser context: register, install, activate, intercept, offline simulation, BackgroundSync replay.
- **Manual test checklist** — documented in `docs/testing/service-worker.md` for scenarios that don't lend themselves to automation (multi-tab, slow connection, browser-specific quirks).

CI runs unit + Playwright on every PR touching `javascript/service-worker/`.

## Interaction With i18n

i18n lands first (3.4) and the SW must be locale-aware from day one:

- **Cache keys** include locale for any localized-collection response. Server emits `Vary: X-Locale, Cookie`.
- **`X-TCMS-Version` header** carries `{collection}:{locale}:{timestamp}` so cache invalidation is locale-scoped.
- **PWA manifest** is generated per-locale, served at `/{locale}/manifest.webmanifest`.
- **Push notification payloads** are localized using the subscriber's preferred locale, drawn from the same `tcms-data/translations/{locale}.json` dictionary that powers `cms.t()`.
- **Offline fallback page** is locale-aware — visitors see the offline page in their language when the network is gone.
- **Locale switcher** must work offline if the active object's translations are cached; SW caches both the locale's slug index and the per-locale fragment.

Without i18n landing first, the SW design would either ignore locale (broken caches) or guess at locale handling that the i18n implementation later contradicts. That's why ordering matters.

## Phases

### Phase 1 — Foundation

**Effort: ~2.5–3 weeks**

- Workbox integrated into the build (ESBuild → `workbox-build injectManifest`)
- SW served at `/sw.js` with scope `/`; kill-switch SW at `/sw-killswitch.js` and config-driven swap
- localhost auto-disable + bypass-for-developer toggle
- Precache for admin static assets; runtime CacheFirst for public hashed assets
- SWR strategy for HTMX Load More with locale + HX-Request in cache keys
- `X-TCMS-Version` header (locale-aware) emitted on listing and fragment responses
- Range request bypass for `/stream/`
- `ExpirationPlugin` configured per cache; quota monitoring
- Cookie/credentials passthrough policy enforced
- Cache reset broadcast (`/sw-meta.json` polling)
- Update flow with toast UX
- **Observability tile in admin dashboard** (cache hit rate, install version, errors)
- Multi-tab coordination via BroadcastChannel
- Test infrastructure: unit + Playwright E2E

**Done:** clear browser cache, load admin once, disconnect wifi, reload — admin shell loads from disk. Visit a public blog listing, click Load More, disconnect, reload, click Load More — page 2 loads from cache. Admin dashboard shows "SW: v1.0.0, 87% hit rate, 0 errors." Operator can flip kill-switch and all clients unregister within one navigation.

### Phase 2 — Admin Offline

**Effort: ~3 weeks**

- BackgroundSync queue for admin write endpoints
- IndexedDB-backed queue with `navigator.storage.persist()` request and admin warning if denied
- Per-user cache partitioning for admin endpoints (user ID in cache key)
- Multi-tab coordination for queue state, replay events, conflict resolution
- Admin UI: pending-sync pill, queue inspector, conflict resolver, queue export-as-JSON escape hatch
- Auth expiry handling in the replay path (CSRF token refresh on re-auth)
- Tests covering disconnect → edit → reconnect → replay, disconnect → edit → someone else edits → reconnect → conflict resolution, multi-tab queue interactions

**Done:** admin goes offline mid-edit, saves succeed locally with clear "queued for sync" feedback, admin keeps working, reconnection triggers automatic replay. Conflicts surface clearly instead of silently overwriting. Two admin tabs stay in sync about queue state.

### Phase 3 — PWA Toggle for Public Sites

**Effort: ~2 weeks**

- Per-site PWA settings schema and admin UI
- **Locale-aware** `manifest.webmanifest` generation, served from `/{locale}/manifest.webmanifest`
- Icon upload + auto-resize via ImageWorks, including maskable + monochrome variants
- iOS splash screen generation
- Locale-aware offline fallback page
- Stacks plugin documentation for `Cache-Control: private` interaction
- Site Builder template author docs

**Done:** user enables PWA, configures icons and theme, visits public site in Chrome → "Install app" appears. Installed site works offline with the locale-aware fallback page. iOS install path documented with splash screen support.

### Phase 4 — Navigation Polish

**Effort: ~1 week**

- Workbox navigation preload for same-origin link hover / viewport entry
- HTMX 4.0 `hx-boost` integration (boosted navigations resolve from SW cache instantly, HTMX swaps the body)
- Speculation Rules API support — server emits "likely next navigation" hints, SW warms cache
- `navigator.connection` awareness: on 2G/3G, prefer cache more aggressively, defer non-critical fetches
- Admin-specific prefetch of common next-page destinations (object edit from a listing, etc.)

**Done:** link hover triggers cache warm; clicking the link resolves instantly from cache. Boosted admin navigations feel SPA-fast.

### Phase 5 — Push Notifications

**Effort: ~2–3 weeks**

- VAPID key generation during install or via `tcms sw:init` CLI command
- `WebPushService` and subscription repository
- Subscription UI in admin profile with **locale preference** per subscription
- Notification preferences (per event type, per collection)
- Localized push payloads (drawn from `tcms-data/translations/`)
- Action buttons ("View", "Mark read", configurable per event type)
- Periodic background sync for housekeeping (subscription refresh, cache pruning, metrics rollup)
- Event listener wiring (form submitted → notify; comment added → notify, etc.)
- Safari 16.4+ PWA-install path documented

## Effort Summary

| Phase | Effort | Cumulative |
|---|---|---|
| 1. Foundation (incl. observability + lifecycle) | 2.5–3 weeks | 3 weeks |
| 2. Admin offline / draft queuing | 3 weeks | 6 weeks |
| 3. PWA toggle (locale-aware) | 2 weeks | 8 weeks |
| 4. Navigation polish | 1 week | 9 weeks |
| 5. Push notifications (locale-aware) | 2–3 weeks | 12 weeks |

**Total: ~10–12 weeks** of focused work for all five phases.

For 3.5: Phase 1 alone is a credible headline feature (~3 weeks).
For 3.6: Phases 1 + 2 together (~6 weeks) give a real "works offline, loads instantly" story.
Phases 3–5 can slot into subsequent minor releases as their value lands with customers.

## T3-Side Changes Summary

Explicit list of what T3 itself grows, so scope is clear:

- **Routing:** serve `/sw.js` (or kill-switch variant) with `Service-Worker-Allowed` header; `/sw-meta.json` for cache reset broadcast
- **Headers:** `X-TCMS-Version` (locale-aware) on collection-derived responses; `Vary: X-Locale, Cookie, HX-Request` enforced
- **Build:** ESBuild pipeline extended with `workbox-build` post-step; second build target for kill-switch SW
- **Admin:** pending-sync UI, conflict resolver, SW update prompt, PWA settings page, push subscription/preferences UI, **SW health dashboard tile**, queue inspector with export
- **Services:** `WebPushService`, `SubscriptionRepository`, version-header middleware, SW metrics ingestion, cache generation tracker
- **CLI:** `tcms sw:init` (VAPID keys), `tcms sw:status`, `tcms sw:clear-cache` (bumps cacheGeneration), `tcms sw:killswitch enable|disable`
- **Extensions:** `ExtensionContext::registerServiceWorkerRoute()` hook; manifest regeneration on extension install/uninstall
- **Emergency cache clear:** `/emergency/cache/clear` bumps `cacheGeneration` so connected clients purge on next navigation
- **Config:** `serviceWorker.enabled`, `serviceWorker.killSwitch`, `serviceWorker.devMode`, `serviceWorker.pwa.enabled` per-site, VAPID keys, notification defaults
- **Docs:** deployment guide section on CDN `Vary` header propagation; testing guide with manual checklist

## Interaction With Existing Caching

T3 already has APCu → Redis → Memcached → filesystem → OPcache → Twig cache layers. The SW adds a **client-side** layer in front of all of them, which changes the cache-clear story:

- "Clear cache" in the admin now has two paths: server caches (existing) and client SW cache (new — via `cacheGeneration` bump).
- The cache reset broadcast lets the admin signal all connected clients to purge their SW caches without a hard reload.
- The `/emergency/cache/clear` endpoint gains a client-wide reset version (bumps the generation).
- Debugging "user reports stale content" now adds a layer; docs need a clear checklist (server cache → CDN cache → SW cache).

This is the real cost of the SW, and why rushing past Phase 1 is a trap. The invariants must be nailed down before layering Phase 2's write-path behavior on top.

## Privacy and Data Storage

The SW stores data in the browser indefinitely. Push subscriptions tie a user to an endpoint. PWA install creates a persistent identity. EU customers will ask.

- **Cookie/storage notice** updates needed for sites with PWA enabled (template provided).
- **Push notification consent** is browser-level, but T3 surfaces enable/disable/revoke in the admin profile.
- **"Clear my data" path** for visitors: a documented JS snippet that unregisters SW and clears all caches. Reachable from a privacy/footer link.
- **Subscription data** (push endpoints, keys) is plaintext in `tcms-data/.system/push-subscriptions/`. Data retention policy: subscriptions auto-purge after 90 days of failed delivery attempts.
- No analytics/telemetry leaves the customer's server. SW metrics are local to the admin dashboard.

## Licensing / Editions

SW is **unlicensed** — ships to every T3 site regardless of edition. Reasons:

- Performance is a baseline quality concern, not a premium feature.
- Gating performance behind an edition signals "buy Pro or your site is slow," which is bad positioning.
- Push notifications is the one capability that could plausibly be Pro-gated if there's a desire to (requires server resources, subscription storage). Default recommendation: leave it free; push-at-scale has natural bounds.

## Open Questions

- **`cacheGeneration` polling cost.** One sub-millisecond fetch per navigation is fine for normal use but multiplies under heavy navigation patterns. Worth measuring before shipping. Alternative: SSE stream for connected admin sessions only, since visitors usually don't navigate fast enough to matter.
- **Per-locale manifest scope.** Browsers cache the manifest by URL. Switching locales mid-session would require a fresh manifest fetch — fine for first-time installs, less ideal for an installed PWA whose user changes language. May need a single "multi-locale" manifest with `dir`/`lang` per locale entry instead.
- **Stacks compatibility.** Caching strategies should work on Stacks output, but real-site verification needed before shipping. Stacks plugin docs need a "your stack and the SW" section.
- **Extension interaction.** Browser extension plan (`docs/planning/browser-extension.md`) runs alongside a SW fine. Caveat: SW cached responses might serve stale content to the extension's edit-map fetch — instrumented render endpoints (`/admin/editor/*`) are SW-bypassed via explicit route rules.
- **Update UX escalation.** Toast with "reload when convenient" default works, but how aggressively should "critical update" auto-reload? Current plan: 30-second timer with a cancel button. May need per-site policy.
- **iOS Safari push adoption.** Push requires PWA install on iOS, which is meaningful friction. Worth tracking adoption before over-investing in iOS push UX.
- **Non-HTTPS dev environments.** SW requires HTTPS (or `localhost`). T3 dev typically serves on `localhost` so this is fine, but staging on plain HTTP must be documented as unsupported.
- **PWA icon source quality.** Tiny uploaded icons → blurry 512px versions. Need minimum-dimension validation (probably ≥ 512×512 PNG/SVG required).
- **Per-user admin caches and shared workstations.** User ID in cache key prevents leakage between sessions, but doesn't auto-purge on logout. Should logout clear the prior user's admin cache entries explicitly?
- **Quota budget tuning.** 50MB runtime / 10MB precache is a starting estimate. Real-world tuning needed once observability data is flowing.

## How It Fits the Roadmap

- **3.4:** i18n lands first. SW depends on i18n's locale conventions.
- **3.5 candidate:** Phase 1 alone. Clean, bounded, ~3 weeks, visible perceived-performance win across every T3 site. Good minor-release headline.
- **3.6 candidate:** Phases 1 + 2 together. Offline admin is a differentiator most flat-file CMSes do not have.
- **Later minors:** Phases 3–5 layered in as customer demand signals.
- **Extension plan complement:** The browser extension gives admins editing superpowers on live sites; the SW gives visitors and admins speed + resilience. Adjacent concerns, no conflicts, buildable independently.
- **Site Builder complement:** PWA toggle (Phase 3) is most valuable when paired with Site Builder's generated stubs — clients get an installable, offline-capable site out of the box.
- **Extensions architecture:** Third-party extensions hook into the SW via `registerServiceWorkerRoute()` from Phase 1, and into push payloads via the event system from Phase 5.

## What Done Looks Like

- A T3 site served over HTTPS registers a service worker automatically. Public-site shell assets are precached; subsequent navigation loads from disk.
- An admin working on a long form loses their connection; saves queue locally with visible "pending sync" feedback (and persistent storage protecting against eviction); reconnection replays the queue; any conflicts surface cleanly.
- Two admin tabs stay in sync about queue state, replay events, and cache resets.
- A Site Builder or Stacks user flips "Enable PWA" in the admin, uploads an icon, and their visitors get an installable, offline-capable site with a locale-aware manifest.
- Admin users can opt in to push notifications and receive them — localized to their preferred language, with action buttons — on Chrome, Firefox, Edge, and Safari (with PWA install on iOS).
- "Clear cache" in the admin clears both server-side and client-side SW caches with one action via the `cacheGeneration` bump.
- An operator hitting a critical SW bug in production can flip `serviceWorker.killSwitch: true` and have all installed clients unregister cleanly within one navigation.
- The admin dashboard shows live SW health metrics: cache hit rate, queue depth, replay success rate, errors. "Saves are missing" reports become diagnosable.
- Localhost dev servers behave normally — no stale cache fights, no DevTools incantations required.
- No existing T3 site, Stacks-integrated site, or custom frontend is broken by the SW landing — opt-in everywhere it could affect public behavior.
