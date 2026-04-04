## Project Brief: Internationalization (i18n)

**Goal**
Give T3 native multi-language support through a new family of locale-aware field types. The current workaround of `title_en`, `title_de` field naming works but doesn't scale — it pollutes schemas, complicates templates, and breaks down entirely when language lists grow. Native i18n makes T3 credible for international client work and opens a market segment currently underserved by flat-file CMS platforms.

**Constraints**
- Existing sites, schemas, and content are completely unaffected — this is purely additive
- Implemented as new field types, not a system-wide storage format change
- Locale configuration is per-collection, not forced globally
- Content editors should not need to understand anything technical to switch languages
- Must integrate cleanly with Site Builder URL routing (`/en/about`, `/de/about`)
- REST API must expose locale-aware responses for localized field types

---

### The Core Model

Rather than changing how existing fields store data, i18n is delivered as a new family of field types. A developer building a multilingual site makes a deliberate choice to use a localized field type where needed. Everything else in T3 is unchanged.

**New field types:**
- `localizedtext` — plain text, locale-aware
- `localizedstyledtext` — rich text, locale-aware
- `localizedimage` — future consideration (alt text and sometimes the image itself varies by locale)

A `localizedtext` field stores values keyed by locale internally:

```json
{
    "title": {
        "en": "About Us",
        "de": "Über uns",
        "fr": "À propos"
    }
}
```

A standard `text` field on the same object is completely untouched:

```json
{
    "slug": "about-us",
    "title": {
        "en": "About Us",
        "de": "Über uns",
        "fr": "À propos"
    }
}
```

---

### Locale Configuration

Defined at the collection level in schema settings. Only collections that need translation are configured — collections with no locale config behave exactly as today:

```json
{
    "locales": ["en", "de", "fr"],
    "defaultLocale": "en",
    "fallbackLocale": "en"
}
```

**Fallback behavior:** if a localized field hasn't been translated yet for the active locale, T3 returns the default locale value rather than empty. Partially translated sites degrade gracefully.

---

### Admin Interface

For objects in a locale-enabled collection, the content editing form shows a locale tab row scoped only to localized field types:

```
[ English ] [ Deutsch ] [ Français ]
```

Non-localized fields (dates, toggles, slugs, numbers) appear below the tabs with no locale UI — they have one value regardless of language. Switching locale tabs shows the localized fields populated with that locale's values. Untranslated localized fields are visually flagged with a "Not yet translated" indicator. Saving writes only the active locale's values.

---

### Twig Integration

`cms.localizedtext()` auto-resolves to the active locale — template authors don't need to specify the locale explicitly:

```twig
{# Returns the correct locale value automatically #}
{{ cms.localizedtext('title') }}

{# Explicit locale override when needed #}
{{ cms.localizedtext('title', 'de') }}
```

Three new helpers for locale-aware templates:

```twig
{# Get current active locale #}
{{ cms.locale }}

{# Generate a locale-aware URL #}
{{ cms.localeUrl('/about', 'de') }}

{# Render a locale switcher — outputs a <ul> of language links #}
{# Fully overridable with a custom template #}
{{ cms.localeSwitcher() }}
```

A `hreflang` partial is provided out of the box for Site Builder users — drop it into your layout and T3 generates the correct `<link rel="alternate">` tags automatically.

---

### URL Routing

Two supported patterns, configured per-site:

**Prefix pattern (recommended):**
```
/en/about
/de/about
/fr/about
```

**Domain pattern:**
```
en.example.com/about
de.example.com/about
```

The prefix pattern is the default — simpler to implement, works on any hosting setup without DNS changes. The domain pattern is documented clearly for users who need it.

T3 sets the active locale from the URL prefix before any template rendering occurs. Site Builder's routing layer reads this and passes it into the Twig environment.

---

### REST API

Locale specified via query parameter or header:

```
GET /api/collections/blog?locale=de
GET /api/collections/blog/my-post?locale=de
X-Locale: de
```

Response includes locale metadata for objects in locale-enabled collections:

```json
{
    "id": "my-post",
    "locale": "de",
    "availableLocales": ["en", "de", "fr"],
    "translated": {
        "en": true,
        "de": true,
        "fr": false
    },
    "data": { ... }
}
```

---

### CLI Commands

```bash
# Show translation status across all locale-enabled collections
tcms i18n:status

# Show untranslated localized fields for a specific locale
tcms i18n:missing --locale=de

# Export untranslated strings for a locale to JSON
tcms i18n:export --locale=de --output=translations-de.json

# Import translated strings back
tcms i18n:import --locale=de --input=translations-de.json
```

The export/import pair is the AI content generation hook — export untranslated strings, pipe through an AI translation service, import results. T3 doesn't own the AI integration, just provides clean in/out.

---

### Migration Path for Existing Sites

For users currently using the `field_en`, `field_de` workaround:

```bash
tcms i18n:migrate --collection=blog --fields=title,body --locales=en,de
```

This command:
1. Reads existing flat field values (`title_en`, `title_de`)
2. Creates a new `localizedtext` field in the schema (`title`)
3. Writes the existing values into the correct locale keys in the new field
4. Removes the old flat fields from the schema
5. Backs up original data before touching anything
6. Reports what changed and what was skipped

Always explicit opt-in — never automatic.

---

### What Done Looks Like

- A new `localizedtext` field type is available in schema builder
- Existing schemas and content with standard field types are completely unaffected
- Admin content editing form for a locale-enabled collection shows locale tabs scoped to localized fields only
- Non-localized fields on the same form show no tabs and behave exactly as today
- Untranslated localized fields are visually flagged in the admin
- Fallback to default locale works when a translation is missing
- `/en/about` and `/de/about` both resolve and render the correct locale content
- `{{ cms.localizedtext('title') }}` auto-resolves to the active locale
- `hreflang` tags are generated correctly for Site Builder pages
- REST API accepts locale parameter and returns locale-aware data for localized fields
- `tcms i18n:export` and `tcms i18n:import` round-trip correctly
- `tcms i18n:migrate` successfully converts an existing `field_en`/`field_de` site without data loss
- Sites with no locale configuration are completely unaffected
