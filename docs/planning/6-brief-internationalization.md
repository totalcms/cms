# T3 Internationalization (i18n) — Feature Plan

**Status:** Planning (2026-05-01) — candidate for 3.3
**Related:** Site Builder plan (`docs/planning/5-brief-builder.md`), Service Worker plan (`docs/planning/service-worker.md`)

## Goal

Give T3 native multi-language support through a new family of locale-aware field types, locale-aware routing, and a small set of supporting helpers (formatters, static-string translation, SEO partials). The current `title_en` / `title_de` workaround works but doesn't scale — it pollutes schemas, breaks templates, and has no story for SEO, slugs, or admin UX. Native i18n makes T3 credible for international client work and opens a market segment underserved by flat-file CMS platforms.

## Non-goals

- Translation memory, glossary, or built-in machine translation. Export/import is the seam — third-party tools (DeepL, GPT, human translators) plug in there.
- Domain-pattern URL routing (`de.example.com`) for v1. Prefix routing (`/de/`) only; domain pattern documented as future work.
- Per-page locale availability toggles in Site Builder. That's a Site Builder feature, layered on top of i18n once it lands.
- Localizing collection-level metadata (collection names, descriptions). Collections stay language-neutral for v1.
- Real-time collaborative translation editing.
- Built-in CDN / edge routing for locale-specific hosting.

## Architecture

### Locale Configuration

Defined per-collection in schema settings. Collections without locale config behave exactly as today — i18n is purely additive.

```json
{
    "locales": [
        { "code": "en-US", "label": "English (US)", "dir": "ltr" },
        { "code": "de", "label": "Deutsch", "dir": "ltr" },
        { "code": "ar", "label": "العربية", "dir": "rtl" }
    ],
    "defaultLocale": "en-US",
    "fallbackLocale": "en-US"
}
```

Locale codes follow **BCP 47** (`en-US`, `pt-BR`, `zh-Hans`). Bare language codes (`de`, `fr`) remain valid — they're just BCP 47 with no region. Region variants matter for real client work (`pt-BR` vs `pt-PT`) and are painful to retrofit.

The `dir` field (`ltr` / `rtl`) is required so RTL languages render correctly without a separate locale-to-direction lookup table elsewhere in the codebase.

### Field Types

| Type | Storage | Notes |
|---|---|---|
| `localizedtext` | `{ "en-US": "About Us", "de": "Über uns" }` | Plain text, locale-keyed |
| `localizedstyledtext` | Same shape, value is rich HTML | Tiptap with locale-aware spell check + RTL toggle |
| `localizedslug` | Same shape | **Critical** — without this, German URLs are stuck as `/de/blog/my-post` instead of `/de/blog/mein-beitrag` |
| `localizedimage` | Phase 6 / future | Different image per locale (e.g., localized screenshots) |
| `localizedfile` | Phase 6 / future | Different file per locale (e.g., language-specific PDFs) |

A standard `text` field on a localized object is completely untouched. Editors choose per-field whether something is locale-aware.

### Active Locale Resolution

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
        "unprefixedDefaultLocale": "redirect" // or "serve"
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

T3 sets the active locale from the URL prefix in middleware before any template rendering. Locale prefix is stripped from the path before route resolution, so existing routes don't need to know about locales.

Site Builder picks up the active locale automatically through the request-scoped `LocaleContext` service and exposes it to Twig.

### Slug Localization

The most-overlooked piece of CMS i18n. Without it, you have half-translated URLs.

`localizedslug` stores per-locale slug values. Object lookup goes through a locale-aware index:

```
tcms-data/{collection}/.slugs/{locale}.json
```

Each file maps slug → object ID for that locale. The router uses the active locale's slug index to resolve `/de/blog/mein-beitrag` → object ID, same as today's slug resolution but locale-scoped.

Slug uniqueness is enforced **per-locale**, not globally — `/de/about` and `/en/about` can both exist as the same object's two slugs.

Backward-compatible: if an object's localized slug for the active locale is missing, fall back to the default-locale slug. Sites that haven't translated slugs yet still work.

### Server-Side Cache Keys

APCu, Twig cache, and any HTTP middleware caches **must** include the active locale in their cache keys. Without this, the first visitor of any locale poisons the cache for everyone else.

Touched components:
- `TwigCache` — extend cache key to include locale
- APCu cache adapters — locale prefix on keys for any localized-collection responses
- `CmsGrid` and Load More fragment caching — locale in cache key
- HTTP response cache headers — `Vary: X-Locale, Cookie` on localized responses

This is correctness, not performance. Worth a dedicated test suite.

### Fallback Behavior

If a localized field has no value for the active locale, fall back to `fallbackLocale`. If that's also missing, return empty string (templates can detect missing values via `cms.localizedtext('title') is empty`).

A separate helper exposes whether the value was a fallback, for templates that want to flag "machine translation pending" or similar:

```twig
{{ cms.localizedtext('title') }}
{% if cms.isFallback('title') %}
    <span class="translation-pending">Translation in progress</span>
{% endif %}
```

## Admin Interface

### Locale Tabs

For objects in a locale-enabled collection, the editing form shows a locale tab row scoped only to localized field types:

```
[ English (US) ] [ Deutsch ] [ العربية ]
```

Non-localized fields (dates, toggles, numbers, non-localized text) appear below the tabs with no locale UI — they have one value regardless of language. Switching tabs only swaps the localized-field values.

Untranslated localized fields are visually flagged with a "Not yet translated" indicator. Saving writes only the active locale's values.

### Translation Status Per Object

Object listing pages show a per-locale translation indicator:

```
my-post     [EN ✓ DE ✓ AR ✗]
about       [EN ✓ DE ✗ AR ✗]
```

Filter and sort by translation completeness. Useful for translation teams to see what's left.

### Tiptap Locale Awareness

When editing `localizedstyledtext`:
- Spell-check language matches the active locale tab
- Editor `dir` attribute switches when the active locale is RTL
- Toolbar layout mirrors for RTL (Tiptap supports this natively)

### Per-Locale Workflow Status (Phase 6)

Future: each translation can have its own status (draft / in-review / published). Translation teams need this; v1 ships without it and the field has only "exists or doesn't."

## Twig Integration

### Field Access

```twig
{# Auto-resolves to active locale, with fallback #}
{{ cms.localizedtext('title') }}
{{ cms.localizedstyledtext('body') }}

{# Explicit locale override #}
{{ cms.localizedtext('title', 'de') }}

{# All locale values (e.g. for an editor preview) #}
{{ cms.localizedtext('title').all }}
```

### Locale Context

```twig
{{ cms.locale }}           {# 'en-US' #}
{{ cms.localeDir }}        {# 'ltr' or 'rtl' #}
{{ cms.localeLabel }}      {# 'English (US)' #}
{{ cms.availableLocales }} {# array of locale config objects #}
```

### URL Helpers

```twig
{{ cms.localeUrl('/about', 'de') }}        {# '/de/about' #}
{{ cms.localeUrl(object, 'de') }}          {# uses object's de slug #}
{{ cms.localeSwitcher() }}                 {# page-aware switcher (see below) #}
{{ cms.localeSwitcher(template='custom') }}
```

### Page-Aware Locale Switcher

When the visitor is viewing object `my-post` at `/de/blog/mein-beitrag` and clicks the English link, the switcher links to `/en/blog/my-post` — using the object's English slug, not the home page. This requires the switcher to know the current object context.

Falls back to swapping just the locale prefix (`/de/foo` → `/en/foo`) when there's no object context (static pages, listings).

### Static UI Strings

`cms.t()` reads from a translations dictionary at `tcms-data/translations/{locale}.json`:

```json
{
    "buttons.read_more": "Weiterlesen",
    "buttons.subscribe": "Abonnieren",
    "form.errors.required": "Pflichtfeld"
}
```

```twig
<a href="...">{{ cms.t('buttons.read_more') }}</a>
{{ cms.t('greeting.welcome', { name: user.name }) }} {# placeholder substitution #}
```

This is the missing-piece feature. Without it, theme authors write `{% if cms.locale == 'de' %}` everywhere for button labels.

### Formatting Helpers

PHP's `Intl` extension does the heavy lifting:

```twig
{{ cms.formatNumber(1234.56) }}            {# '1,234.56' (en-US), '1.234,56' (de) #}
{{ cms.formatDate(post.date) }}            {# locale-formatted #}
{{ cms.formatDate(post.date, 'long') }}    {# 'May 1, 2026' (en-US), '1. Mai 2026' (de) #}
{{ cms.formatCurrency(99.95, 'EUR') }}     {# '€99.95' or '99,95 €' depending on locale #}
{{ cms.formatRelative(post.date) }}        {# '3 days ago' / 'vor 3 Tagen' #}
```

### Per-Locale Template Overrides

Twig loader checks for `template.{locale}.twig` before falling back to `template.twig`. Lets designers handle "German text is 30% longer" cases without inline conditionals:

```
templates/blog/post.twig         (default)
templates/blog/post.de.twig      (German layout override)
templates/blog/post.ar.twig      (RTL layout override)
```

### `hreflang` Partial

Drop-in for layouts:

```twig
{% include '@cms/i18n/hreflang.twig' %}
```

Generates `<link rel="alternate" hreflang="en" href="...">` for every available locale of the current page, plus `hreflang="x-default"`.

## SEO

A locale-aware site needs more than just hreflang.

- `<html lang="{{ cms.locale }}" dir="{{ cms.localeDir }}">` — base template gets these automatically when i18n is enabled
- `<link rel="canonical" href="...">` — canonical points to the active-locale URL
- `<meta property="og:locale" content="de_DE">` + `og:locale:alternate` for each available locale
- **Locale-aware sitemap** — sitemap builder generates `<xhtml:link rel="alternate" hreflang="...">` annotations per URL
- **Locale-aware RSS** — RSS builder supports `?locale=de` to filter to a single language; `dc:language` element set correctly

## REST API

Locale via query parameter or header:

```
GET /api/collections/blog?locale=de
GET /api/collections/blog/my-post?locale=de
X-Locale: de
```

**Response shape decision** — for localized fields, default to returning the resolved string for the active locale (simpler for most clients):

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

Add `?expand=locales` to get the full multi-locale object (for translation tooling, admin UIs):

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
tcms i18n:import --locale=de --input=pseudo-de.json

# Migrate from the field_en/field_de workaround
tcms i18n:migrate --collection=blog --fields=title,body --locales=en-US,de --dry-run
tcms i18n:migrate --collection=blog --fields=title,body --locales=en-US,de
```

The export/import pair is the AI translation hook — export untranslated strings, pipe through DeepL or GPT, import results. T3 doesn't own the AI integration, just provides clean in/out.

The `--pseudo` flag is a pre-launch QA tool — catches truncation bugs and missed strings before real translations exist.

## Migration Path

For users currently using the `field_en` / `field_de` workaround:

```bash
tcms i18n:migrate --collection=blog --fields=title,body --locales=en-US,de
```

Steps the migrator performs:
1. Validates target locales are configured on the collection
2. Backs up `tcms-data/{collection}/` to `tcms-data/.backups/{collection}-{timestamp}/`
3. For each object: reads existing flat fields (`title_en`, `title_de`), writes values into a new `localizedtext` field (`title`)
4. Updates the schema: removes the old flat fields, adds the new localized field with the same field-builder configuration
5. Reports what changed and what was skipped

Always opt-in. Always backed up. Dry-run mode shows the plan without writing.

## Phases

### Phase 1 — Foundation (no UI yet)

**Effort: ~1–1.5 weeks**

- Schema config for locales (BCP 47 + `dir`), `defaultLocale`, `fallbackLocale`
- `localizedtext` field type with storage, repository support, validation
- `LocaleContext` service + middleware for active locale resolution (URL → header → cookie → Accept-Language → default)
- URL routing with prefix pattern; unprefixed-URL behavior config
- Server-side cache key inclusion (Twig cache, APCu, fragment cache)
- Fallback resolution + `cms.isFallback()` helper
- Tests covering resolution priority, cache isolation, fallback chains

**Done:** a localized object's `localizedtext` field round-trips through storage, returns the right value for `?locale=de`, and caches don't leak across locales.

### Phase 2 — Content Editing

**Effort: ~1–1.5 weeks**

- `localizedstyledtext` field type (Tiptap with locale-aware spell check + RTL)
- `localizedslug` field type with per-locale slug index
- Admin form: locale tab row scoped to localized fields
- Object listing: per-locale translation status indicators
- Untranslated-field visual flag in editor

**Done:** an editor can author a multi-locale blog post end-to-end, including localized slugs, with clear UI for what's translated and what's not.

### Phase 3 — Frontend Integration

**Effort: ~1 week**

- Twig: `cms.localizedtext`, `cms.localizedstyledtext`, `cms.locale`, `cms.localeDir`, `cms.localeUrl`
- Page-aware `cms.localeSwitcher()` (uses object slugs from Phase 2)
- SEO: `hreflang` partial, `<html lang dir>` in base templates, `og:locale` + `og:locale:alternate`, canonical URLs
- Locale-aware sitemap and RSS builders
- REST API locale support + response shape

**Done:** a multi-locale public site renders correctly, switches between locales without losing context, and passes basic SEO audits.

### Phase 4 — Polish

**Effort: ~0.5–1 week**

- Static strings: `cms.t()` + `tcms-data/translations/{locale}.json` loader
- Formatting helpers: `cms.formatNumber`, `cms.formatDate`, `cms.formatCurrency`, `cms.formatRelative` (PHP Intl)
- Per-locale Twig template overrides (`template.de.twig`)

**Done:** theme authors stop writing `{% if cms.locale == 'de' %}` for button labels, and locale-aware number/date formatting works without conditionals.

### Phase 5 — Tooling

**Effort: ~0.5–1 week**

- `tcms i18n:status` and `tcms i18n:missing`
- `tcms i18n:export` / `tcms i18n:import` with `--pseudo` flag
- `tcms i18n:migrate` with `--dry-run` and backup
- JumpStart export/import round-trip test for localized data

**Done:** translation workflow is scriptable. Existing single-language sites can convert to i18n with one command.

### Phase 6 — Future

- `localizedimage` and `localizedfile` field types
- Per-locale workflow status (draft / in-review / published per translation)
- Translation provider webhook events (extension hook, not a built-in integration)
- Domain-pattern URL routing (`de.example.com`)
- Per-page locale availability toggles in Site Builder

## Effort Summary

| Phase | Effort | Cumulative |
|---|---|---|
| 1. Foundation | 1–1.5 weeks | 1.5 weeks |
| 2. Content editing | 1–1.5 weeks | 3 weeks |
| 3. Frontend integration | 1 week | 4 weeks |
| 4. Polish | 0.5–1 week | 5 weeks |
| 5. Tooling | 0.5–1 week | 6 weeks |

**Total: ~4–6 weeks** for phases 1–5. Phase 6 ships incrementally as demand signals.

For 3.4: phases 1–3 are the MVP (~3–4 weeks). Phases 4–5 can land in 3.4.x patch releases or 3.5.

## Interaction With Other Plans

- **Service Worker:** SW cache keys must include locale. Easier if i18n lands first (this is why we're doing i18n first per the prior decision).
- **Site Builder:** Site Builder reads `LocaleContext` for active locale, generates locale-prefixed routes for pages. Per-page locale toggles are a Site Builder feature, deferred to Phase 6.
- **Extensions:** Extensions get access to `LocaleContext` via `ExtensionContext`. Extension-provided field types can opt into localization by following the `localized*` naming convention (no special framework support needed beyond storage helpers).
- **JumpStart:** Localized field values round-trip through JumpStart export/import. Tested in Phase 5.
- **MCP docs server:** Documentation for i18n field types and Twig helpers gets indexed alongside core docs — no extra work required.

## Open Questions

- **Locale code normalization.** Do we accept both `de` and `de-DE` and treat them as equivalent at lookup time? Probably yes (lenient input, canonical storage), but the rules need to be explicit.
- **Slug collision during migration.** If two existing objects have the same slug across different `_en` / `_de` field combinations, migration needs a clear conflict-resolution policy. Probably: report, skip, let user fix manually.
- **Tiptap RTL toolbar.** Tiptap supports RTL but our toolbar is a custom layer (`TiptapToolbar.js`). Need to verify mirroring works without manual CSS overrides.
- **Cache invalidation on locale config change.** Adding a new locale to a collection should invalidate all cached responses for that collection. Worth a clean event hook on `schema.saved` that detects locale-config changes specifically.
- **`X-Locale` vs `Accept-Language` precedence.** Current plan: explicit wins (header → cookie → Accept-Language). Worth confirming this matches what API clients expect.
- **Default behavior for non-localized REST clients.** A client hitting `/api/collections/blog/my-post` without specifying a locale: do they get the default locale's resolved values, or the full multi-locale object? Current plan says default-locale resolved (simplest for existing clients), but it changes behavior on the response. Worth a deprecation period if any existing clients depend on the current shape.
- **Pseudo-localization output format.** Current plan: `[!! Wëlcömé !!]` (bracketed, accented, length-padded). Alternative: configurable so QA can match their expectations. Probably ship the default and add config later if asked.

## What Done Looks Like

- `localizedtext`, `localizedstyledtext`, and `localizedslug` field types are available in the schema builder
- Existing schemas and content with standard field types are completely unaffected
- A locale-enabled collection's editor shows locale tabs scoped to localized fields; non-localized fields show no tabs
- Untranslated localized fields are visually flagged in the admin
- Object listing pages show per-locale translation status
- Fallback to default locale works when a translation is missing
- `/en-us/about` and `/de/ueber-uns` both resolve to the right object via locale-aware slug lookup
- Server-side caches don't leak across locales
- `cms.localizedtext('title')` auto-resolves to the active locale
- `cms.t('buttons.read_more')` returns the right translated string
- `cms.formatDate`, `cms.formatNumber`, `cms.formatCurrency` produce locale-correct output
- The page-aware locale switcher links to the equivalent translated object, not the home page
- `<html lang dir>`, hreflang, og:locale, canonical, and locale-aware sitemap/RSS are all wired up
- REST API accepts locale parameter and returns the right shape (with `?expand=locales` available)
- `tcms i18n:export` and `tcms i18n:import` round-trip cleanly
- `tcms i18n:export --pseudo` produces dummy translations usable for pre-launch QA
- `tcms i18n:migrate` converts an existing `field_en` / `field_de` site without data loss
- Sites with no locale configuration are completely unaffected
