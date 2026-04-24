# T3 Service Worker — Feature Plan

**Status:** Planning (2026-04-23) — candidate for 3.4 / 3.5
**Related:** HTMX Load More system (shipped 3.2), Tiptap editor (shipped 3.2), Site Builder plan (`docs/planning/site-builder.md`), Browser Extension plan (`docs/planning/browser-extension.md`)

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

Workbox handles the plumbing. T3-specific logic (draft queue, conflict resolution, admin-aware routing) sits on top of Workbox primitives.

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
        public-htmx.js    # SWR for HTMX Load More + listing fragments
        admin-shell.js    # Precache admin assets
        admin-writes.js   # BackgroundSync queue for admin saves
        push.js           # Push notification event handlers
    lib/
        version.js        # Collection version header parsing
        draft-queue.js    # IndexedDB queue for offline admin saves
        messaging.js      # postMessage contract with pages
```

### Build Integration

ESBuild doesn't have an official Workbox plugin. The pipeline:

1. `yarn build` compiles the SW source via ESBuild like the rest of T3's JavaScript.
2. A post-build step runs `workbox-build` in `injectManifest` mode: it scans the built asset directory and injects the precache manifest (file → revision hash) into the compiled SW.
3. The final SW lands at `public/sw.js` for Composer-installed T3, served by Slim as a static file with the correct headers.

`bin/watch.sh` extended to regenerate the SW manifest on asset changes during development.

### Cache Strategy Per Route

| Route pattern | Strategy | Why |
|---|---|---|
| `/css/*`, `/js/*`, `/fonts/*` (hashed filenames) | **CacheFirst** + precache | Immutable; version is in the filename |
| Public images from `/images/` | **StaleWhileRevalidate** | Usually stable, occasional replacement |
| Public HTMX fragments (Load More, `/partial/*`) | **StaleWhileRevalidate** keyed by collection version header | Instant from cache; background revalidate when content changed |
| Admin static assets (`/admin/assets/*`) | **CacheFirst** + precache | Ships with T3 release |
| Admin HTMX partials | **NetworkFirst** with 3s timeout → cache | Freshness matters for admin; cache is fallback |
| Admin writes (`PUT`/`POST`/`DELETE`) | **NetworkOnly** + BackgroundSync fallback | Online: straight through. Offline: queue and replay. |
| Admin read endpoints returning JSON | **NetworkFirst** | Admin usually wants fresh data |
| Push endpoints | N/A — handled by `push` event listener | |

### Collection Version Header

The runtime cache needs a freshness signal for content that changes. Proposal:

- T3 emits `X-TCMS-Version: {collection}:{last-modified-unix}` on responses that are derived from a specific collection (listing fragments, object pages, RSS/sitemap).
- SW stores this header alongside the cached response.
- On request, SW checks the cached version against a lightweight `HEAD` probe (or piggybacks on the SWR revalidation) and invalidates when mismatched.

APCu already tracks collection last-modified timestamps internally for its own invalidation — exposing one header is cheap and makes the SW cache genuinely correct rather than time-based-and-hope.

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

Scope for v1: queue supports object saves and single-field edits. Bulk operations (imports, mass updates) stay online-only — replaying them offline is a different problem class.

### PWA Toggle for Public Sites

Opt-in per-site via an admin setting. When enabled:

- T3 generates `/manifest.webmanifest` with site name, theme color, icons, start URL
- Public SW registers on visitor pages via a small inline script in the base template
- Admin UI: icon upload + auto-generation of the full icon set (192, 512, maskable), theme color, display mode (`standalone`, `minimal-ui`, `browser`), offline fallback page
- Works equally for Site Builder templates and Stacks-rendered pages

Off by default. Users who opt in get: installable site, cached shell, SWR for listings, offline fallback. No changes for anyone who doesn't flip the toggle.

### Push Notifications

Requires a VAPID key pair generated once per T3 install and stored in config.

- Server-side: a `WebPushService` that maintains subscriptions (user + endpoint + keys) and sends pushes using an existing PHP library (e.g. `minishlink/web-push`).
- SW: `push` event listener decodes the payload and shows a notification.
- Admin-side: subscription UI in the admin profile. Notification preferences per event type.
- Event hooks on the existing T3 event system: `object.created` (for form submissions), `collection.created`, custom hooks extensions can trigger.
- Cross-browser: Chrome, Firefox, Edge have had web push for years. Safari added it in 16.4 (desktop + iOS 16.4+) — requires the site to be installed as a PWA on iOS.

## Phases

### Phase 1 — Shell Caching + Load More SWR

**Effort: ~1.5–2 weeks**

- Workbox integrated into the build (ESBuild → `workbox-build injectManifest`)
- SW served at `/sw.js` with scope `/`
- Precache for admin static assets; runtime CacheFirst for public hashed assets
- SWR strategy for HTMX Load More endpoints
- `X-TCMS-Version` header emitted on listing and fragment responses
- Update flow: admin sees a "new version available, reload?" toast when the SW updates

**What done looks like:** clear the browser cache, load a T3 admin page once, disconnect wifi, reload — admin shell still loads from disk. Visit a public blog listing, click Load More, disconnect, reload listing, click Load More again — page 2 loads instantly from cache.

### Phase 2 — Admin Offline / Draft Queuing

**Effort: ~2–3 weeks**

- BackgroundSync queue for admin write endpoints
- IndexedDB-backed queue with replay logic
- Admin UI: pending-sync pill, queue inspector, conflict resolver
- Auth expiry handling in the replay path
- Tests covering disconnect → edit → reconnect → replay, and disconnect → edit → someone else edits → reconnect → conflict resolution

**What done looks like:** admin goes offline mid-edit, saves succeed locally with clear "queued for sync" feedback, admin keeps working, reconnection triggers automatic replay. Conflicts surface clearly instead of silently overwriting.

### Phase 3 — PWA Toggle for Public Sites

**Effort: ~1.5–2 weeks**

- Per-site PWA settings schema and admin UI
- `manifest.webmanifest` generation
- Icon upload + auto-resize (uses T3's existing `ImageWorks` system)
- Offline fallback page (configurable, defaults to a static HTML)
- Docs for Stacks users + Site Builder template authors

**What done looks like:** user enables PWA in admin, configures icons and theme, visits their public site in Chrome → "Install app" appears in the address bar. Installed site works offline with the fallback page.

### Phase 4 — Navigation Prefetch + Polish

**Effort: ~2–3 days**

- Workbox navigation preload for same-origin link hover / viewport entry
- Admin-specific prefetch of common next-page destinations (object edit from a listing, etc.)
- Metrics endpoint: SW reports cache hit rates to an admin dashboard tile so operators can see the SW earning its keep

### Phase 5 — Push Notifications

**Effort: ~2–3 weeks**

- VAPID key generation during install or via `tcms sw:init` CLI command
- `WebPushService` and subscription repository
- Subscription UI in admin profile
- Notification preferences (per event type, per collection)
- Event listener wiring (form submitted → notify; comment added → notify, etc.)
- Safari 16.4+ PWA-install path documented

## Effort Summary

| Phase | Effort | Cumulative |
|---|---|---|
| 1. Shell caching + Load More | 1.5–2 weeks | 2 weeks |
| 2. Admin offline / draft queuing | 2–3 weeks | 5 weeks |
| 3. PWA toggle | 1.5–2 weeks | 7 weeks |
| 4. Navigation prefetch + polish | 2–3 days | 7.5 weeks |
| 5. Push notifications | 2–3 weeks | 10.5 weeks |

**Total: ~9–12 weeks** of focused work for all five phases.

For 3.4: Phase 1 alone is a credible headline feature (~2 weeks).
For 3.5: Phases 1 + 2 together (~5 weeks) give a real "works offline, loads instantly" story.
Phases 3–5 can slot into subsequent minor releases as their value lands with customers.

## T3-Side Changes Summary

Explicit list of what T3 itself grows, so scope is clear:

- **Routing:** serve `/sw.js` with `Service-Worker-Allowed` header
- **Headers:** `X-TCMS-Version` (and optionally `ETag` / `Last-Modified`) on collection-derived responses
- **Build:** ESBuild pipeline extended with `workbox-build` post-step
- **Admin:** pending-sync UI, conflict resolver, SW update prompt, PWA settings page, push subscription/preferences UI
- **Services:** `WebPushService`, `SubscriptionRepository`, version-header middleware
- **CLI:** `tcms sw:init` (generate VAPID keys), `tcms sw:status`, `tcms sw:clear-cache`
- **Emergency cache clear:** `/emergency/cache/clear` endpoint gains a companion "clear service worker caches" signal (broadcast to connected clients)
- **Config:** `serviceWorker.enabled`, `serviceWorker.pwa.enabled` per-site, VAPID keys, notification defaults

## Interaction With Existing Caching

T3 already has APCu → Redis → Memcached → filesystem → OPcache → Twig cache layers. The SW adds a **client-side** layer in front of all of them, which changes the cache-clear story:

- "Clear cache" in the admin now has two paths: server caches (existing) and client SW cache (new).
- The broadcast update channel lets the admin signal all connected clients to purge their SW caches without a hard reload.
- The `/emergency/cache/clear` endpoint gains a client-wide reset version.
- Debugging "user reports stale content" now adds a layer; docs need a clear checklist.

This is the real cost of the SW, and why rushing past Phase 1 is a trap. The invariants must be nailed down before layering Phase 2's write-path behavior on top.

## Licensing / Editions

SW is **unlicensed** — ships to every T3 site regardless of edition. Reasons:

- Performance is a baseline quality concern, not a premium feature.
- Gating performance behind an edition signals "buy Pro or your site is slow," which is bad positioning.
- Push notifications is the one capability that could plausibly be Pro-gated if there's a desire to (requires server resources, subscription storage). Default recommendation: leave it free; push-at-scale has natural bounds.

## Open Questions

- **Admin / public SW sharing.** Current plan is one SW per origin handling both via route rules. Revisit if admin and public have fundamentally conflicting caching expectations — but early signal is they don't.
- **Stacks compatibility.** Stacks-rendered pages are served from T3 but generated outside the Twig engine. The public-site caching strategies all work the same on Stacks output (cacheable HTTP responses), so no special handling expected. Needs a real-site test.
- **Extension interaction.** The browser extension plan (`docs/planning/browser-extension.md`) runs alongside a SW fine — extension content scripts run in the host page's JS world, SW intercepts network. One caveat: SW cached responses might serve stale content to the extension's edit-map fetch. The instrumented render endpoints (`/admin/editor/*`) should be SW-bypassed via explicit route rules.
- **Update UX.** When a new SW version is available, do we hard-reload automatically (risks losing unsaved admin work), show a toast (user might ignore), or delay until the next natural navigation (invisible, delays improvements)? Leaning toward toast with a "reload when convenient" default, escalating to automatic if a critical SW update flag is set in the new version.
- **Collection version header granularity.** `X-TCMS-Version` per collection is the starting design. For pages that compose multiple collections (a homepage pulling from blog + portfolio + gallery), a composite header is needed. Probably a comma-separated list with the latest-modified of any contributor.
- **iOS Safari push adoption.** Push requires PWA install on iOS, which is a meaningful friction step. Worth tracking adoption data before over-investing in the iOS experience.
- **Non-HTTPS dev environments.** SW requires HTTPS (or `localhost`). T3 dev environments typically serve on `localhost` so this is fine, but any staging setups on HTTP must be documented.
- **PWA icon auto-generation source quality.** Users uploading tiny icons → SW generates blurry 512px versions. Need minimum-dimension validation.

## How It Fits the Roadmap

- **3.4 candidate:** Phase 1 alone. Clean, bounded, ~2 weeks, visible perceived-performance win across every T3 site. Good minor-release headline.
- **3.5 candidate:** Phases 1 + 2 together. Offline admin is a differentiator most flat-file CMSes do not have.
- **Later minors:** Phases 3–5 layered in as customer demand signals.
- **Extension plan complement:** The browser extension gives admins editing superpowers on live sites; the SW gives visitors and admins speed + resilience. Adjacent concerns, no conflicts, buildable independently.
- **Site Builder complement:** PWA toggle (Phase 3) is most valuable when paired with Site Builder's generated stubs — clients get an installable, offline-capable site out of the box.
- **Extensions architecture:** Third-party extensions can hook into the SW via the event system (push payloads, custom cache rules) once Phase 5 is in. Not a Phase 1 concern.

## What Done Looks Like

- A T3 site served over HTTPS registers a service worker automatically. Public-site shell assets are precached; subsequent navigation loads from disk.
- An admin working on a long form loses their connection; saves queue locally with visible "pending sync" feedback; reconnection replays the queue; any conflicts surface cleanly.
- A Site Builder or Stacks user flips "Enable PWA" in the admin, uploads an icon, and their visitors get an installable, offline-capable site.
- Admin users can opt in to push notifications and receive them for form submissions and other configured events — on Chrome, Firefox, Edge, and Safari (with PWA install on iOS).
- "Clear cache" in the admin clears both server-side and client-side SW caches with one action.
- No existing T3 site, Stacks-integrated site, or custom frontend is broken by the SW landing — opt-in everywhere it could affect public behavior.
