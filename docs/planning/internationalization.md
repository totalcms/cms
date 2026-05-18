# T3 Internationalization (i18n) — Feature Plan

**Status:** Planning (2026-05-02; renumbered 2026-05-15 after 3.3→3.5 release shift) — split scope: **3.5 sliver** (localized field types only) + **3.6 full system** (routing, admin tabs, SEO, CLI, migration)
**Supersedes:** `docs/planning/6-brief-internationalization.md`
**Related:** Site Builder plan (`docs/planning/5-brief-builder.md`), Service Worker plan (`docs/planning/service-worker.md`), MCP server plan (`docs/planning/mcp-server.md`)

## Goal

Give T3 native multi-language support through a new family of locale-aware field types, locale-aware routing, and a small set of supporting helpers (formatters, static-string translation, SEO partials). The current `title_en` / `title_de` workaround works but doesn't scale — it pollutes schemas, breaks templates, and has no story for SEO, slugs, or admin UX. Native i18n makes T3 credible for international client work and opens a market segment underserved by flat-file CMS platforms.

The work splits into two releases:

- **3.5 sliver** — just the `localizedtext` and `localizedstyledtext` field types, with a deliberately minimal admin UI and Twig API designed to be forward-compatible with the full system. Customers building multi-language sites *today* (using the `field_en`/`field_de` workaround) get a cleaner storage shape they can adopt without waiting for the full release.
- **3.6 full system** — locale routing, admin locale tabs, SEO helpers, CLI tools, migration command, slug localization, server-side cache key isolation, and the rest of the stack.

## Release Scope: 3.5 Sliver vs 3.6 Full System

### 3.5 Sliver (this release)

Ships only what's needed to give customers cleaner content storage today, without forcing the full i18n stack.

| In | Out |
|---|---|
| `localizedtext` field type | Locale-aware URL routing (`/de/about`) |
| `localizedstyledtext` field type (Tiptap) | Active locale resolution / `LocaleContext` middleware |
| Site-wide `cms.config('i18n', 'available')` | Slug localization |
| `cms.locale.text(value, locale)` Twig helper (locale required, case-insensitive) | Admin locale tabs |
| `cms.locale.styledtext(value, locale)` Twig helper | `cms.locale.t()` static strings |
| Per-field `defaultLocale` fallback | Locale-aware formatters |
| Admin form: labeled-inputs-per-locale UI with RTL `dir` | SEO helpers (hreflang, og:locale, locale-aware sitemap) |
| Mixed-case POSIX locale codes (`en_US`, `de`, `pt_BR`) | REST API `?locale=` query parameter |
| REST API serializes localized fields as `{ "en_US": "...", "de": "..." }` always | CLI commands (status, missing, export, import, migrate) |
| Transitive Pro gating via `CUSTOM_SCHEMAS` (no dedicated `EditionFeature`) | Per-locale Twig template overrides |
| | Per-locale Twig template overrides |
| | Server-side cache key isolation (no active locale yet, so not needed) |

**Effort: ~1.5 weeks.** Slots into 3.5 alongside MCP work without disrupting the platform-release narrative.

**Marketing framing:** "T3 has localized field types now — full i18n routing and tooling lands in 3.6." Don't call this "T3 has i18n now" — it's deliberately a sliver.

### 3.6 Full System

Everything in the architecture sections below that isn't in the sliver. Phased as Phases 1–5 in the Phases section (the sliver is Phase 0).

## Forward-Compatibility Contract

These decisions are locked in 3.5 because changing them in 3.6 would be a migration headache:

1. **Storage format is mixed-case POSIX locale codes.** `{ "en_US": "About Us", "de": "Über uns", "pt_BR": "Sobre" }`. Language lowercase, region uppercase, underscore separator — the format PHP's intl extension, CakePHP I18n, Faker, and T3's existing admin translation files (`admin.en_US.php`, `js.de_DE.php`, etc.) all canonicalize to. Bare language codes (`de`, `fr`) are valid. BCP 47 normalization (`en-US`, lowercase `/en-us/`) happens at the HTML/HTTP output boundary in 3.6, not at storage.
2. **Twig accessor namespace and signature are final.** `cms.locale.text(value, locale)` and `cms.locale.styledtext(value, locale)`, where `value` is the localized dict (typically `post.title`). The `cms.locale.*` namespace gives the full 3.6 surface (`cms.locale.t()`, `cms.locale.url()`, `cms.locale.current`, `cms.locale.switcher()`, formatters) a clean home alongside existing `cms.locale.set()`/`cms.locale.get()`/`cms.locale.t()`/`cms.locale.languages()`. In 3.5 the `locale` argument is required; in 3.6 it becomes optional and auto-resolves to the active locale. Same call shape, expanded behavior — no template rewrites. Direct array access (`post.title.de`, `post.title['en_US']`) also works without a helper for the simple case.
3. **Helper locale matching is lenient.** Lookup order: (1) canonicalize input (case-insensitive — `'en_us'`, `'EN_US'`, `'En_Us'` all become `en_US`), (2) exact match in the value dict, (3) region fall-up (request `de_DE`, fall to bare `de`), (4) region fall-down (request bare `en`, fall to first matching `en_*` in the order of the site's configured locales), (5) field-level `defaultLocale`, (6) empty string. This stays the same in 3.6 — active-locale resolution feeds the same chain.
4. **REST API serialization shape stays.** Localized fields always serialize as the full `{ "en_US": ..., "de": ... }` object in 3.5. In 3.6, the default behavior shifts to "return the resolved string for the active locale" with `?expand=locales` for the full object — but the full-object shape stays available exactly as today. No breaking shape change for clients that opt into `?expand=locales`.
5. **Locale-content config lives at the site level in 3.5 under the `i18n` bucket, with a future per-collection override in 3.6.** `cms.config('i18n', 'available')` returns the site list; `cms.config('i18n', 'default')` returns the default locale. When 3.6 adds per-collection locale config, the site-wide setting becomes the default fallback. No data migration.
6. **No reserved collections.** Unlike the existing `text` / `styledtext` collections, `localizedtext` and `localizedstyledtext` are field *types* only — they don't get auto-reserved collections. Customers use them on their own schemas.
7. **Pro edition only (transitive).** Both field types are Pro-only in effect because they're usable only inside custom schemas, and custom schemas are already gated behind `EditionFeature::CUSTOM_SCHEMAS`. No dedicated `EditionFeature` for the localized types — adding one would only fire in code paths that aren't reachable without Pro anyway. Same pattern as `deck`.
8. **No `localizedslug` in 3.5.** Slug localization needs `/de/` URL routing to be useful. Shipping a slug field without anywhere to use it would constrain 3.6's design.
9. **Admin form UI uses a tab interface.** One tab per configured locale, single pane visible at a time. The defaultLocale tab is active on render. The "Not yet translated" indicator (per-tab status badge) ships in 3.6 with the rest of the editorial polish.

## Non-goals

- Translation memory, glossary, or built-in machine translation. Export/import (Phase 5) is the seam — third-party tools (DeepL, GPT, human translators) plug in there.
- Domain-pattern URL routing (`de.example.com`) for v1. Prefix routing (`/de/`) only; domain pattern documented as future work.
- Per-page locale availability toggles in Site Builder. That's a Site Builder feature, layered on top of i18n once it lands.
- Localizing collection-level metadata (collection names, descriptions). Collections stay language-neutral.
- Real-time collaborative translation editing.
- Built-in CDN / edge routing for locale-specific hosting.

## Architecture

### Locale Configuration

In 3.5, locale-content settings live in a single site-wide `i18n` config bucket — sibling to the existing `auth`, `htmlclean`, etc. buckets — distinct from the system locale string at `$config->locale`:

```php
// config/tcms.php
return [
    'i18n' => [
        'default'   => 'en_US',
        'available' => [
            ['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
            ['code' => 'de', 'label' => 'Deutsch', 'dir' => 'ltr'],
            ['code' => 'ar', 'label' => 'العربية', 'dir' => 'rtl'],
        ],
    ],
];
```

Accessible via `cms.config('i18n', 'available')` and `cms.config('i18n', 'default')`. Empty by default — the field types refuse to render with an explanatory error if the site has no `i18n.available` entries (matches the open-question resolution in section 13 below).

A backwards-compat shim in `Config::__construct` also accepts the flat-key shape (`$settings['locales']`, `$settings['defaultLocale']`) that pre-dated the `i18n` bucket — so any operator who copied the pre-rename docs still works without an edit.

In 3.6, schemas can override the site-wide config per collection:

```json
{
    "i18n": {
        "default":  "en_US",
        "fallback": "en_US",
        "available": [
            { "code": "en_US", "label": "English (US)", "dir": "ltr" },
            { "code": "de", "label": "Deutsch", "dir": "ltr" }
        ]
    }
}
```

Locale codes use the **mixed-case POSIX format** (`en_US`, `pt_BR`, `zh_Hans`) — the canonical form used by PHP's intl extension, CakePHP I18n, Faker, and T3's existing admin translation files. Bare language codes (`de`, `fr`) remain valid. The order of the `locales` array is meaningful: it determines fall-down order when the Twig helper is given a bare-language request like `en` and needs to pick among `en_US` / `en_GB`.

BCP 47 dashed form (`en-US`, `pt-BR`) is only used at HTML/HTTP output boundaries (`<html lang="en-US">`, `hreflang`, URL prefix `/en-us/`) in 3.6 — one-line normalization at render time.

The `dir` field (`ltr` / `rtl`) is required so RTL languages render correctly without a separate locale-to-direction lookup table.

### Field Types

| Type | Storage | Notes | Ships in |
|---|---|---|---|
| `localizedtext` | `{ "en_US": "About Us", "de": "Über uns" }` | Plain text, locale-keyed | **3.5** |
| `localizedstyledtext` | Same shape, value is rich HTML | Tiptap with locale-aware spell check + RTL toggle | **3.5** (basic Tiptap; spell check and RTL toggling come in 3.6) |
| `localizedslug` | Same shape | Per-locale slug index, used by locale-aware routing | 3.6 |
| `localizedimage` | Future | Different image per locale (e.g., localized screenshots) | 3.7+ |
| `localizedfile` | Future | Different file per locale (e.g., language-specific PDFs) | 3.7+ |

A standard `text` field on a localized object is completely untouched. Editors choose per-field whether something is locale-aware.

Both field types are **Pro edition only** in practice — they live exclusively in custom schemas, and custom schemas already require Pro (`EditionFeature::CUSTOM_SCHEMAS`). Lite and Standard sites can't reach the schema editor at all, so the localized field types are inaccessible without ever needing a dedicated edition gate. Same precedent as `deck`.

### Active Locale Resolution (3.6)

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

### URL Routing (3.6)

Prefix pattern only for v1:

```
/en-us/about
/de/about
/ar/about
```

T3 sets the active locale from the URL prefix in middleware before any template rendering. Locale prefix is stripped from the path before route resolution, so existing routes don't need to know about locales.

Site Builder picks up the active locale automatically through the request-scoped `LocaleContext` service.

### Slug Localization (3.6)

`localizedslug` stores per-locale slug values. Object lookup goes through a locale-aware index:

```
tcms-data/{collection}/.slugs/{locale}.json
```

Each file maps slug → object ID for that locale. The router uses the active locale's slug index to resolve `/de/blog/mein-beitrag` → object ID, locale-scoped.

Slug uniqueness is enforced **per-locale**, not globally — `/de/about` and `/en/about` can both exist as the same object's two slugs.

Backward-compatible: if an object's localized slug for the active locale is missing, fall back to the default-locale slug.

### Server-Side Cache Keys (3.6)

APCu, Twig cache, and any HTTP middleware caches **must** include the active locale in their cache keys once active locale resolution exists. Without this, the first visitor of any locale poisons the cache for everyone else.

Touched components:
- `TwigCache` — extend cache key to include locale
- APCu cache adapters — locale prefix on keys for any localized-collection responses
- `CmsGrid` and Load More fragment caching — locale in cache key
- HTTP response cache headers — `Vary: X-Locale, Cookie` on localized responses

This is correctness, not performance. Worth a dedicated test suite.

In 3.5 there's no active locale to vary on, so no cache changes needed.

### Fallback Behavior

The Twig helper applies a deterministic lookup chain. Given `cms.locale.text(value, locale)`:

1. **Canonicalize** the input locale — case-insensitive on input, normalized to `{lang}_{REGION}` (lowercase language, uppercase region). So `'en_us'`, `'EN_US'`, `'En_Us'` all become `en_US`.
2. **Exact match** in the value dict — `value[canonicalized_locale]` if present.
3. **Region fall-up** — if the requested code has a region (`de_DE`), try the bare language (`de`).
4. **Region fall-down** — if the requested code is a bare language (`en`), walk the site's `cms.config('i18n', 'available')` array in order and return the first entry whose code starts with `{lang}_` and has a value in the dict. Configured order matters here — it's how operators express preference.
5. **Field-level `defaultLocale`** — the value at that key, if set.
6. **Empty string.**

Direct array access bypasses the helper and gives you the raw value: `post.title.de` works (Twig syntax for bare-language keys), `post.title['en_US']` works for region-coded keys. Use the helper when you want the fallback chain or case-insensitive locale handling.

A separate helper exposes whether the value was a fallback (3.6):

```twig
{{ cms.locale.text(post.title) }}
{% if cms.locale.isFallback(post.title) %}
    <span class="translation-pending">Translation in progress</span>
{% endif %}
```

In 3.5, `cms.locale.text(post.title, 'de')` walks the chain above and returns the resolved string.

## Admin Interface

### 3.5 Sliver: Locale Tabs (per-field)

Each localized field renders as a tab strip with one tab per configured locale; clicking a tab shows the matching input or Tiptap editor. The `defaultLocale` tab is active on first render. RTL locales render their input/textarea with `dir="rtl"` so caret position and text alignment are correct.

```
[ English (US) ][ Deutsch ][ العربية ]
┌─────────────────────────────────────┐
│ About Us                            │
└─────────────────────────────────────┘
```

Implementation: pure-semantic HTML (`role="tablist"` + `role="tab"` + `role="tabpanel"` with `aria-selected` / `aria-controls` / `aria-labelledby`) plus a single delegated click handler in the JS field class (~10 lines) that toggles `hidden` on panes and updates `aria-selected`. The `LocalizedStyledTextField` JS additionally nudges Tiptap's view layer when its tab becomes active so editor measurements settle correctly. Tab strip is scoped to one field — multiple localized fields on one form each get their own independent tab state.

### 3.6 Full System: Form-Wide Locale Tabs

For objects in a locale-enabled collection, the editing form also shows a *form-wide* locale tab row that switches every localized field on the page in unison. Per-field tabs remain for inline editing; the form-wide row is a convenience for editorial work that focuses on translating one locale end-to-end. Untranslated localized fields are visually flagged with a "Not yet translated" indicator. Saving writes only the active locale's values.

### Translation Status Per Object (3.6)

Object listing pages show a per-locale translation indicator:

```
my-post     [EN ✓ DE ✓ AR ✗]
about       [EN ✓ DE ✗ AR ✗]
```

Filter and sort by translation completeness.

### Tiptap Locale Awareness (3.6)

When editing `localizedstyledtext`:
- Spell-check language matches the active locale tab
- Editor `dir` attribute switches when the active locale is RTL
- Toolbar layout mirrors for RTL

In 3.5, Tiptap renders normally per labeled input (no per-locale spell check or RTL toggle yet).

## Twig Integration

All i18n helpers live under the `cms.locale.*` namespace — same sub-adapter pattern as `cms.data.*`, `cms.render.*`, `cms.builder.*`, `cms.grid.*`.

### 3.5 Sliver

```twig
{# Direct array access — simplest case, no helper #}
{{ post.title.de }}              {# bare-language key, dot syntax works #}
{{ post.title['en_US'] }}        {# region-coded key, bracket syntax #}

{# Helper — locale required, applies fallback chain (case-insensitive on locale arg) #}
{{ cms.locale.text(post.title, 'de') }}
{{ cms.locale.styledtext(post.body, 'de') }}

{# Bare-language request falls down to first matching region in the configured locales order #}
{{ cms.locale.text(post.title, 'en') }}  {# returns en_US value if en_US comes first in cms.config('i18n', 'available') #}

{# Iterate site-wide locales (e.g., render alternates) #}
{% for locale in cms.config('i18n', 'available') %}
    {{ locale.label }}: {{ cms.locale.text(post.title, locale.code) }}
{% endfor %}

{# All locale values — just the raw dict, no helper needed #}
{{ post.title|json_encode }}
```

### 3.6 Full System

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

### Page-Aware Locale Switcher (3.6)

When the visitor is viewing object `my-post` at `/de/blog/mein-beitrag` and clicks the English link, the switcher links to `/en/blog/my-post` — using the object's English slug, not the home page. Falls back to swapping just the locale prefix when there's no object context.

### Static UI Strings (3.6)

`cms.t()` reads from a translations dictionary at `tcms-data/translations/{locale}.json`:

```json
{
    "buttons.read_more": "Weiterlesen",
    "buttons.subscribe": "Abonnieren",
    "form.errors.required": "Pflichtfeld"
}
```

### Per-Locale Template Overrides (3.6)

Twig loader checks for `template.{locale}.twig` before falling back to `template.twig`:

```
templates/blog/post.twig         (default)
templates/blog/post.de.twig      (German layout override)
templates/blog/post.ar.twig      (RTL layout override)
```

### `hreflang` Partial (3.6)

```twig
{% include '@cms/i18n/hreflang.twig' %}
```

Generates `<link rel="alternate" hreflang="en" href="...">` for every available locale of the current page, plus `hreflang="x-default"`.

## SEO (3.6)

A locale-aware site needs more than just hreflang.

- `<html lang="{{ cms.locale.localeBcp }}" dir="{{ cms.locale.localeDir }}">` — base template gets these automatically when i18n is enabled (note `localeBcp` returns the BCP 47 dashed form for HTML)
- `<link rel="canonical" href="...">` — canonical points to the active-locale URL
- `<meta property="og:locale" content="de_DE">` — uses underscore form natively, served directly from `cms.locale.locale`
- `og:locale:alternate` for each available locale
- Locale-aware sitemap with `<xhtml:link rel="alternate" hreflang="...">` annotations per URL (BCP 47 dashed form)
- Locale-aware RSS — supports `?locale=de` to filter to a single language; `dc:language` element set correctly

## REST API

### 3.5 Sliver

Localized fields always serialize as the full `{ "en_US": ..., "de": ... }` object — no resolution happens server-side because there's no active locale.

```json
{
    "id": "my-post",
    "data": {
        "title": { "en_US": "About Us", "de": "Über uns" },
        "body": { "en_US": "...", "de": "..." }
    }
}
```

### 3.6 Full System

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

## CLI Commands (3.6)

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

## Migration Path (3.6)

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

### Phase 0 — 3.5 Sliver (this release)

**Effort: ~1.5 weeks**

- `localizedtext` field type with mixed-case POSIX locale-keyed storage (`en_US`, `de`, `pt_BR`)
- `localizedstyledtext` field type with Tiptap (basic — no per-locale spell check or RTL toggle yet)
- Site-wide `i18n.available` + `i18n.default` config bucket, exposed via `cms.config('i18n', 'available')` / `cms.config('i18n', 'default')`
- Per-field `defaultLocale` setting for fallback
- `cms.locale.text(value, locale)` and `cms.locale.styledtext(value, locale)` Twig accessors (locale required, case-insensitive)
- Helper fallback chain: exact match → region fall-up → region fall-down (configured-order) → field defaultLocale → empty
- Direct array access (`post.title.de`, `post.title['en_US']`) works without a helper
- Admin form: per-field locale tab interface (`defaultLocale` active on render) with `dir` attribute on input/textarea rendering for RTL locales; ~10 lines of delegated-click JS, no framework
- REST API: localized fields serialize as `{ "en_US": ..., "de": ... }` always
- Pro edition is transitively required (custom schemas only; no dedicated `EditionFeature` needed)
- Tests covering storage round-trip, fallback chain, RTL rendering, REST shape, edition gate
- Documentation: field-type docs + manual migration guide from the `field_en` workaround (no CLI tool yet)

**Done:** a Pro-licensed customer building a multi-language site can replace `title_en` / `title_de` field pairs with a single `localizedtext` field, store and retrieve all locale values cleanly, and render them in templates with `cms.locale.text(post.title, 'de')` or `post.title.de`.

### Phase 1 — Foundation (3.6)

**Effort: ~1–1.5 weeks**

- Per-collection schema config for locales (`defaultLocale`, `fallbackLocale`, per-collection locale list overriding site-wide)
- `LocaleContext` service + middleware for active locale resolution (URL → header → cookie → Accept-Language → default)
- URL routing with prefix pattern; unprefixed-URL behavior config
- Server-side cache key inclusion (Twig cache, APCu, fragment cache)
- `cms.locale.text(value)` becomes valid (locale optional, auto-resolves)
- `cms.locale.isFallback()` helper
- Tests covering resolution priority, cache isolation, fallback chains

**Done:** active locale resolves from URL/header/cookie/Accept-Language and Twig calls auto-resolve. Caches don't leak across locales.

### Phase 2 — Content Editing (3.6)

**Effort: ~1–1.5 weeks**

- `localizedslug` field type with per-locale slug index
- Admin form: form-wide locale tab row that switches every localized field in unison (complements 3.5's per-field tabs)
- Object listing: per-locale translation status indicators
- Untranslated-field visual flag in editor
- Tiptap locale-aware spell check and RTL toggle

**Done:** an editor can author a multi-locale blog post end-to-end, including localized slugs, with clear UI for what's translated and what's not.

### Phase 3 — Frontend Integration (3.6)

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

**Done:** theme authors stop writing `{% if cms.locale.locale == 'de' %}` for button labels, and locale-aware number/date formatting works without conditionals.

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

## Effort Summary

| Phase | Effort | Cumulative | Target |
|---|---|---|---|
| 0. 3.5 sliver | ~1.5 weeks | 1.5 weeks | **3.5 ship** |
| 1. Foundation (active locale, routing, cache keys) | 1–1.5 weeks | 3 weeks | 3.6 |
| 2. Content editing (tabs, slugs, status, Tiptap polish) | 1–1.5 weeks | 4.5 weeks | 3.6 |
| 3. Frontend integration (Twig, SEO, REST) | 1 week | 5.5 weeks | 3.6 |
| 4. Polish (`cms.t()`, formatters, template overrides) | 0.5–1 week | 6.5 weeks | 3.6 or 3.6.x |
| 5. Tooling (CLI commands, migration) | 0.5–1 week | 7.5 weeks | 3.6 or 3.6.x |

**Sliver: ~1.5 weeks. Full system after sliver: ~4–5 weeks.** Phases 1–3 are the 3.6 MVP (~3–4 weeks); Phases 4–5 can land in 3.6.x patch releases if 3.6 is otherwise full.

## Interaction With Other Plans

- **MCP server (3.5 ship):** MCP's auto-generated tools include a `locale` parameter from day one (forward-compat). In 3.5 the parameter accepts any of the configured locale codes (`en_US`, `de`, etc.) and returns the `localizedtext` value for that locale. In 3.6, when a request omits the parameter, the active locale resolves automatically.
- **Service Worker (3.7+):** SW cache keys must include locale once active-locale resolution exists (3.6). Plan already specs `Vary: X-Locale, Cookie`.
- **Site Builder:** Site Builder reads `LocaleContext` for active locale (3.6), generates locale-prefixed routes for pages. Per-page locale toggles are a Site Builder feature, deferred to Phase 6.
- **Extensions:** Extensions get access to `LocaleContext` via `ExtensionContext` (3.6). Extension-provided field types can opt into localization by following the `localized*` naming convention.
- **JumpStart:** Localized field values round-trip through JumpStart export/import. Tested in Phase 5.
- **MCP docs server:** Documentation for i18n field types and Twig helpers gets indexed alongside core docs — no extra work required.

## Open Questions

- **Locale code normalization (resolved 2026-05-15).** Helper input is case-insensitive — `'en_us'`, `'EN_US'`, `'En_Us'` all canonicalize to `en_US` (lowercase language, uppercase region). Storage is always the canonical form. Region fall-up (`de_DE` → `de`) and fall-down (`en` → first matching `en_*` in configured order) are part of the helper's lookup chain.
- **Slug collision during migration.** If two existing objects have the same slug across different `_en`/`_de` field combinations, migration needs a clear conflict-resolution policy. Probably: report, skip, let user fix manually.
- **Tiptap RTL toolbar.** Tiptap supports RTL but our toolbar is a custom layer (`TiptapToolbar.js`). Need to verify mirroring works without manual CSS overrides.
- **Cache invalidation on locale config change.** Adding a new locale to a collection should invalidate all cached responses for that collection. Worth a clean event hook on `schema.saved` that detects locale-config changes specifically.
- **`X-Locale` vs `Accept-Language` precedence.** Current plan: explicit wins (header → cookie → Accept-Language). Worth confirming this matches what API clients expect.
- **3.5 sliver: missing-locale behavior in admin (resolved 2026-05-15).** If `cms.config('i18n', 'available')` lists `[en_US, de, ar]` but the field only has `en_US` data, the `de` and `ar` tabs are still rendered with empty inputs. Plain empty in the sliver — honest about state. Per-tab "Not yet translated" indicator badges ship in 3.6.
- **3.5 sliver: what if site config has no locales? (resolved 2026-05-15).** A `localizedtext` field on a site with no `i18n.available` config — refuse to register the field type with a clear error: "Localized field types require `locales` to be configured in tcms.php."
- **Pseudo-localization output format.** Current plan: `[!! Wëlcömé !!]` (bracketed, accented, length-padded). Alternative: configurable so QA can match their expectations. Probably ship the default and add config later if asked.

## What Done Looks Like

### 3.5 Sliver

- `localizedtext` and `localizedstyledtext` field types are available in the schema builder for any custom schema (which requires Pro — same gate as the rest of custom-schema work)
- A site with `locales` configured can use these fields in any schema
- The admin form renders a tab strip per localized field — one tab per configured locale, defaultLocale active on render, RTL `dir` propagated to inputs/textareas
- Stored values use mixed-case POSIX keys (`{ "en_US": ..., "de": ..., "pt_BR": ... }`)
- Direct array access works in Twig: `{{ post.title.de }}` or `{{ post.title['en_US'] }}`
- `cms.locale.text(value, 'de')` and `cms.locale.styledtext(value, 'de')` return the right value, case-insensitive on the locale arg, with the full fallback chain (exact → region fall-up → region fall-down → field defaultLocale → empty)
- Per-field `defaultLocale` fallback works when the requested locale is missing
- REST API serializes localized fields as the full multi-locale object
- A Pro-licensed customer can replace `title_en` / `title_de` field pairs with a single `localizedtext` field and continue building their multi-language site with cleaner storage
- Documentation explains the manual migration path from the workaround
- Existing schemas and content with standard field types are completely unaffected

### 3.6 Full System

Everything in the sliver, plus:

- `localizedslug` field type with per-locale slug routing
- A locale-enabled collection's editor shows locale tabs scoped to localized fields
- Untranslated localized fields are visually flagged
- Object listing pages show per-locale translation status
- Active locale resolves from URL/header/cookie/Accept-Language
- `/en-us/about` and `/de/ueber-uns` both resolve to the right object via locale-aware slug lookup
- Server-side caches don't leak across locales
- `cms.locale.text(post.title)` (no locale arg) auto-resolves to the active locale
- `cms.t('buttons.read_more')` returns the right translated string
- `cms.formatDate`, `cms.formatNumber`, `cms.formatCurrency` produce locale-correct output
- Page-aware locale switcher links to the equivalent translated object
- `<html lang dir>`, hreflang, og:locale, canonical, and locale-aware sitemap/RSS are all wired up
- REST API accepts locale parameter and returns the right shape (with `?expand=locales` available)
- `tcms i18n:export` and `tcms i18n:import` round-trip cleanly
- `tcms i18n:export --pseudo` produces dummy translations usable for pre-launch QA
- `tcms i18n:migrate` converts an existing `field_en`/`field_de` site without data loss
- Sites with no locale configuration are completely unaffected
