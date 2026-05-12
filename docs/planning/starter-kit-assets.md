# Starter Kit Assets (CSS + JS)

**Status:** Planning
**Target:** 3.4 (post-3.3 ship)
**Owner:** Joe

## Problem

Today's starter "templates" (`tcms builder:init <name>`) ship layouts with **inline `<style>` blocks** and **inline `style="..."` attributes** in partials. The docs even acknowledge this is intentional — "T3 does not own your CSS build pipeline" — and tell users to replace it themselves.

That's a poor first impression. No serious user is going to ship a site with `<style>` tags in `<head>` and inline styles on every nav link. The first thing they'll do is rip it out and start over, which means the starter wasn't really a starter — it was a throwaway.

We also want to start using the term **"starter kit"** instead of "starter" / "starter pack" — it's clearer about what the thing actually is (a complete kit you scaffold from), and matches Laravel/Next/etc.

## Starter kit vs. theme

These two ideas get conflated, so worth pinning down before we design anything. They are different abstractions and we should be careful not to drift one into the other.

| | **Starter kit** | **Theme** |
|---|---|---|
| Lifecycle | One-shot scaffold. `builder:init` copies files; the source is then forgotten. | Persistent. Stays installed and applied; can be updated, swapped, layered. |
| Ownership of output | User owns every file after scaffolding. Editing the source kit has no effect on existing sites. | Theme owns its files. User customizes via overrides, hooks, or config — not by editing the theme directly. |
| Scope | Defines **structure**: pages, routes, page templates, layouts, partials, schemas, plus visual scaffolding. | Defines **presentation only**: CSS, class conventions, sometimes layout fragments. Doesn't dictate which pages exist. |
| Update story | None. The kit is consumed and gone. Future kit improvements don't reach existing sites. | Has one. New theme version → user updates → site re-renders with new styles. |
| Swap story | None. You don't "switch starter kits" on a live site — you'd start over. | Core feature. Switch theme → site looks different, content untouched. |
| Closest analogy | `create-react-app`, Laravel starter kits, `composer create-project` templates. | WordPress themes, Stacks themes, Hugo themes, Ghost themes. |

**This plan is about starter kits, not themes.** The CSS assets we ship live inside the kit and get copied into the user's docroot at scaffold time. After that, the user owns them outright. There's no theme registry, no theme-switching command, no expectation that updating a kit updates anyone's site.

A real theme abstraction (swappable presentation layer over stable content + structure) would be a **separate primitive** and is listed under "Future enhancements" below. It's worth doing eventually if customers ask for skinning rather than scaffolding, but conflating it with starter kits would muddle both. Keep them distinct.

## Goals

1. Starter kits ship **real, external CSS files** with CSS custom properties (variables) for theming
2. Optional **vanilla JS** support (ES modules, no bundler required)
3. **No build step** — files copy as-is to docroot, work immediately when served
4. **Extensions can ship starter kits** the same way they ship Twig functions, CLI commands, etc.
5. Rename surface terminology to "starter kit" without churning all internal class names

## Non-goals

- Tailwind / SCSS / Vite scaffolding — out of scope for v1. Anyone wanting that can either delete `assets/` after scaffolding or we add a separate `tailwind` / `vite` kit later.
- A swappable "theme" abstraction (apply/swap visual layer over stable content). Kits stay one-shot scaffolds; theming happens by editing the CSS files the kit drops.
- Asset versioning / cache busting. Hand-rolled `?v=1` is fine for vanilla CSS sites.

## Design

### Source layout

Each kit in `resources/builder/starters/{kit}/` gains an `assets/` directory:

```
business/
  manifest.json
  layouts/
    default.twig
  pages/
    index.twig  about.twig  services.twig  contact.twig
  partials/
    nav.twig  footer.twig
  assets/
    css/
      variables.css   # design tokens — colors, spacing, type scale, radii
      base.css        # reset, body, typography, .container utility
      components.css  # nav, footer, .card, .grid-2, .section, .hero
    js/
      main.js         # optional — most kits won't have one
```

Three small CSS files per kit (target: under ~200 lines total). Splitting them this way means:
- `variables.css` is the obvious place to retheme — a user changes colors/fonts in one file
- `base.css` is rarely touched
- `components.css` is where styling for the kit's specific patterns lives

### Destination

`assets/` is copied verbatim to `{docroot}/assets/`. The layout references files with absolute paths:

```twig
<link rel="stylesheet" href="/assets/css/variables.css">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/components.css">
{# optional #}
<script type="module" src="/assets/js/main.js" defer></script>
```

Existing assets at the destination are left alone unless `--force`. Kits should not put files at top-level `assets/` — only inside `assets/css/`, `assets/js/`, `assets/img/` — to minimize collision risk if a user runs multiple kits or layers their own assets.

### Manifest changes

No required changes — `assets/` is detected by convention. Optionally a kit can declare an `assets` block to override defaults:

```json
{
  "name": "Business",
  "description": "...",
  "version": "1.1.0",
  "pages": [...],
  "assets": {
    "destination": "assets",
    "overwrite": false
  }
}
```

For v1 we should ship **convention-only** (no `assets` block in manifest) and add config later if real needs surface. YAGNI.

### CSS conventions

Each kit's `variables.css` defines tokens at `:root`:

```css
:root {
    --color-text: #1a1a1a;
    --color-muted: #555;
    --color-bg: #fff;
    --color-bg-alt: #f8f9fa;
    --color-accent: #2563eb;
    --color-border: #e5e7eb;

    --font-body: system-ui, -apple-system, sans-serif;
    --font-heading: var(--font-body);

    --container-max: 1080px;
    --space-section: 4rem;
    --radius: 8px;
}
```

`base.css` and `components.css` reference only those variables — never hardcoded colors. This is the single contract: **change variables.css, restyle the whole site**. (Mirrors what the T3 admin already does internally with `oklch(var(--totalform-*))` — note that admin uses `oklch()`, but starter kits target plain web users so we use simple hex/rgb values for v1.)

### Layouts and partials

Replace inline styles with class names. The current `business/partials/nav.twig`:

```html
<nav style="background: #fff; border-bottom: 1px solid #e5e7eb; padding: 1rem 0;">
    <div class="container" style="display: flex; justify-content: space-between; align-items: center;">
        <a href="/" style="font-weight: 700; ...">...</a>
```

becomes:

```html
<nav class="site-nav">
    <div class="container site-nav__inner">
        <a href="/" class="site-nav__brand">...</a>
```

with the styling moved to `components.css`. BEM-ish names, no framework conventions.

## Implementation

### 1. Asset copy step in `StarterService`

`src/Domain/Builder/Service/StarterService.php` — `copyTemplateFiles()` currently copies `layouts`, `pages`, `partials`, `macros` via `TemplateMigrationService`. Add a sibling step:

```php
private function copyAssets(string $starterDir, bool $force): int
{
    $assetsSource = $starterDir . '/assets';
    if (!is_dir($assetsSource)) {
        return 0;
    }
    $destination = $this->builderConfig->getDocroot() . '/assets';
    return $this->recursiveCopy($assetsSource, $destination, $force);
}
```

Need a recursive copy helper that:
- Creates destination directories as needed
- Skips files that exist unless `$force`
- Returns count of files copied
- Logs each skip/copy at debug level

Likely a new utility class `AssetCopier` in `src/Domain/Builder/Service/` (or extend `TemplateMigrationService` if patterns line up).

### 2. Update existing four kits

For each of `minimal`, `business`, `blog`, `portfolio`:
- Extract inline `<style>` from `layouts/default.twig` into `assets/css/{variables,base,components}.css`
- Extract `style="..."` from partials into class names + rules in `components.css`
- Add `<link>` tags to layout
- Bump kit version in `manifest.json`

Each kit gets its own three CSS files (no shared `_base/` directory — easier for users to mod, and they're tiny). The kits will have similar `variables.css` and `base.css` with kit-specific values; `components.css` diverges most.

### 3. Extension-shipped starter kits

Extensions already register Twig functions, CLI commands, routes, schemas, etc. via `ExtensionContext`. Add starter kit registration:

```php
// In an extension's Extension.php boot/register method:
$context->registerStarterKit('saas-app', __DIR__ . '/starters/saas-app');
```

Implementation:

- New method `ExtensionContext::registerStarterKit(string $name, string $directory)`
- `ExtensionState` stores per-extension list of `[name => absolutePath]`
- `StarterService::listStarters()` aggregates: core kits first, then extension kits
- Extension kits are namespaced as `{vendor}/{name}` to avoid collision with core kit names. CLI:

  ```
  $ tcms builder:init --list
  Available starter kits:

    Core:
      minimal     A minimal starting point...
      business    Professional business website...
      blog        Blog-focused site with posts...
      portfolio   Portfolio site with project grid...

    Extensions:
      acme/saas       SaaS marketing site (acme/starter)
      foo/landing     Single-page landing kit (foo/landing)

  $ tcms builder:init acme/saas
  ```

- Capability detection: after `register()` runs, `ExtensionDiscovery` notes "starter-kits" as a capability if any were registered. Becomes a toggleable permission like all other extension capabilities.
- Fault isolation: if an extension's manifest is invalid or kit directory is missing, log + skip — don't break `builder:init --list`.

### 4. Terminology pass

We don't rename internal classes (`StarterService`, `StarterManifest`, `BuilderInitCommand`) — too much churn for marginal value, and "starter" is correct as the noun root.

We **do** update user-facing surfaces:
- `BuilderInitCommand` description: "Initialize a Site Builder project from a starter kit"
- `--list` output header: "Available starter kits"
- `resources/docs/builder/starters.md` — title, body, examples updated to "starter kit"
- `MEMORY.md` and any agent-facing docs — same
- File can stay at `starters.md` (URL stability) but update the H1

Tests reference class names, so no test churn from this pass.

### 5. Docs update

`resources/docs/builder/starters.md` needs:
- Rename to "Starter Kits" in the H1 and prose
- New section explaining the asset structure (`assets/css/`, `assets/js/`)
- Example of editing `variables.css` to retheme
- Section on extension-shipped kits, with `tcms builder:init vendor/name` example
- "Creating Custom Starter Kits" section gets the full source layout (including `assets/`)

## Files to touch

**New:**
- `docs/planning/starter-kit-assets.md` (this file)
- `src/Domain/Builder/Service/AssetCopier.php` (or method on existing service)
- For each kit: `assets/css/variables.css`, `assets/css/base.css`, `assets/css/components.css`

**Modified:**
- `src/Domain/Builder/Service/StarterService.php` — add asset copy step
- `src/Domain/Extension/ExtensionContext.php` — `registerStarterKit()`
- `src/Domain/Extension/ExtensionState.php` — track registered kits
- `src/Domain/Extension/ExtensionDiscovery.php` — detect starter-kits capability
- `src/CLI/Command/BuilderInitCommand.php` — update help text, support namespaced names
- All four `resources/builder/starters/*/layouts/default.twig` — strip inline styles, add `<link>`
- All four `resources/builder/starters/*/partials/*.twig` — strip inline styles, add classes
- All four `resources/builder/starters/*/manifest.json` — bump version
- `resources/docs/builder/starters.md` — terminology + assets section
- `tests/fixtures/extensions/` — add a fixture extension that registers a starter kit

**Tests:**
- `tests/Feature/Builder/StarterServiceTest.php` — assert assets copy, idempotency under `--force`
- `tests/Feature/Builder/ExtensionStarterKitTest.php` — extension can register, kit shows in list, scaffold works

## Risks / open questions

- **Docroot ambiguity** — `BuilderConfigService::getDocroot()` returns the configured docroot, which may not be writable in all installs. Need to check failure mode (currently template copying writes to `tcms-data/`, which is always writable). If docroot is read-only, fail with a clear message rather than half-copying.
- **Asset collisions across multiple `builder:init` runs** — running two kits sequentially could overwrite. The existing `--force` flag already gates this for templates; same gate applies to assets.
- **Extension uninstall** — when an extension that ships a kit is removed, the kit disappears from `--list`, but any sites already scaffolded from it are unaffected (files are owned by the docroot at that point). This is correct behavior; document it.
- **Per-kit `assets/img/`** — out of scope for v1. If a kit wants images, it bundles them in `assets/img/` and the convention copy will pick them up naturally. No special handling needed.

## Future enhancements (not v1)

- Tailwind starter kit (`tcms builder:init tailwind`) — ships a Tailwind config + `assets/css/input.css` + npm scripts in the docroot
- Vite starter kit — same idea, with a `vite.config.js` and proper asset manifests
- "Theme" concept as a separate primitive — extension type that ships only CSS + class conventions, applied/swappable over an existing site (different problem, different abstraction)
- `tcms builder:retheme <kit>` — copy just the `assets/css/variables.css` from another kit on top of an existing scaffolded site, for quick visual experimentation
