# T3 Internationalization (i18n) — Feature Plan

**Status:** Planning (2026-05-02) — split scope: **3.3 sliver** (localized field types only) + **3.4 full system** (routing, admin tabs, SEO, CLI, migration)
**Supersedes:** `docs/planning/6-brief-internationalization.md`
**Related:** Site Builder plan (`docs/planning/5-brief-builder.md`), Service Worker plan (`docs/planning/service-worker.md`), MCP server plan (`docs/planning/mcp-server.md`)

## Goal

Give T3 native multi-language support through a new family of locale-aware field types, locale-aware routing, and a small set of supporting helpers (formatters, static-string translation, SEO partials). The current `title_en` / `title_de` workaround works but doesn't scale — it pollutes schemas, breaks templates, and has no story for SEO, slugs, or admin UX. Native i18n makes T3 credible for international client work and opens a market segment underserved by flat-file CMS platforms.

The work splits into two releases:

- **3.3 sliver** — just the `localizedtext` and `localizedstyledtext` field types, with a deliberately minimal admin UI and Twig API designed to be forward-compatible with the full system. Customers building multi-language sites *today* (using the `field_en`/`field_de` workaround) get a cleaner storage shape they can adopt without waiting for the full release.
- **3.4 full system** — locale routing, admin locale tabs, SEO helpers, CLI tools, migration command, slug localization, server-side cache key isolation, and the rest of the stack.

## Release Scope: 3.3 Sliver vs 3.4 Full System

### 3.3 Sliver (this release)

Ships only what's needed to give customers cleaner content storage today, without forcing the full i18n stack.

| In | Out |
|---|---|
| `localizedtext` field type | Locale-aware URL routing (`/de/about`) |
| `localizedstyledtext` field type (Tiptap) | Active locale resolution / `LocaleContext` middleware |
| Site-wide `cms.config('locales')` | Slug localization |
| `cms.localizedtext('field', locale)` Twig helper (locale required) | Admin locale tabs |
| `cms.localizedstyledtext('field', locale)` Twig helper | `cms.t()` static strings |
| Per-field `defaultLocale` fallback | Locale-aware formatters |
| Admin form: labeled-inputs-per-locale UI | SEO helpers (hreflang, og:locale, locale-aware sitemap) |
| BCP 47 codes documented as the standard | REST API `?locale=` query parameter |
| REST API serializes localized fields as `{ "en-US": "...", "de": "..." }` always | CLI commands (status, missing, export, import, migrate) |
| | Per-locale Twig template overrides |
| | Server-side cache key isolation (no active locale yet, so not needed) |

**Effort: ~1.5 weeks.** Slots into 3.3 alongside MCP work without disrupting the platform-release narrative.

**Marketing framing:** "T3 has localized field types now — full i18n routing and tooling lands in 3.4." Don't call this "T3 has i18n now" — it's deliberately a sliver.

### 3.4 Full System

Everything in the architecture sections below that isn't in the sliver. Phased as Phases 1–5 in the Phases section (the sliver is Phase 0).

## Forward-Compatibility Contract

These decisions are locked in 3.3 because changing them in 3.4 would be a migration headache:

1. **Storage format is BCP 47 keyed.** `{ "en-US": "About Us", "de": "Über uns" }`. Bare language codes (`de`, `fr`) are valid as BCP 47 with no region. No other keying schemes (no `en_US`, no numeric IDs, no array-of-objects).
2. **Twig accessor signature is final.** `cms.localizedtext('field', locale)` and `cms.localizedstyledtext('field', locale)`. In 3.3 the `locale` argument is required; in 3.4 it becomes optional and auto-resolves to the active locale. Same call shape, expanded behavior — no template rewrites.
3. **REST API serialization shape stays.** Localized fields always serialize as the full `{ "en-US": ..., "de": ... }` object in 3.3. In 3.4, the default behavior shifts to "return the resolved string for the active locale" with `?expand=locales` for the full object — but the full-object shape stays available exactly as today. No breaking shape change for clients that opt into `?expand=locales`.
4. **Locales config lives at the site level in 3.3, with a future per-collection override in 3.4.** `cms.config('locales')` returns the site list. When 3.4 adds per-collection locale config, the site-wide setting becomes the default fallback. No data migration.
5. **No `localizedslug` in 3.3.** Slug localization needs `/de/` URL routing to be useful. Shipping a slug field without anywhere to use it would constrain 3.4's design.
6. **Admin form UI stays deliberately minimal.** Labeled inputs per locale, not tabs. Tabs ship in 3.4 as part of the full admin UX.

## Non-goals

- Translation memory, glossary, or built-in machine translation. Export/import (Phase 5) is the seam — third-party tools (DeepL, GPT, human translators) plug in there.
- Domain-pattern URL routing (`de.example.com`) for v1. Prefix routing (`/de/`) only; domain pattern documented as future work.
- Per-page locale availability toggles in Site Builder. That's a Site Builder feature, layered on top of i18n once it lands.
- Localizing collection-level metadata (collection names, descriptions). Collections stay language-neutral.
- Real-time collaborative translation editing.
- Built-in CDN / edge routing for locale-specific hosting.

## Architecture

### Locale Configuration

In 3.3, locales live in a single site-wide config:

```php
// config/tcms.php
return [
    'locales' => [
        ['code' => 'en-US', 'label' => 'English (US)', 'dir' => 'ltr'],
        ['code' => 'de', 'label' => 'Deutsch', 'dir' => 'ltr'],
        ['code' => 'ar', 'label' => 'العربية', 'dir' => 'rtl'],
    ],
    'defaultLocale' => 'en-US',
];
```

Accessible via `cms.config('locales')`.

In 3.4, schemas can override site-wide config per collection:

```json
{
    "locales": [
        { "code": "en-US", "label": "English (US)", "dir": "ltr" },
        { "code": "de", "label": "Deutsch", "dir": "ltr" }
    ],
    "defaultLocale": "en-US",
    "fallbackLocale": "en-US"
}
```

Locale codes follow **BCP 47** (`en-US`, `pt-BR`, `zh-Hans`). Bare language codes (`de`, `fr`) remain valid — they're just BCP 47 with no region.

The `dir` field (`ltr` / `rtl`) is required so RTL languages render correctly without a separate locale-to-direction lookup table.

### Field Types

| Type | Storage | Notes | Ships in |
|---|---|---|---|
| `localizedtext` | `{ "en-US": "About Us", "de": "Über uns" }` | Plain text, locale-keyed | **3.3** |
| `localizedstyledtext` | Same shape, value is rich HTML | Tiptap with locale-aware spell check + RTL toggle | **3.3** (basic Tiptap; spell check and RTL toggling come in 3.4) |
| `localizedslug` | Same shape | Per-locale slug index, used by locale-aware routing | 3.4 |
| `localizedimage` | Future | Different image per locale (e.g., localized screenshots) | 3.5+ |
| `localizedfile` | Future | Different file per locale (e.g., language-specific PDFs) | 3.5+ |

A standard `text` field on a localized object is completely untouched. Editors choose per-field whether something is locale-aware.

### Active Locale Resolution (3.4)

Resolution order, highest priority first:

1. **URL prefix** — `/de/about` sets locale to `de`
2. **`X-Locale` header** — for API clients
3. **`?locale=` query parameter** — for API and explicit overrides
4. **User preference cookie** — `tcms_locale`, set when a user clicks the locale switcher
5. **`Accept-Language` header** — parsed and matched against site's configured locales
6. **Site default locale**

For unprefixed URLs (`/about` instead of `/en/about`), per-site config decides:

```json
{
    "i18n": {
        "unprefixedDefaultLocale": "redirect"
    }
}
```

- `redirect` — `/about` → `301 /en/about` (clean URL canonicalization, recommended)
- `serve` — both `/about` and `/en/about` work (backwards-compat for existing single-language sites that just added a second language)

### URL Routing (3.4)

Prefix pattern only for v1:

```
/en-us/about
/de/about
/ar/about
```

T3 sets the active locale from the URL prefix in middleware before any template rendering. Locale prefix is stripped from the path before route resolution, so existing routes don't need to know about locales.

Site Builder picks up the active locale automatically through the request-scoped `LocaleContext` service.

### Slug Localization (3.4)

`localizedslug` stores per-locale slug values. Object lookup goes through a locale-aware index:

```
tcms-data/{collection}/.slugs/{locale}.json
```

Each file maps slug → object ID for that locale. The router uses the active locale's slug index to resolve `/de/blog/mein-beitrag` → object ID, locale-scoped.

Slug uniqueness is enforced **per-locale**, not globally — `/de/about` and `/en/about` can both exist as the same object's two slugs.

Backward-compatible: if an object's localized slug for the active locale is missing, fall back to the default-locale slug.

### Server-Side Cache Keys (3.4)

APCu, Twig cache, and any HTTP middleware caches **must** include the active locale in their cache keys once active locale resolution exists. Without this, the first visitor of any locale poisons the cache for everyone else.

Touched components:
- `TwigCache` — extend cache key to include locale
- APCu cache adapters — locale prefix on keys for any localized-collection responses
- `CmsGrid` and Load More fragment caching — locale in cache key
- HTTP response cache headers — `Vary: X-Locale, Cookie` on localized responses

This is correctness, not performance. Worth a dedicated test suite.

In 3.3 there's no active locale to vary on, so no cache changes needed.

### Fallback Behavior

If a localized field has no value for the active locale, fall back to the field's `defaultLocale` (or in 3.4, the collection's `fallbackLocale`). If that's also missing, return empty string.

A separate helper exposes whether the value was a fallback (3.4):

```twig
{{ cms.localizedtext('title') }}
{% if cms.isFallback('title') %}
    <span class="translation-pending">Translation in progress</span>
{% endif %}
```

In 3.3, `cms.localizedtext('title', 'de')` returns the German value, or the field's `defaultLocale` if German isn't set, or empty string.

## Admin Interface

### 3.3 Sliver: Labeled Inputs

For each localized field, the admin form renders one input per configured locale, with the locale label visible:

```
Title
  English (US)  [About Us                        ]
  Deutsch       [Über uns                        ]
  العربية       [                                ]  (RTL-aware text direction)
```

Plain, honest, no half-built tabs. Customer sees exactly what the field stores.

### 3.4 Full System: Locale Tabs

For objects in a locale-enabled collection, the editing form shows a locale tab row scoped only to localized field types:

```
[ English (US) ] [ Deutsch ] [ العربية ]
```

Non-localized fields appear below the tabs with no locale UI. Untranslated localized fields are visually flagged with a "Not yet translated" indicator. Saving writes only the active locale's values.

### Translation Status Per Object (3.4)

Object listing pages show a per-locale translation indicator:

```
my-post     [EN ✓ DE ✓ AR ✗]
about       [EN ✓ DE ✗ AR ✗]
```

Filter and sort by translation completeness.

### Tiptap Locale Awareness (3.4)

When editing `localizedstyledtext`:
- Spell-check language matches the active locale tab
- Editor `dir` attribute switches when the active locale is RTL
- Toolbar layout mirrors for RTL

In 3.3, Tiptap renders normally per labeled input (no per-locale spell check or RTL toggle yet).

## Twig Integration

### 3.3 Sliver

```twig
{# Locale required #}
{{ cms.localizedtext('title', 'de') }}
{{ cms.localizedstyledtext('body', 'de') }}

{# Get all locale values (e.g., to render alternates) #}
{{ cms.localizedtext('title').all }}

{# Site-wide locale config #}
{% for locale in cms.config('locales') %}
    {{ locale.label }}: {{ cms.localizedtext('title', locale.code) }}
{% endfor %}
```

### 3.4 Full System

```twig
{# Locale becomes optional — auto-resolves to active locale #}
{{ cms.localizedtext('title') }}
{{ cms.localizedstyledtext('body') }}

{# Locale context #}
{{ cms.locale }}            {# 'en-US' #}
{{ cms.localeDir }}         {# 'ltr' or 'rtl' #}
{{ cms.localeLabel }}       {# 'English (US)' #}
{{ cms.availableLocales }}  {# array of locale config objects #}

{# URL helpers #}
{{ cms.localeUrl('/about', 'de') }}     {# '/de/about' #}
{{ cms.localeUrl(object, 'de') }}       {# uses object's de slug #}
{{ cms.localeSwitcher() }}              {# page-aware switcher #}

{# Static UI strings #}
{{ cms.t('buttons.read_more') }}
{{ cms.t('greeting.welcome', { name: user.name }) }}

{# Formatting helpers #}
{{ cms.formatNumber(1234.56) }}
{{ cms.formatDate(post.date, 'long') }}
{{ cms.formatCurrency(99.95, 'EUR') }}
{{ cms.formatRelative(post.date) }}

{# Fallback detection #}
{% if cms.isFallback('title') %}
    <span class="translation-pending">Translation in progress</span>
{% endif %}
```

### Page-Aware Locale Switcher (3.4)

When the visitor is viewing object `my-post` at `/de/blog/mein-beitrag` and clicks the English link, the switcher links to `/en/blog/my-post` — using the object's English slug, not the home page. Falls back to swapping just the locale prefix when there's no object context.

### Static UI Strings (3.4)

`cms.t()` reads from a translations dictionary at `tcms-data/translations/{locale}.json`:

```json
{
    "buttons.read_more": "Weiterlesen",
    "buttons.subscribe": "Abonnieren",
    "form.errors.required": "Pflichtfeld"
}
```

### Per-Locale Template Overrides (3.4)

Twig loader checks for `template.{locale}.twig` before falling back to `template.twig`:

```
templates/blog/post.twig         (default)
templates/blog/post.de.twig      (German layout override)
templates/blog/post.ar.twig      (RTL layout override)
```

### `hreflang` Partial (3.4)

```twig
{% include '@cms/i18n/hreflang.twig' %}
```

Generates `<link rel="alternate" hreflang="en" href="...">` for every available locale of the current page, plus `hreflang="x-default"`.

## SEO (3.4)

A locale-aware site needs more than just hreflang.

- `<html lang="{{ cms.locale }}" dir="{{ cms.localeDir }}">` — base template gets these automatically when i18n is enabled
- `<link rel="canonical" href="...">` — canonical points to the active-locale URL
- `<meta property="og:locale" content="de_DE">` + `og:locale:alternate` for each available locale
- Locale-aware sitemap with `<xhtml:link rel="alternate" hreflang="...">` annotations per URL
- Locale-aware RSS — supports `?locale=de` to filter to a single language; `dc:language` element set correctly

## REST API

### 3.3 Sliver

Localized fields always serialize as the full `{ "en-US": ..., "de": ... }` object — no resolution happens server-side because there's no active locale.

```json
{
    "id": "my-post",
    "data": {
        "title": { "en-US": "About Us", "de": "Über uns" },
        "body": { "en-US": "...", "de": "..." }
    }
}
```

### 3.4 Full System

Locale via query parameter or header:

```
GET /api/collections/blog?locale=de
GET /api/collections/blog/my-post?locale=de
X-Locale: de
```

Default behavior shifts to "return resolved string for active locale":

```json
{
    "id": "my-post",
    "locale": "de",
    "data": {
        "title": "Über uns",
        "slug": "ueber-uns"
    }
}
```

`?expand=locales` returns the full multi-locale object (same shape as 3.3 — no break for clients that explicitly opt in):

```json
{
    "id": "my-post",
    "locale": "de",
    "availableLocales": ["en-US", "de", "ar"],
    "translated": { "en-US": true, "de": true, "ar": false },
    "data": {
        "title": { "en-US": "About Us", "de": "Über uns", "ar": "" },
        "slug": { "en-US": "about-us", "de": "ueber-uns", "ar": "" }
    }
}
```

Collections endpoint accepts `?onlyTranslated=true` to filter out objects with no translation in the requested locale.

## CLI Commands (3.4)

```bash
# Translation status across all locale-enabled collections
tcms i18n:status

# Untranslated localized fields for a specific locale
tcms i18n:missing --locale=de
tcms i18n:missing --locale=de --collection=blog

# Export untranslated strings for a locale to JSON
tcms i18n:export --locale=de --output=translations-de.json
tcms i18n:export --locale=de --collection=blog --output=blog-de.json

# Import translated strings back
tcms i18n:import --locale=de --input=translations-de.json

# Pseudo-localization for QA — generates dummy translations like "[!! Wëlcömé !!]"
tcms i18n:export --locale=de --pseudo --output=pseudo-de.json

# Migrate from the field_en/field_de workaround
tcms i18n:migrate --collection=blog --fields=title,body --locales=en-US,de --dry-run
tcms i18n:migrate --collection=blog --fields=title,body --locales=en-US,de
```

The export/import pair is the AI translation hook — export untranslated strings, pipe through DeepL or GPT, import results.

The `--pseudo` flag is a pre-launch QA tool — catches truncation bugs and missed strings before real translations exist.

## Migration Path (3.4)

For users currently using the `field_en` / `field_de` workaround:

```bash
tcms i18n:migrate --collection=blog --fields=title,body --locales=en-US,de
```

Steps:
1. Validates target locales are configured
2. Backs up `tcms-data/{collection}/` to `tcms-data/.backups/{collection}-{timestamp}/`
3. For each object: reads existing flat fields (`title_en`, `title_de`), writes values into a new `localizedtext` field (`title`)
4. Updates the schema: removes the old flat fields, adds the new localized field with the same field-builder configuration
5. Reports what changed and what was skipped

Always opt-in. Always backed up. Dry-run mode shows the plan without writing.

## Phases

### Phase 0 — 3.3 Sliver (this release)

**Effort: ~1.5 weeks**

- `localizedtext` field type with BCP 47 keyed storage
- `localizedstyledtext` field type with Tiptap (basic — no per-locale spell check or RTL toggle yet)
- Site-wide `locales` config in `tcms.php`, exposed via `cms.config('locales')`
- Per-field `defaultLocale` setting for fallback
- `cms.localizedtext('field', locale)` and `cms.localizedstyledtext('field', locale)` Twig accessors (locale required)
- Admin form: labeled-inputs-per-locale UI with RTL `dir` attribute on input rendering
- REST API: localized fields serialize as `{ "en-US": ..., "de": ... }` always
- Tests covering storage round-trip, fallback, RTL rendering, REST shape
- Documentation: field-type docs + migration-from-`field_en`-workaround guide (manual instructions, no CLI tool yet)

**Done:** a customer building a multi-language site can replace `title_en` / `title_de` field pairs with a single `localizedtext` field, store and retrieve all locale values cleanly, and render them in templates with explicit `cms.localizedtext('title', 'de')` calls.

### Phase 1 — Foundation (3.4)

**Effort: ~1–1.5 weeks**

- Per-collection schema config for locales (`defaultLocale`, `fallbackLocale`, per-collection locale list overriding site-wide)
- `LocaleContext` service + middleware for active locale resolution (URL → header → cookie → Accept-Language → default)
- URL routing with prefix pattern; unprefixed-URL behavior config
- Server-side cache key inclusion (Twig cache, APCu, fragment cache)
- `cms.localizedtext('field')` becomes valid (locale optional, auto-resolves)
- `cms.isFallback()` helper
- Tests covering resolution priority, cache isolation, fallback chains

**Done:** active locale resolves from URL/header/cookie/Accept-Language and Twig calls auto-resolve. Caches don't leak across locales.

### Phase 2 — Content Editing (3.4)

**Effort: ~1–1.5 weeks**

- `localizedslug` field type with per-locale slug index
- Admin form: locale tab row scoped to localized fields (replaces 3.3's labeled inputs)
- Object listing: per-locale translation status indicators
- Untranslated-field visual flag in editor
- Tiptap locale-aware spell check and RTL toggle

**Done:** an editor can author a multi-locale blog post end-to-end, including localized slugs, with clear UI for what's translated and what's not.

### Phase 3 — Frontend Integration (3.4)

**Effort: ~1 week**

- Twig: `cms.locale`, `cms.localeDir`, `cms.localeUrl`, `cms.localeLabel`, `cms.availableLocales`
- Page-aware `cms.localeSwitcher()`
- SEO: `hreflang` partial, `<html lang dir>` in base templates, `og:locale` + `og:locale:alternate`, canonical URLs
- Locale-aware sitemap and RSS builders
- REST API locale support (`?locale=`, `?expand=locales`, `?onlyTranslated=true`)

**Done:** a multi-locale public site renders correctly, switches between locales without losing context, and passes basic SEO audits.

### Phase 4 — Polish (3.4 or 3.4.x)

**Effort: ~0.5–1 week**

- Static strings: `cms.t()` + `tcms-data/translations/{locale}.json` loader
- Formatting helpers: `cms.formatNumber`, `cms.formatDate`, `cms.formatCurrency`, `cms.formatRelative` (PHP Intl)
- Per-locale Twig template overrides (`template.de.twig`)

**Done:** theme authors stop writing `{% if cms.locale == 'de' %}` for button labels, and locale-aware number/date formatting works without conditionals.

### Phase 5 — Tooling (3.4 or 3.4.x)

**Effort: ~0.5–1 week**

- `tcms i18n:status` and `tcms i18n:missing`
- `tcms i18n:export` / `tcms i18n:import` with `--pseudo` flag
- `tcms i18n:migrate` with `--dry-run` and backup
- JumpStart export/import round-trip test for localized data

**Done:** translation workflow is scriptable. Existing single-language sites can convert to i18n with one command.

### Phase 6 — Future (3.5+)

- `localizedimage` and `localizedfile` field types
- Per-locale workflow status (draft / in-review / published per translation)
- Translation provider webhook events (extension hook, not a built-in integration)
- Domain-pattern URL routing (`de.example.com`)
- Per-page locale availability toggles in Site Builder

## Effort Summary

| Phase | Effort | Cumulative | Target |
|---|---|---|---|
| 0. 3.3 sliver | ~1.5 weeks | 1.5 weeks | **3.3 ship** |
| 1. Foundation (active locale, routing, cache keys) | 1–1.5 weeks | 3 weeks | 3.4 |
| 2. Content editing (tabs, slugs, status, Tiptap polish) | 1–1.5 weeks | 4.5 weeks | 3.4 |
| 3. Frontend integration (Twig, SEO, REST) | 1 week | 5.5 weeks | 3.4 |
| 4. Polish (`cms.t()`, formatters, template overrides) | 0.5–1 week | 6.5 weeks | 3.4 or 3.4.x |
| 5. Tooling (CLI commands, migration) | 0.5–1 week | 7.5 weeks | 3.4 or 3.4.x |

**Sliver: ~1.5 weeks. Full system after sliver: ~4–5 weeks.** Phases 1–3 are the 3.4 MVP (~3–4 weeks); Phases 4–5 can land in 3.4.x patch releases if 3.4 is otherwise full.

## Interaction With Other Plans

- **MCP server (3.3 ship):** MCP's auto-generated tools include a `locale` parameter from day one (forward-compat). In 3.3 the parameter accepts any of the configured locales and returns the `localizedtext` value for that locale. In 3.4, when a request omits the parameter, the active locale resolves automatically.
- **Service Worker (3.5+):** SW cache keys must include locale once active-locale resolution exists (3.4). Plan already specs `Vary: X-Locale, Cookie`.
- **Site Builder:** Site Builder reads `LocaleContext` for active locale (3.4), generates locale-prefixed routes for pages. Per-page locale toggles are a Site Builder feature, deferred to Phase 6.
- **Extensions:** Extensions get access to `LocaleContext` via `ExtensionContext` (3.4). Extension-provided field types can opt into localization by following the `localized*` naming convention.
- **JumpStart:** Localized field values round-trip through JumpStart export/import. Tested in Phase 5.
- **MCP docs server:** Documentation for i18n field types and Twig helpers gets indexed alongside core docs — no extra work required.

## Open Questions

- **Locale code normalization.** Do we accept both `de` and `de-DE` and treat them as equivalent at lookup time? Probably yes (lenient input, canonical storage), but the rules need to be explicit.
- **Slug collision during migration.** If two existing objects have the same slug across different `_en`/`_de` field combinations, migration needs a clear conflict-resolution policy. Probably: report, skip, let user fix manually.
- **Tiptap RTL toolbar.** Tiptap supports RTL but our toolbar is a custom layer (`TiptapToolbar.js`). Need to verify mirroring works without manual CSS overrides.
- **Cache invalidation on locale config change.** Adding a new locale to a collection should invalidate all cached responses for that collection. Worth a clean event hook on `schema.saved` that detects locale-config changes specifically.
- **`X-Locale` vs `Accept-Language` precedence.** Current plan: explicit wins (header → cookie → Accept-Language). Worth confirming this matches what API clients expect.
- **3.3 sliver: missing-locale behavior in admin.** If `cms.config('locales')` lists `[en-US, de, ar]` but the field only has `en-US` data, the admin form renders empty inputs for `de` and `ar`. Visual indicator? Plain empty? Lean toward plain empty in the sliver (honest about state); add "not yet translated" indicator with the tabs UI in 3.4.
- **3.3 sliver: what if site config has no locales?** A `localizedtext` field on a site with no `locales` config — render one input keyed `default`? Refuse to register the field type? Probably refuse with a clear error: "localizedtext requires `locales` to be configured in tcms.php."
- **Pseudo-localization output format.** Current plan: `[!! Wëlcömé !!]` (bracketed, accented, length-padded). Alternative: configurable so QA can match their expectations. Probably ship the default and add config later if asked.

## What Done Looks Like

### 3.3 Sliver

- `localizedtext` and `localizedstyledtext` field types are available in the schema builder
- A site with `locales` configured in `tcms.php` can use these fields in any schema
- The admin form renders one labeled input per locale, with correct `dir` for RTL locales
- Stored values use BCP 47 keys (`{ "en-US": ..., "de": ... }`)
- `cms.localizedtext('field', 'de')` and `cms.localizedstyledtext('field', 'de')` return the right value
- Per-field `defaultLocale` fallback works when the requested locale is missing
- REST API serializes localized fields as the full multi-locale object
- A customer can replace `title_en` / `title_de` field pairs with a single `localizedtext` field and continue building their multi-language site with cleaner storage
- Documentation explains the manual migration path from the workaround
- Existing schemas and content with standard field types are completely unaffected

### 3.4 Full System

Everything in the sliver, plus:

- `localizedslug` field type with per-locale slug routing
- A locale-enabled collection's editor shows locale tabs scoped to localized fields
- Untranslated localized fields are visually flagged
- Object listing pages show per-locale translation status
- Active locale resolves from URL/header/cookie/Accept-Language
- `/en-us/about` and `/de/ueber-uns` both resolve to the right object via locale-aware slug lookup
- Server-side caches don't leak across locales
- `cms.localizedtext('title')` (no locale arg) auto-resolves to the active locale
- `cms.t('buttons.read_more')` returns the right translated string
- `cms.formatDate`, `cms.formatNumber`, `cms.formatCurrency` produce locale-correct output
- Page-aware locale switcher links to the equivalent translated object
- `<html lang dir>`, hreflang, og:locale, canonical, and locale-aware sitemap/RSS are all wired up
- REST API accepts locale parameter and returns the right shape (with `?expand=locales` available)
- `tcms i18n:export` and `tcms i18n:import` round-trip cleanly
- `tcms i18n:export --pseudo` produces dummy translations usable for pre-launch QA
- `tcms i18n:migrate` converts an existing `field_en`/`field_de` site without data loss
- Sites with no locale configuration are completely unaffected
