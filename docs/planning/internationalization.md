# T3 Internationalization (i18n) — 3.6 Plan

**Status:** Planning. The 3.5 sliver (localized field types, settings UI, locale registry, CSV dot-notation) shipped on the `feature/intl` branch. This document covers the remaining work for the **3.6 full system** — active-locale resolution, URL routing, admin polish, SEO, REST API expansion, CLI tooling, and migration.

For what already shipped, look at:

- `src/Domain/Property/Data/LocalizedtextData.php`, `src/Domain/Admin/FormField/Localized*Field.php`
- `src/Domain/Locale/LocaleRegistry.php`
- `src/Domain/Twig/Adapter/LocaleTwigAdapter.php` (`text()`, `styledtext()`, `canonicalizeLocale()`)
- `resources/schemas/settings/i18n.json` + `resources/templates/admin/settings.twig`
- `resources/docs/fields/localized-text.md` + `resources/docs/operations/supported-locales.md`

## Goal

Bring locale awareness to the rest of T3: resolve an active locale per request, route locale-prefixed URLs, expose Twig helpers that auto-resolve to the active locale, generate SEO markup (hreflang, og:locale, locale-aware sitemap), expand the REST API with `?locale=` / `?expand=locales`, and ship CLI tooling so translation workflows are scriptable.

## Forward-Compatibility Contract (locked in 3.5)

These decisions shipped in 3.5 and cannot be revisited without a migration story. 3.6 work must respect them:

1. **Storage format is mixed-case POSIX locale codes.** `{ "en_US": "About Us", "de": "Über uns", "pt_BR": "Sobre" }`. Language lowercase, region uppercase, underscore separator. Bare language codes (`de`, `fr`) are valid. BCP 47 normalization (`en-US`, lowercase `/en-us/`) happens at the HTML/HTTP output boundary, not at storage.
2. **Twig accessor namespace and signature are final.** `cms.locale.text(value, locale)` and `cms.locale.styledtext(value, locale)`. In 3.6 the `locale` argument becomes optional and auto-resolves to the active locale. Same call shape, expanded behavior — no template rewrites. Direct array access (`post.title.de`, `post.title['en_US']`) also works without a helper.
3. **Helper locale matching is lenient.** Lookup order: (1) canonicalize input (case-insensitive), (2) exact match in the value dict, (3) region fall-up (`de_DE` → `de`), (4) region fall-down (bare `en` → first matching `en_*` in `i18n.available` order), (5) site default (`cms.config('i18n', 'default')`), (6) empty string.
4. **REST API serialization shape stays.** Localized fields always serialize as the full `{ "en_US": ..., "de": ... }` object in 3.5. In 3.6, the default behavior shifts to "return the resolved string for the active locale" with `?expand=locales` for the full object — but the full-object shape stays available exactly as today.
5. **Locale config lives at the site level under `i18n` in 3.5, with a future per-collection override in 3.6.** `cms.config('i18n', 'available')` returns the site list; per-collection overrides become the new default with site-level as fallback. No data migration.
6. **No reserved collections.** `localizedtext`, `localizedtextarea`, and `localizedstyledtext` are field types only.
7. **Pro edition transitively.** Localized field types live in custom schemas, which are gated by `EditionFeature::CUSTOM_SCHEMAS`. No dedicated `EditionFeature`.
8. **Locale registry is curated.** `LocaleRegistry::LOCALES` is a hardcoded list; operator-extensibility deferred to 3.6+.

## Non-goals (3.6)

- Translation memory, glossary, or built-in machine translation. Export/import is the seam — third-party tools (DeepL, GPT, human translators) plug in there.
- Domain-pattern URL routing (`de.example.com`). Prefix routing (`/de/`) only; domain pattern documented as future work.
- Per-page locale availability toggles in Site Builder. That's a Site Builder feature, layered on top of i18n once it lands.
- Localizing collection-level metadata (collection names, descriptions). Collections stay language-neutral.
- Real-time collaborative translation editing.
- Built-in CDN / edge routing for locale-specific hosting.

## Architecture

### Per-collection schema overrides (new in 3.6)

Schemas can override the site-wide config:

```json
{
    "i18n": {
        "default":  "en_US",
        "fallback": "en_US",
        "available": ["en_US", "de"]
    }
}
```

When 3.6 adds per-collection locale config, the site-wide setting becomes the default fallback. Codes flow through `LocaleRegistry::expand()` the same way they do at the site level.

### Active Locale Resolution

Resolution order, highest priority first:

1. **URL prefix** — `/de/about` sets locale to `de`
2. **`X-Locale` header** — for API clients
3. **`?locale=` query parameter** — for API and explicit overrides
4. **User preference cookie** — `tcms_locale`, set when a user clicks the locale switcher
5. **`Accept-Language` header** — parsed and matched against site's configured locales
6. **Site default locale** (`i18n.default`)

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

### URL Routing

Prefix pattern only for v1:

```
/en-us/about
/de/about
/ar/about
```

T3 sets the active locale from the URL prefix in middleware before any template rendering. Locale prefix is stripped from the path before route resolution, so existing routes don't need to know about locales. Site Builder picks up the active locale automatically through the request-scoped `LocaleContext` service.

### Slug Localization

`localizedslug` stores per-locale slug values. Object lookup goes through a locale-aware index:

```
tcms-data/{collection}/.slugs/{locale}.json
```

Each file maps slug → object ID for that locale. The router uses the active locale's slug index to resolve `/de/blog/mein-beitrag` → object ID, locale-scoped.

Slug uniqueness is enforced **per-locale**, not globally — `/de/about` and `/en/about` can both exist as the same object's two slugs. If an object's localized slug for the active locale is missing, fall back to the default-locale slug.

### Server-Side Cache Keys

APCu, Twig cache, and any HTTP middleware caches **must** include the active locale in their cache keys once active locale resolution exists. Without this, the first visitor of any locale poisons the cache for everyone else.

Touched components:
- `TwigCache` — extend cache key to include locale
- APCu cache adapters — locale prefix on keys for any localized-collection responses
- `CmsGrid` and Load More fragment caching — locale in cache key
- HTTP response cache headers — `Vary: X-Locale, Cookie` on localized responses

This is correctness, not performance. Worth a dedicated test suite.

### Fallback Detection helper

Expose whether a value was resolved via fallback rather than exact match:

```twig
{{ cms.locale.text(post.title) }}
{% if cms.locale.isFallback(post.title) %}
    <span class="translation-pending">Translation in progress</span>
{% endif %}
```

## Admin Interface

### Form-wide locale tabs

For objects in a locale-enabled collection, the editing form shows a *form-wide* locale tab row that switches every localized field on the page in unison. Per-field tabs (from 3.5) remain for inline editing; the form-wide row is a convenience for editorial work that focuses on translating one locale end-to-end. Saving writes only the active locale's values.

### Translation status per object

Object listing pages show a per-locale translation indicator:

```
my-post     [EN ✓ DE ✓ AR ✗]
about       [EN ✓ DE ✗ AR ✗]
```

Filter and sort by translation completeness.

### Untranslated-field visual flag

In the editor, localized fields with no value for the active locale get a "Not yet translated" badge so editors see at a glance what still needs work.

### Tiptap Locale Awareness

When editing `localizedstyledtext`:
- Spell-check language matches the active locale tab
- Editor `dir` attribute switches when the active locale is RTL
- Toolbar layout mirrors for RTL

## Twig Integration (3.6 surface)

```twig
{# Locale becomes optional — auto-resolves to active locale #}
{{ cms.locale.text(post.title) }}
{{ cms.locale.styledtext(post.body) }}

{# Locale context #}
{{ cms.locale.current }}    {# 'en_US' (mixed-case POSIX) — replaces the existing cms.locale.get() method #}
{{ cms.locale.bcp }}        {# 'en-US' (BCP 47 dashed, for HTML lang) #}
{{ cms.locale.dir }}        {# 'ltr' or 'rtl' #}
{{ cms.locale.label }}      {# 'English (US)' #}
{{ cms.locale.available }}  {# array of locale config objects #}

{# URL helpers #}
{{ cms.locale.url('/about', 'de') }}     {# '/de/about' — emits lowercase BCP 47 prefix #}
{{ cms.locale.url(object, 'de') }}       {# uses object's de slug #}
{{ cms.locale.switcher() }}              {# page-aware switcher #}

{# Static UI strings #}
{{ cms.locale.t('buttons.read_more') }}
{{ cms.locale.t('greeting.welcome', { name: user.name }) }}

{# Formatting helpers #}
{{ cms.locale.formatNumber(1234.56) }}
{{ cms.locale.formatDate(post.date, 'long') }}
{{ cms.locale.formatCurrency(99.95, 'EUR') }}
{{ cms.locale.formatRelative(post.date) }}

{# Fallback detection #}
{% if cms.locale.isFallback(post.title) %}
    <span class="translation-pending">Translation in progress</span>
{% endif %}
```

### Page-Aware Locale Switcher

When the visitor is viewing object `my-post` at `/de/blog/mein-beitrag` and clicks the English link, the switcher links to `/en/blog/my-post` — using the object's English slug, not the home page. Falls back to swapping just the locale prefix when there's no object context.

### Static UI Strings

`cms.locale.t()` reads from a translations dictionary at `tcms-data/translations/{locale}.json`:

```json
{
    "buttons.read_more": "Weiterlesen",
    "buttons.subscribe": "Abonnieren",
    "form.errors.required": "Pflichtfeld"
}
```

### Per-Locale Template Overrides

Twig loader checks for `template.{locale}.twig` before falling back to `template.twig`:

```
templates/blog/post.twig         (default)
templates/blog/post.de.twig      (German layout override)
templates/blog/post.ar.twig      (RTL layout override)
```

### `hreflang` Partial

```twig
{% include '@cms/i18n/hreflang.twig' %}
```

Generates `<link rel="alternate" hreflang="en" href="...">` for every available locale of the current page, plus `hreflang="x-default"`.

## SEO

A locale-aware site needs more than just hreflang.

- `<html lang="{{ cms.locale.bcp }}" dir="{{ cms.locale.dir }}">` — base template gets these automatically when i18n is enabled (`bcp` returns the BCP 47 dashed form for HTML)
- `<link rel="canonical" href="...">` — canonical points to the active-locale URL
- `<meta property="og:locale" content="de_DE">` — uses underscore form natively, served directly from `cms.locale.current`
- `og:locale:alternate` for each available locale
- Locale-aware sitemap with `<xhtml:link rel="alternate" hreflang="...">` annotations per URL (BCP 47 dashed form)
- Locale-aware RSS — supports `?locale=de` to filter to a single language; `dc:language` element set correctly

## REST API

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

`?expand=locales` returns the full multi-locale object (same shape as 3.5 — no break for clients that explicitly opt in):

```json
{
    "id": "my-post",
    "locale": "de",
    "availableLocales": ["en_US", "de", "ar"],
    "translated": { "en_US": true, "de": true, "ar": false },
    "data": {
        "title": { "en_US": "About Us", "de": "Über uns", "ar": "" },
        "slug": { "en_US": "about-us", "de": "ueber-uns", "ar": "" }
    }
}
```

Collections endpoint accepts `?onlyTranslated=true` to filter out objects with no translation in the requested locale.

## CLI Commands

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
tcms i18n:migrate --collection=blog --fields=title,body --locales=en_US,de --dry-run
tcms i18n:migrate --collection=blog --fields=title,body --locales=en_US,de
```

The export/import pair is the AI translation hook — export untranslated strings, pipe through DeepL or GPT, import results.

The `--pseudo` flag is a pre-launch QA tool — catches truncation bugs and missed strings before real translations exist.

## Migration Path

For users currently using the `field_en` / `field_de` workaround:

```bash
tcms i18n:migrate --collection=blog --fields=title,body --locales=en_US,de
```

Steps:
1. Validates target locales are configured
2. Backs up `tcms-data/{collection}/` to `tcms-data/.backups/{collection}-{timestamp}/`
3. For each object: reads existing flat fields (`title_en`, `title_de`), writes values into a new `localizedtext` field (`title`)
4. Updates the schema: removes the old flat fields, adds the new localized field with the same field-builder configuration
5. Reports what changed and what was skipped

Always opt-in. Always backed up. Dry-run mode shows the plan without writing.

## Phases

### Phase 1 — Foundation

**Effort: ~1–1.5 weeks**

- Per-collection schema config for locales (`default`, `fallback`, per-collection locale list overriding site-wide)
- `LocaleContext` service + middleware for active locale resolution (URL → header → cookie → Accept-Language → default)
- URL routing with prefix pattern; unprefixed-URL behavior config
- Server-side cache key inclusion (Twig cache, APCu, fragment cache)
- `cms.locale.text(value)` becomes valid (locale optional, auto-resolves)
- `cms.locale.isFallback()` helper
- Tests covering resolution priority, cache isolation, fallback chains

**Done:** active locale resolves from URL/header/cookie/Accept-Language and Twig calls auto-resolve. Caches don't leak across locales.

### Phase 2 — Content Editing

**Effort: ~1–1.5 weeks**

- `localizedslug` field type with per-locale slug index
- Form-wide locale tab row that switches every localized field in unison (complements per-field tabs from 3.5)
- Object listing: per-locale translation status indicators
- Untranslated-field visual flag in editor
- Tiptap locale-aware spell check and RTL toggle

**Done:** an editor can author a multi-locale blog post end-to-end, including localized slugs, with clear UI for what's translated and what's not.

### Phase 3 — Frontend Integration

**Effort: ~1 week**

- Twig: `cms.locale.current`, `cms.locale.bcp`, `cms.locale.dir`, `cms.locale.url`, `cms.locale.label`, `cms.locale.available`
- Page-aware `cms.locale.switcher()`
- SEO: `hreflang` partial, `<html lang dir>` in base templates, `og:locale` + `og:locale:alternate`, canonical URLs
- Locale-aware sitemap and RSS builders
- REST API locale support (`?locale=`, `?expand=locales`, `?onlyTranslated=true`)

**Done:** a multi-locale public site renders correctly, switches between locales without losing context, and passes basic SEO audits.

### Phase 4 — Polish (3.6 or 3.6.x)

**Effort: ~0.5–1 week**

- Static strings: `cms.locale.t()` + `tcms-data/translations/{locale}.json` loader
- Formatting helpers: `cms.locale.formatNumber`, `cms.locale.formatDate`, `cms.locale.formatCurrency`, `cms.locale.formatRelative` (PHP Intl)
- Per-locale Twig template overrides (`template.de.twig`)

**Done:** theme authors stop writing `{% if cms.locale.current == 'de' %}` for button labels, and locale-aware number/date formatting works without conditionals.

### Phase 5 — Tooling (3.6 or 3.6.x)

**Effort: ~0.5–1 week**

- `tcms i18n:status` and `tcms i18n:missing`
- `tcms i18n:export` / `tcms i18n:import` with `--pseudo` flag
- `tcms i18n:migrate` with `--dry-run` and backup
- JumpStart export/import round-trip test for localized data

**Done:** translation workflow is scriptable. Existing single-language sites can convert to i18n with one command.

### Phase 6 — Future (3.7+)

- `localizedimage` and `localizedfile` field types
- Per-locale workflow status (draft / in-review / published per translation)
- Translation provider webhook events (extension hook, not a built-in integration)
- Domain-pattern URL routing (`de.example.com`)
- Per-page locale availability toggles in Site Builder
- Operator-extensible locale registry (extension hook to add custom codes)

## Effort Summary

| Phase | Effort | Cumulative |
|---|---|---|
| 1. Foundation (active locale, routing, cache keys) | 1–1.5 weeks | 1.5 weeks |
| 2. Content editing (form-wide tabs, slugs, status, Tiptap polish) | 1–1.5 weeks | 3 weeks |
| 3. Frontend integration (Twig, SEO, REST) | 1 week | 4 weeks |
| 4. Polish (`cms.locale.t()`, formatters, template overrides) | 0.5–1 week | 5 weeks |
| 5. Tooling (CLI commands, migration) | 0.5–1 week | 6 weeks |

**Full 3.6 system: ~4–5 weeks.** Phases 1–3 are the MVP (~3–4 weeks); Phases 4–5 can land in 3.6.x patch releases if 3.6 is otherwise full.

## Interaction With Other Plans

- **Service Worker:** SW cache keys must include locale once active-locale resolution exists. Plan already specs `Vary: X-Locale, Cookie`.
- **Site Builder:** Site Builder reads `LocaleContext` for active locale, generates locale-prefixed routes for pages. Per-page locale toggles are a Site Builder feature, deferred to Phase 6.
- **Extensions:** Extensions get access to `LocaleContext` via `ExtensionContext`. Extension-provided field types can opt into localization by following the `localized*` naming convention.
- **JumpStart:** Localized field values round-trip through JumpStart export/import. Tested in Phase 5.

## Open Questions

- **Slug collision during migration.** If two existing objects have the same slug across different `_en`/`_de` field combinations, migration needs a clear conflict-resolution policy. Probably: report, skip, let user fix manually.
- **Tiptap RTL toolbar.** Tiptap supports RTL but our toolbar is a custom layer (`TiptapToolbar.js`). Need to verify mirroring works without manual CSS overrides.
- **Cache invalidation on locale config change.** Adding a new locale to a collection should invalidate all cached responses for that collection. Worth a clean event hook on `schema.saved` that detects locale-config changes specifically.
- **`X-Locale` vs `Accept-Language` precedence.** Current plan: explicit wins (header → cookie → Accept-Language). Worth confirming this matches what API clients expect.
- **Pseudo-localization output format.** Current plan: `[!! Wëlcömé !!]` (bracketed, accented, length-padded). Alternative: configurable so QA can match their expectations. Probably ship the default and add config later if asked.

## What Done Looks Like (3.6 full system)

- `localizedslug` field type with per-locale slug routing
- A locale-enabled collection's editor shows form-wide locale tabs in addition to per-field tabs
- Untranslated localized fields are visually flagged
- Object listing pages show per-locale translation status
- Active locale resolves from URL/header/cookie/Accept-Language
- `/en-us/about` and `/de/ueber-uns` both resolve to the right object via locale-aware slug lookup
- Server-side caches don't leak across locales
- `cms.locale.text(post.title)` (no locale arg) auto-resolves to the active locale
- `cms.locale.t('buttons.read_more')` returns the right translated string
- `cms.locale.formatDate`, `cms.locale.formatNumber`, `cms.locale.formatCurrency` produce locale-correct output
- Page-aware locale switcher links to the equivalent translated object
- `<html lang dir>`, hreflang, og:locale, canonical, and locale-aware sitemap/RSS are all wired up
- REST API accepts locale parameter and returns the right shape (with `?expand=locales` available)
- `tcms i18n:export` and `tcms i18n:import` round-trip cleanly
- `tcms i18n:export --pseudo` produces dummy translations usable for pre-launch QA
- `tcms i18n:migrate` converts an existing `field_en`/`field_de` site without data loss
- Sites with no locale configuration are completely unaffected
