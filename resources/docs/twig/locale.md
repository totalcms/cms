---
title: "Twig Locale Reference"
description: "Reference for the cms.locale namespace providing localization, translation, and language management in templates."
---

# cms.locale

The locale adapter provides localization and translation methods for internationalized templates. For broader i18n concepts and setup, see [Localization](docs/twig/localization).

## Language Management

### languages()

Get the list of supported languages as a name-to-locale mapping.

```twig
{% set langs = cms.locale.languages() %}
<select name="language">
    {% for name, code in langs %}
        <option value="{{ code }}">{{ name }}</option>
    {% endfor %}
</select>
```

**Returns:** `array<string, string>` — language display name to locale code (e.g., `{'English': 'en_US', 'Deutsch': 'de_DE'}`)

### set()

Set the active locale for the current request. Requires the PHP `intl` extension.

```twig
{{ cms.locale.set('de_DE') }}
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `locale` | string | Locale code (e.g., `'en_US'`, `'de_DE'`, `'fr_FR'`) |

**Returns:** `string` — empty string (for use in output context)

### get()

Get the current active locale. Defaults to `'en_US'` if the `intl` extension is not available.

```twig
<html lang="{{ cms.locale.get() }}">
```

## Translation

### t()

Translate a key from the admin translation domain. Supports parameter interpolation.

```twig
{{ cms.locale.t('save') }}
{{ cms.locale.t('welcome_message', {name: user.name}) }}
{{ cms.locale.t('item_count', {count: items|length}) }}
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `key` | string | required | Translation key |
| `params` | array | `[]` | Parameters to interpolate into the translation |

### translate()

Alias for `t()`.

```twig
{{ cms.locale.translate('save') }}
```

### jsTranslations()

Get all translations as a flat array for use in JavaScript. Useful for passing translations to frontend code.

```twig
<script>
    window.translations = {{ cms.locale.jsTranslations()|json_encode|raw }};
</script>
```

**Returns:** `array<string, string>` — key-value pairs of all translations
