---
title: "Localized Text Fields"
description: "Multi-language text and styled-text fields with one value per configured locale. Pro edition only."
audience: intermediate
related:
  - fields/text-inputs
  - fields/styled-text
  - fields/all-fields
---

# Localized Text Fields

Total CMS includes two field types for content that needs to ship in more than one language:

- **`localizedtext`** — plain-text input, one value per configured locale.
- **`localizedstyledtext`** — Tiptap rich-text editor, one HTML value per configured locale.

Both store a JSON dict keyed by locale code (`{ "en_US": "About Us", "de": "Über uns" }`) instead of a single string. The admin form renders one labeled input per locale and templates can read either the raw dict directly or call a helper that handles fallback.

> **Pro edition required.** Localized field types are used inside custom schemas, which require the Pro edition. Lite and Standard licenses can't create or edit custom schemas, so these field types are out of reach. Existing data on a site that downgrades from Pro still renders, but the schema editor is locked.

## Configuring Locales

Add your locale list to `config/tcms.php` (or your `tcms-data/.system/settings.json`) before adding any localized fields. Settings live under the `i18n` bucket:

```php
return [
    'i18n' => [
        'default'   => 'en_US',
        'available' => [
            ['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'],
            ['code' => 'de',    'label' => 'Deutsch',      'dir' => 'ltr'],
            ['code' => 'ar',    'label' => 'العربية',     'dir' => 'rtl'],
        ],
    ],
];
```

Each entry in `available` has:

- **`code`** — mixed-case POSIX locale code (`en_US`, `pt_BR`, `zh_Hans`). Bare language codes (`de`, `fr`) are valid too. This is the same format PHP's `intl` extension, CakePHP I18n, and Total CMS's admin translations already use.
- **`label`** — human-readable name shown on the locale tab in the admin form.
- **`dir`** — `ltr` or `rtl`. Required. Sets the `dir` attribute on each input so right-to-left locales render correctly.

The **order of the `available` array matters** — when a template calls `cms.locale.text(value, 'en')` and the dict has `en_US` and `en_GB`, the helper returns the first matching entry in the order you list them here.

`i18n.default` is the field-level fallback used by `__toString()` and the helper's last fallback step. It also drives which tab is active when the admin form first renders.

If `i18n.available` is empty, the field types refuse to render with a clear error message in the admin form.

## Using the Fields in Schemas

Pick **Localization Fields → Localized Text** (or **Localized Styled Text**) in the schema editor's field-type dropdown. The rest of the schema property options work the same way as the regular text / styled-text fields.

The stored shape looks like this:

```json
{
    "id": "about-us",
    "title": {
        "en_US": "About Us",
        "de":    "Über uns",
        "ar":    "معلومات عنا"
    },
    "body": {
        "en_US": "<p>Welcome…</p>",
        "de":    "<p>Willkommen…</p>",
        "ar":    "<p>مرحبا…</p>"
    }
}
```

## Reading Localized Values in Twig

### Direct array access

The simplest case: just look up the locale key on the field value.

```twig
{{ post.title.de }}           {# bare-language key #}
{{ post.title['en_US'] }}     {# region-coded key — must use bracket syntax #}
```

This is the lightest path when you know the locale exists and you don't need fallback.

### The `cms.locale.*` helpers

When you want a deterministic fallback chain, use the helper:

```twig
{{ cms.locale.text(post.title, 'de') }}
{{ cms.locale.styledtext(post.body, 'de')|raw }}
```

The helper canonicalizes the requested locale (case-insensitive — `'en_us'`, `'EN_US'`, and `'En_Us'` all become `en_US`) and walks this lookup order:

1. **Exact match** — `value[canonical]`
2. **Region fall-up** — if the request was `de_DE`, fall to bare `de`
3. **Region fall-down** — if the request was bare `en`, return the first matching `en_*` entry in your `i18n.available` order
4. **Empty string**

```twig
{# `de_DE` falls up to `de` when only the bare code is in the dict #}
{{ cms.locale.text({"en_US": "A", "de": "B"}, 'de_DE') }}  {# → "B" #}

{# `en` falls down to the first en_* in cms.config('i18n', 'available') order #}
{{ cms.locale.text({"en_US": "A", "en_GB": "B"}, 'en') }}  {# → "A" or "B" depending on configured order #}
```

### Rendering a per-locale stack

```twig
{% for locale in cms.config('i18n', 'available') %}
    <section lang="{{ locale.code|replace({'_': '-'}) }}" dir="{{ locale.dir }}">
        <h2>{{ locale.label }}</h2>
        {{ cms.locale.styledtext(post.body, locale.code)|raw }}
    </section>
{% endfor %}
```

## REST API

The REST API serializes localized fields as the full multi-locale dict in 3.5:

```json
{
    "id": "about-us",
    "data": {
        "title": { "en_US": "About Us", "de": "Über uns" }
    }
}
```

The 3.6 release will add a `?locale=` parameter that resolves to a single string for the active locale, with `?expand=locales` available for clients that still want the full dict.

## Migrating From the `field_en` / `field_de` Workaround

If you've been splitting locales into separate fields (`title_en`, `title_de`, `body_en`, `body_de`), you can consolidate them manually:

1. Configure your `i18n.available` locales in `tcms.php` (see above).
2. Edit the schema. Replace the per-locale fields with a single localized field (same base name — e.g. `title`). Save the schema.
3. For each existing object, edit it and copy each per-locale value into the matching locale input in the new localized field.
4. Once the new field has the data, remove the old `title_en` / `title_de` fields from the schema.

A `tcms i18n:migrate` CLI command that automates this is planned for 3.6.

## What Doesn't Ship in 3.5

This is the deliberate first slice. The full i18n system in 3.6 adds:

- Locale-aware URL routing (`/de/about`)
- Active-locale auto-resolution (`cms.locale.text(post.title)` with no locale arg)
- Admin locale tabs (replacing the labeled-inputs UI)
- `localizedslug` field type
- `cms.locale.t()` is already available for static UI strings; locale-aware date / number / currency formatters arrive in 3.6
- SEO helpers (hreflang, og:locale, locale-aware sitemap)
- REST API `?locale=` parameter and `?expand=locales`
- `tcms i18n:status`, `i18n:missing`, `i18n:export`, `i18n:import`, `i18n:migrate` CLI commands

The storage shape and Twig signatures locked in 3.5 are forward-compatible with all of that — no template rewrites or data migration when you upgrade to 3.6.
