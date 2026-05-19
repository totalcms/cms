# Frontend Asset Pipeline — Feature Plan

**Status:** Planning (2026-05-04, revised 2026-05-04) — 3.6 candidate
**Related:** Extension System (shipped), Service Worker (`docs/planning/service-worker.md`), Frontend Build Integration (deferred — see "Future Evolution" below)

## Goal

Give T3 a single contract with site layouts for injecting CSS and JS into public pages. Today there are two unsolved problems:

1. **Core T3 frontend assets are hardcoded into Stacks templates.** 8 lines of `<link>` and `<script>` tags emitted from a Stacks plugin. Every time T3 ships a new core feature that needs assets (gallery lightbox, htmx, content.js), Joe-the-customer has to update their template. There's no way for T3 to add or change a core asset without coordinated template changes.
2. **Extensions have no way to ship frontend CSS/JS at all.** They can serve files via `/ext/{vendor}/{name}/assets/{file}`, but nothing collects or renders the corresponding `<link>` / `<script>` tags. Authors hand-roll tags into layouts or emit inline tags inside widget HTML.

Solve both with the same mechanism: **two generic Twig helpers (`cms.assetsHead()` / `cms.assetsBody()`) that emit every CSS and JS tag T3 needs for a given page.** Core T3 assets and extension assets flow through the same channel. Site authors put the helpers in their layout once and never touch tags again.

Ship the **simplest viable mechanism**: load everything on every public page, mirroring the admin asset pattern. Reserve the public API so future optimization (per-page queueing) is a non-breaking internal change.

## Non-goals (this round)

- **Per-page asset selection.** Every public page loads every enabled extension's frontend assets. We accept the bloat.
- **Two-pass / deferred rendering.** No buffering of `{% block content %}`, no `@push`/`@stack` semantics. Registration happens at boot, rendering is straight pass-through.
- **Build-time integration.** Extensions ship pre-compiled assets in their own `assets/` directory. No Vite manifest aggregation, no SCSS `@use` chain.
- **Inline critical CSS.** No size threshold auto-inlining. All assets render as `<link>` / `<script>` tags.
- **A redesigned admin permission UI.** The new `frontend:assets` capability auto-shows in the existing toggle list; we don't redo the screen.

## Vision

Site authors put two helpers in their layout once and forget about them:

```twig
<!DOCTYPE html>
<html>
  <head>
    ...
    {{ cms.assetsHead() }}
  </head>
  <body>
    ...
    {{ cms.assetsBody() }}
  </body>
</html>
```

Today's hardcoded Stacks block (8 lines) becomes those two helpers. Whatever T3 needs to inject — core CSS, htmx, gallery JS, extension widgets, future browser-extension bootstrap, future SW registration — flows through the same channel.

Extension authors register what they need from `register()` or `boot()`:

```php
$context->addFrontendAsset('css', 'widget.css');
$context->addFrontendAsset('js',  'widget.js', preload: true);
```

That's the whole user-facing API. Internals can change — caching, queueing, build aggregation — without site authors or extension authors touching a thing.

## Architecture

### Mirror the admin pattern

Admin assets work via:

1. `ExtensionContext::addAdminAsset(type, path)` collects entries into `$adminAssets`.
2. `ExtensionManager::getAllAdminAssets()` walks all contexts at boot, filters by the `admin:assets` permission, builds `/ext/{vendor}/{name}/assets/{path}` URLs, returns `['css' => [...], 'js' => [...]]`.
3. Result is published as a Twig global (`extensionAssets`) and to `TotalCMSTwigAdapter::setExtensionAssets()`.
4. `{{ cms.extensionAssets() }}` in `admin-dashboard.twig` renders the tags.

Frontend assets follow the same shape with three differences:

- Two buckets: `head` and `body`, each with `css` + `js` sub-buckets.
- New capability: `frontend:assets` (toggleable per-extension like the others).
- New helpers: `cms.assetsHead()` and `cms.assetsBody()` — generic names so core T3 features and future systems can route through the same channel.

### Generic helpers, not extension-specific

`cms.assetsHead()` / `cms.assetsBody()` are not "the extension asset helpers." They are **the T3 asset injection points**. Initially they emit only extension assets, but they are reserved as the contract between T3 and site layouts:

> "T3 puts what it needs to inject into the page through these two helpers. Put them in your layout and T3 handles the rest."

This means future features (browser-extension bootstrap, service worker registration, debug toolbar, conditional core feature CSS like the gallery lightbox) all have a stable injection point. We don't have to ask site authors to add another tag every time we ship a feature that needs to inject markup.

### Capability key: `frontend:assets`

Auto-detected by `ExtensionContext::getCapabilities()` — present if `addFrontendAsset()` was called at least once. Toggleable in the admin UI. Disabling the capability suppresses both `head` and `body` assets for that extension. Permission filtering happens in `ExtensionManager::getAllFrontendAssets()`, same place as the admin equivalent.

### Cache busting via file mtime

Use `?v={mtime}` instead of `?v={cms.version}` for cache busting:

- **Per-file granularity.** Changing `widget.css` doesn't bust `widget.js`. CMS version doesn't bust either unless the file actually changed.
- **No version-bump dance for extension authors.** Drop a new file in, the URL changes automatically.
- **Survives patch releases.** A 3.3.1 → 3.3.2 release that doesn't touch frontend code doesn't pointlessly invalidate every browser cache.
- **Cheap.** `filemtime()` is a single stat call; OPcache caches stat results. Compute once at boot when the asset is registered, store with the asset record, render is pure string concat.

Extensions or core can override with an explicit `version` option (e.g. for builds that ship hashed filenames already, or to force a manual bust): `addFrontendAsset('js', 'widget.js', version: 'manual-v3')`. If `version: null` (default), use mtime. If the file path already contains a content hash (heuristic: 8+ hex characters), skip the query string — defer this; YAGNI for now.

## Core T3 frontend assets

The current Stacks template hardcodes these. They get migrated into a `CoreFrontendAssetRegistrar` service that registers via the same internal pipeline (different bucket from extensions, but same renderer):

| Asset | Position | Type | Preload | Notes |
|---|---|---|---|---|
| `/assets/icons.css` | head | css | — | |
| `/assets/content.css` | head | css | — | |
| `/assets/cms-grid.css` | head | css | — | |
| `/assets/gallery.css` | head | css | — | |
| `/assets/pagination.css` | head | css | — | |
| `/assets/content.js` | body | js (module) | yes | |
| `/assets/gallery.js` | body | js (module) | yes | |
| `/assets/htmx.min.js` | body | js (module) | yes | Was classic script in Stacks; loaded as module now (htmx 2.x exposes `window.htmx` as side effect either way) |

`CoreFrontendAssetRegistrar` runs at container build, calling into the same registry the extension system uses. Result: `cms.assetsHead()` emits all 5 stylesheets + 3 preload hints, `cms.assetsBody()` emits 3 module scripts. Mtime-based cache busting applied to each.

**Why a registrar service rather than a hardcoded list in the adapter:** lets us refactor to config-driven (`config/tcms.php` `frontend.assets`) or per-feature opt-out (e.g., headless API consumers don't need gallery.css) without touching the helper code. The registrar is the pluggable seam.

**Stacks template migration:** Stacks ships an updated template that emits `{{ cms.assetsHead() }}` / `{{ cms.assetsBody() }}` instead of the 8 hardcoded tags. Customers who don't update their Stacks plugin keep the hardcoded tags working — the new helpers don't conflict with manual tags, just produce duplicate `<link>` / `<script>` (browsers dedupe download but not parse). A 3.3.x docs note: "delete the 8 hardcoded tags when you upgrade to use the helpers."

## API surface

### `ExtensionContext`

```php
/**
 * Register a CSS or JS asset to be loaded on public pages.
 *
 * Defaults: CSS → head, JS → body, JS modules → type="module", preload off,
 * cache busting via file mtime.
 *
 * @param string                                                          $type    'css' or 'js'
 * @param string                                                          $path    Path relative to the extension's assets/ directory
 * @param 'head'|'body'|null                                              $position Override default placement
 * @param bool                                                            $module  JS only; load as ES module (default true)
 * @param bool                                                            $preload Emit a paired <link rel="preload"> in head (default false)
 * @param string|null                                                     $version Override cache-busting query string (default: file mtime)
 */
public function addFrontendAsset(
    string $type,
    string $path,
    ?string $position = null,
    bool $module = true,
    bool $preload = false,
    ?string $version = null,
): void
```

Examples:

```php
// CSS in head, mtime cache bust
$context->addFrontendAsset('css', 'widget.css');

// JS module in body, with preload pair in head
$context->addFrontendAsset('js', 'widget.js', preload: true);

// Classic (non-module) JS — rare for extensions, supported anyway
$context->addFrontendAsset('js', 'legacy.js', module: false);

// Manual version override
$context->addFrontendAsset('css', 'widget.css', version: 'v3');
```

**Forward-compatible alias** (ship at the same time):

```php
/**
 * Register a frontend asset that the current page needs.
 *
 * Same signature and behavior as addFrontendAsset() in the current
 * implementation — all registered assets load on every page. A future
 * release may switch to per-page queueing, at which point this method's
 * semantics ("only load when something on the page actually requires it")
 * will be honored. Extensions that want to stay forward-compatible should
 * prefer this call from inside Twig functions / widget render paths.
 */
public function requireAsset(
    string $type,
    string $path,
    ?string $position = null,
    bool $module = true,
    bool $preload = false,
    ?string $version = null,
): void
```

Internally both push onto the same `$frontendAssets` list. The distinction matters later, not now — but having `requireAsset()` in the API today means extension authors writing widgets in 2026 can write code that auto-benefits from per-page mode if/when we ship it, with no migration.

### Internal asset record

```php
/**
 * @phpstan-type FrontendAsset array{
 *   type:     'css'|'js',
 *   url:      string,        // absolute URL with ?v= cache bust applied
 *   position: 'head'|'body',
 *   module:   bool,
 *   preload:  bool,
 * }
 */
```

URLs are resolved at boot — query string with file mtime baked in. Render is pure string concat.

### `ExtensionManager`

```php
/** @return list<FrontendAsset> */
public function getAllFrontendAssets(): array
```

Filtered by `frontend:assets` capability permission. URLs built like admin assets but with mtime cache busting.

### `TotalCMSTwigAdapter`

```php
/**
 * Register frontend assets (called by ExtensionManager + CoreFrontendAssetRegistrar
 * during boot). Multiple calls accumulate.
 *
 * @param list<FrontendAsset> $assets
 */
public function addFrontendAssets(array $assets): void;

public function assetsHead(): string;  // {{ cms.assetsHead() }}
public function assetsBody(): string;  // {{ cms.assetsBody() }}
```

Render output:

```html
<!-- assetsHead() -->
<link rel="stylesheet" href="/assets/icons.css?v=1714839832">
<link rel="stylesheet" href="/assets/content.css?v=1714839832">
<link rel="stylesheet" href="/ext/vendor/name/assets/widget.css?v=1714900000">
<link rel="preload" as="script" href="/assets/content.js?v=1714839832">
<link rel="preload" as="script" href="/assets/htmx.min.js?v=1714839832">
<link rel="preload" as="script" href="/ext/vendor/name/assets/widget.js?v=1714900000">

<!-- assetsBody() -->
<script type="module" src="/assets/content.js?v=1714839832"></script>
<script type="module" src="/assets/htmx.min.js?v=1714839832"></script>
<script type="module" src="/ext/vendor/name/assets/widget.js?v=1714900000"></script>
```

Render order within each helper:
1. `assetsHead()`: stylesheets, then preload hints, then any (rare) head scripts
2. `assetsBody()`: any (rare) body stylesheets, then scripts

Within each subgroup: registration order, with core assets registered before extensions (so core CSS loads first and extensions can override).

## Implementation steps

1. **`ExtensionContext`**
   - Add `private array $frontendAssets = []` with shape `list<array{type, path, position, module, preload, version}>`.
   - Add `addFrontendAsset()` and `requireAsset()` (the latter delegating to the former) with the full signature.
   - Add `getRegisteredFrontendAssets(): array` getter.
   - Add `frontend:assets` to `capabilityLabels()`.
   - Update `getCapabilities()` to detect non-empty `$frontendAssets`.

2. **`CoreFrontendAssetRegistrar`** (new)
   - New service in `src/Domain/Twig/Service/` (or similar) that owns the hardcoded list of core T3 frontend assets (the 8 from the table above).
   - Method `register(TotalCMSTwigAdapter $adapter): void` — resolves each asset's mtime and pushes a `FrontendAsset` record to the adapter via `addFrontendAssets()`.
   - Called during container build before extensions boot, so core assets render first.

3. **`ExtensionManager`**
   - Add `getAllFrontendAssets()` — returns `list<FrontendAsset>` filtered by capability permission.
   - URL builder resolves asset path → `/ext/{vendor}/{name}/assets/{path}` and appends `?v={mtime|version}`.
   - In `bootAll()`, call it after admin asset collection and push to the adapter via `addFrontendAssets()` (note plural — additive).

4. **`TotalCMSTwigAdapter`**
   - Add `private array $frontendAssets = []` field.
   - Add `addFrontendAssets()` (additive), `assetsHead()`, `assetsBody()` methods.
   - Render logic: filter by position + ordering rule, emit `<link>` / `<script>` with `htmlspecialchars()` on URLs, prefixed with `$this->api`.
   - `<script>` tags get `type="module"` when `module: true`.
   - Preload pairing: when an asset has `preload: true`, emit `<link rel="preload" as="script">` (or `as="style"` if CSS, though that's less useful) in head regardless of the asset's own position.

5. **Admin extension-management UI**
   - The `frontend:assets` capability auto-shows in the toggle list; no UI work needed.

6. **Stacks template update**
   - Update the Stacks template to emit `{{ cms.assetsHead() }}` / `{{ cms.assetsBody() }}` instead of the 8 hardcoded tags.
   - Keep the hardcoded tags working for sites on older Stacks plugins (no conflict, just duplication).

7. **Documentation**
   - Update `resources/docs/extensions.md` with the new methods.
   - Update extension-starter repo (`totalcms/extension-starter`) with a frontend asset example.
   - Note in upgrade guide: existing layouts won't break (they don't call the new helpers); to opt in, site author adds `{{ cms.assetsHead() }}` and `{{ cms.assetsBody() }}` to their layout and removes the old hardcoded block.

8. **Tests**
   - Unit: `ExtensionContext` registration, capability detection, `requireAsset()` aliasing, options handling.
   - Unit: `ExtensionManager::getAllFrontendAssets()` permission filtering, URL + mtime building.
   - Unit: `CoreFrontendAssetRegistrar` registers all 8 core assets with correct properties.
   - Unit: `TotalCMSTwigAdapter::assetsHead()` / `assetsBody()` render order, preload pairing, module/classic distinction.
   - Integration: extension fixture that registers head + body + preload assets, verify rendered HTML in a public-template render.

## Admin asset symmetry

Take the same pass through the admin side. Today admin uses a single helper `{{ cms.extensionAssets() }}` (in `admin-dashboard.twig:54`) that lumps all CSS + JS into one location. Now that we're defining the head/body pattern for public, mirror it in admin.

### New admin helpers

```twig
{# in admin-dashboard.twig <head> #}
{{ cms.adminAssetsHead() }}

{# in admin-dashboard.twig before </body> #}
{{ cms.adminAssetsBody() }}
```

Both helpers cover **admin-specific assets only** — admin extension assets, plus any future core admin assets we want to flow through the same channel. Admin and public stay strictly separate; no cross-contamination.

### `addAdminAsset()` grows to match `addFrontendAsset()`

```php
public function addAdminAsset(
    string $type,
    string $path,
    ?string $position = null,
    bool $module = true,
    bool $preload = false,
    ?string $version = null,
): void
```

Same options as the frontend variant. Admin extensions get the same `preload`, `module`, and head/body control. Mtime cache busting applied uniformly.

### Backwards compatibility

`cms.extensionAssets()` keeps working — implemented as `assetsHead() . assetsBody()` for the duration of 3.3.x. Mark it `@deprecated` in the PHPDoc and document the migration in the upgrade guide. Drop it in 3.4.

`admin-dashboard.twig` updated to call the two new helpers in the right places. Existing extensions that called `addAdminAsset(string, string)` (the two-arg form) keep working — the new arguments are all optional.

### Implementation work

- `ExtensionContext::addAdminAsset()` — extend signature, update internal record shape to match `FrontendAsset`.
- `ExtensionManager::getAllAdminAssets()` — return `list<AdminAsset>` with the same shape, mtime baked in.
- `TotalCMSTwigAdapter` — add `adminAssetsHead()` / `adminAssetsBody()`; keep `extensionAssets()` as deprecated alias.
- `admin-dashboard.twig` — split the single helper call into head and body.

This is a small additional scope — maybe 30% extra work given the renderer code is shared between admin and frontend buckets. Worth doing in the same release for consistency.

## Future evolution

**Per-page queueing (double-pass).** The `requireAsset()` call site is the hook. Implementation switches to: (1) buffer `{% block content %}` to a string, (2) collect `requireAsset()` calls during that render, (3) render the layout with the populated bucket. `cms.assetsHead()` / `cms.assetsBody()` keep the same signature. Triggered by real-world data showing first-load weight is hurting sites.

**Frontend build integration.** Extensions opt in by declaring source entry points in their manifest. `tcms` writes an aggregated manifest to `tcms-data/.system/extension-assets.json`; the site's Vite config reads it and adds the entries to `rollupOptions.input`. Output replaces the per-extension asset URLs in `getAllFrontendAssets()`. Headless / no-build sites fall back to the runtime path automatically. Useful but not urgent — most extensions ship pre-compiled assets via Composer anyway.

**Service worker integration.** Once SW lands (3.4/3.5 per `docs/planning/service-worker.md`), Workbox precaches `/ext/*/assets/*` automatically via the existing route pattern. No change to this plan needed — the asset URLs we generate are already SW-cacheable. See "Where the SW helps" discussion: repeat-visit cost approaches zero, first-visit cost is unchanged.

**Inline `<style>` / `<script>` registration.** A `requireInlineStyle()` / `requireInlineScript()` pair would let widgets emit dynamic per-instance styles. Defer until there's a real use case — most widgets want shared rules, not per-instance ones, and shared rules belong in a static file.

**Priority hints.** `priority: int` on `addFrontendAsset()` for fine-grained ordering within a bucket. Defer; registration order is good enough for now.

## Open questions

1. **What happens if a site author doesn't add the helpers to their layout?**
   Extension assets silently don't load, **and now core T3 assets also silently don't load** — that's a regression for any site that hasn't updated its Stacks plugin. Mitigations:
   - Stacks plugin update ships alongside this feature.
   - Old Stacks templates keep their hardcoded tags; they get duplicate (not broken) loads if they also somehow opt in. No-op risk.
   - Admin notice ("N pages rendered today emitted no asset helpers — your layout may be missing `{{ cms.assetsHead() }}`") as a 3.3.x follow-up.

2. **Should the helpers accept arguments to filter by extension or position-within-position?**
   No. Keep them argumentless. If a site author needs more control they can opt out at the registrar level (e.g., disable a core feature's assets via config) — not at the helper call site.

3. **Should there be a way for headless / API-only sites to disable core asset registration?**
   Worth doing eventually — `config/tcms.php` `frontend.coreAssets` array (or `null` to disable). Defer to 3.3.x. Today, headless consumers don't call the helpers anyway, so they pay no rendering cost. They only pay the boot-time mtime stat cost.

4. **htmx as module — any breakage risk?**
   htmx 2.x exposes `window.htmx` as a side effect even when loaded as a module, so existing inline `htmx.foo()` calls keep working. Audit T3 internal JS to confirm it imports htmx rather than relying purely on the global, then ship as module. Low risk; flag as a smoke-test item during integration testing.

5. **What's the URL prefix story under reverse proxies / subdirectory installs?**
   `TotalCMSTwigAdapter` already prefixes admin asset URLs with `$this->api`. Frontend uses the same prefix. Verified working pattern.

## Acceptance criteria

- A public page rendering a layout that calls `{{ cms.assetsHead() }}` and `{{ cms.assetsBody() }}` emits all 8 core T3 assets (5 stylesheets, 3 preload hints, 3 module scripts) plus any extension-registered assets.
- Cache-busting `?v={mtime}` query strings are present on every asset URL and update automatically when the underlying file changes.
- An extension can register CSS and JS for head or body via `addFrontendAsset()`, with optional `preload: true` and `module: false`.
- Disabling the `frontend:assets` capability for an extension via the admin UI suppresses its tags on the public site without restart.
- htmx loads as a module without breaking existing T3 HTMX integrations.
- Existing Stacks layouts (with the 8 hardcoded tags) continue to work; nothing breaks for sites that don't update their Stacks plugin.
- `requireAsset()` is callable today and produces the same result as `addFrontendAsset()`. Extensions written against it remain valid through the future per-page transition.
- PHPStan Level 8 clean.
