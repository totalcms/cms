---
title: "Localized Text Fields"
description: "Multi-language text, textarea, and styled-text fields with one value per configured locale. Pro edition only."
audience: intermediate
related:
  - fields/text-inputs
  - fields/styled-text
  - fields/all-fields
---

# Localized Text Fields

Total CMS includes three field types for content that needs to ship in more than one language:

- **`localizedtext`** — single-line plain-text input, one value per configured locale.
- **`localizedtextarea`** — multi-line plain-text input for longer content (descriptions, notes, summaries).
- **`localizedstyledtext`** — Tiptap rich-text editor, one HTML value per configured locale.

All three store a JSON dict keyed by locale code instead of a single string. The admin form renders one labeled input per locale and templates can read either the raw dict directly or call a helper that handles fallback.

> **Pro edition required.** Localized field types are used inside custom schemas, which require the Pro edition. Lite and Standard licenses can't create or edit custom schemas, so these field types are out of reach. Existing data on a site that downgrades from Pro still renders, but the schema editor is locked.

## Configuring Locales

Configure the site's locales in **Settings → Internationalization** before adding any localized fields. Set a **Default Locale** and add each code you plan to localize content into (e.g. `en_US`, `de`, `ar`) under **Available Content Locales**. The order you list them in matters — see the helper fallback rules below.

If no available locales are configured, the field types refuse to render with a clear error message in the admin form.

> **Power user note:** locales can also be configured in code — see [Advanced: Configuring Locales in Code](#advanced-configuring-locales-in-code) at the bottom of this page.

## Using the Fields in Schemas

Pick **Localization Fields → Localized Text**, **Localized Textarea**, or **Localized Styled Text** in the schema editor's field-type dropdown. The rest of the schema property options work the same way as the regular text / textarea / styled-text fields.

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
{{ post.title.en_US }}
{{ post.title.de }}
```

This is the lightest path when you know the locale exists and you don't need fallback.

### The `cms.locale.*` helpers

When you want a deterministic fallback chain, use the helper:

```twig
{{ cms.locale.text(post.title, 'de') }}        {# localizedtext + localizedtextarea #}
{{ cms.locale.styledtext(post.body, 'de') }}   {# localizedstyledtext #}
```

`cms.locale.text()` handles both *localizedtext* and *localizedtextarea* content (they're both plain strings). Use `cms.locale.styledtext()` for *localizedstyledtext* — the HTML renders as-is since Total CMS ships with Twig autoescape disabled.

The helper canonicalizes the requested locale (case-insensitive — *'en_us'*, *'EN_US'*, and *'En_Us'* all become *en_US*) and walks this lookup order:

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
        {{ cms.locale.styledtext(post.body, locale.code) }}
    </section>
{% endfor %}
```


## Migrating From the `field_en` / `field_de` Workaround

If you've been splitting locales into separate fields (`title_en`, `title_de`, `body_en`, `body_de`), you can consolidate them manually:

1. Configure your locales the settings (see above).
2. Export your collection to a CSV.
3. Edit the schema. Replace the per-locale fields with a single localized field (same base name — e.g. `title`). Save the schema.
4. Rename the headers the old CSV from `title_en` / `title_de` to `title.en_US` / `title.de`.

See a list of [currently supported locales](docs/operations/supported-locales).

## Advanced: Configuring Locales in Code

Most sites should use **Settings → Internationalization** in the admin. If you'd rather pin locale config in version control (or you're scripting bulk site provisioning), add a `i18n` bucket to `config/tcms.php`:

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

**Precedence:** `config/tcms.php` is loaded as a default *before* `settings.json`. Whatever the operator saves through Settings → Internationalization overwrites the matching keys at runtime, so tcms.php is effectively an install-time seed — not a permanent override. If you want code to be the source of truth, just don't touch the i18n section in the admin Settings UI on that install.
