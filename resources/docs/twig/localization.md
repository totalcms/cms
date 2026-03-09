---
title: "Localization & Internationalization"
description: "Localize dates, numbers, currencies, and relative time in Total CMS Twig templates with support for 25+ languages and locale-aware formatting."
---

# Localization & Internationalization

Total CMS provides comprehensive support for localizing dates, numbers, currencies, and relative time strings. This guide covers the supported languages and how to use localization features in your Twig templates.

## Setting the Default Locale

The default locale is configured in **Settings > General > Locale**. This affects all date, number, and currency formatting throughout your site.

## Supported Languages

The following languages have full localization support, including translated relative time strings:

| Language | Locale Code | Example Relative Time |
|----------|-------------|----------------------|
| Arabic | `ar_SA` | "منذ شهرين" |
| Czech | `cs_CZ` | "před 2 měsíci" |
| Danish | `da_DK` | "for 2 måneder siden" |
| Dutch | `nl_NL` | "2 maanden geleden" |
| English (US) | `en_US` | "2 months ago" |
| English (UK) | `en_GB` | "2 months ago" |
| English (Canada) | `en_CA` | "2 months ago" |
| English (Australia) | `en_AU` | "2 months ago" |
| English (Singapore) | `en_SG` | "2 months ago" |
| French (France) | `fr_FR` | "il y a 2 mois" |
| French (Canada) | `fr_CA` | "il y a 2 mois" |
| German | `de_DE` | "vor 2 Monaten" |
| Hungarian | `hu_HU` | "2 hónapja" |
| Italian | `it_IT` | "2 mesi fa" |
| Japanese | `ja_JP` | "2 か月前" |
| Khmer (Cambodia) | `km_KH` | "2 months ago" * |
| Norwegian | `no_NO` | "for 2 måneder siden" |
| Polish | `pl_PL` | "2 miesiące temu" |
| Portuguese (Brazil) | `pt_BR` | "há 2 meses" |
| Portuguese (Portugal) | `pt_PT` | "há 2 meses" |
| Russian | `ru_RU` | "2 месяца назад" |
| Spanish (Spain) | `es_ES` | "hace 2 meses" |
| Spanish (Mexico) | `es_MX` | "hace 2 meses" |
| Turkish | `tr_TR` | "2 ay önce" |
| Ukrainian | `uk_UA` | "2 місяці тому" |
| Vietnamese | `vi_VN` | "2 tháng trước" |
| Chinese (Simplified) | `zh_CN` | "2 个月前" |

\* Khmer has partial support - date/number formatting works but relative time falls back to English.

## Multilingual Sites

For sites with multiple languages, you can change the locale dynamically within your templates.

### Setting the Locale

Use `cms.locale.set()` at the top of your template to change the locale for all subsequent formatting:

```twig
{# Set locale based on page language #}
{{ cms.locale.set('de_DE') }}

{# All formatting now uses German #}
{{ post.date|dateRelative }}
{# Output: "vor 2 Monaten" #}
```

### Getting the Current Locale

```twig
{{ cms.locale.get() }}
{# Output: "de_DE" #}
```

### Dynamic Locale from Content

If your content has a language field, you can set the locale dynamically:

```twig
{# Assuming page.locale contains the locale code #}
{{ cms.locale.set(page.locale) }}

<article>
    <h1>{{ page.title }}</h1>
    <time>{{ page.date|dateRelative }}</time>
</article>
```

## Date Formatting

### Relative Time

The `dateRelative` filter displays dates in a human-readable relative format:

```twig
{{ post.date|dateRelative }}
{# Output varies by locale: #}
{# en_US: "2 months ago" #}
{# de_DE: "vor 2 Monaten" #}
{# fr_FR: "il y a 2 mois" #}
{# ja_JP: "2 か月前" #}
```

### Formatted Dates

Use `format_date` for locale-aware date formatting:

```twig
{# Short format #}
{{ post.date|format_date('short') }}
{# en_US: "12/31/25" #}
{# de_DE: "31.12.25" #}

{# Medium format #}
{{ post.date|format_date('medium') }}
{# en_US: "Dec 31, 2025" #}
{# de_DE: "31.12.2025" #}

{# Long format #}
{{ post.date|format_date('long') }}
{# en_US: "December 31, 2025" #}
{# de_DE: "31. Dezember 2025" #}

{# Full format #}
{{ post.date|format_date('full') }}
{# en_US: "Wednesday, December 31, 2025" #}
{# de_DE: "Mittwoch, 31. Dezember 2025" #}
```

### Date and Time

Use `format_datetime` for both date and time:

```twig
{{ post.date|format_datetime('medium', 'short') }}
{# en_US: "Dec 31, 2025, 3:30 PM" #}
{# de_DE: "31.12.2025, 15:30" #}
```

### Time Only

```twig
{{ post.date|format_time('short') }}
{# en_US: "3:30 PM" #}
{# de_DE: "15:30" #}
```

## Number Formatting

Numbers are formatted according to locale conventions:

```twig
{{ 1234567.89|format_number }}
{# en_US: "1,234,567.89" #}
{# de_DE: "1.234.567,89" #}
{# fr_FR: "1 234 567,89" #}
```

### Percentages

```twig
{{ 0.1525|format_percent_number }}
{# en_US: "15%" #}
{# de_DE: "15 %" #}
```

### Scientific Notation

```twig
{{ 1234567|format_scientific_number }}
{# Output: "1.234567E6" #}
```

### Spelled Out Numbers

```twig
{{ 42|format_spellout_number }}
{# en_US: "forty-two" #}
{# de_DE: "zwei­und­vierzig" #}
```

## Currency Formatting

Currencies are formatted with proper symbols and decimal placement:

```twig
{{ 99.99|format_currency('USD') }}
{# en_US: "$99.99" #}
{# de_DE: "99,99 $" #}

{{ 99.99|format_currency('EUR') }}
{# en_US: "€99.99" #}
{# de_DE: "99,99 €" #}

{{ 99.99|format_currency('JPY') }}
{# ja_JP: "￥100" (no decimals for Yen) #}

{{ 99.99|format_currency('SGD') }}
{# en_SG: "S$99.99" #}
```

## Localized Names

### Country Names

```twig
{{ 'US'|country_name }}
{# en_US: "United States" #}
{# de_DE: "Vereinigte Staaten" #}
{# ja_JP: "アメリカ合衆国" #}
```

### Language Names

```twig
{{ 'de'|language_name }}
{# en_US: "German" #}
{# de_DE: "Deutsch" #}
{# fr_FR: "allemand" #}
```

### Currency Names

```twig
{{ 'EUR'|currency_name }}
{# en_US: "Euro" #}
{# de_DE: "Euro" #}
{# ja_JP: "ユーロ" #}

{{ 'USD'|currency_symbol }}
{# Output: "$" #}
```

### Timezone Names

```twig
{{ 'America/New_York'|timezone_name }}
{# en_US: "Eastern Time" #}
{# de_DE: "Nordamerikanische Ostküstenzeit" #}
```

## Complete Example

Here's a complete example of a multilingual blog post template:

```twig
{# Set locale from page content #}
{{ cms.locale.set(post.locale|default('en_US')) }}

<article class="blog-post" lang="{{ post.locale|default('en_US')|slice(0,2) }}">
    <header>
        <h1>{{ post.title }}</h1>
        <div class="meta">
            <time datetime="{{ post.date|date('c') }}">
                {{ post.date|format_date('long') }}
            </time>
            <span class="relative-time">
                ({{ post.date|dateRelative }})
            </span>
        </div>
    </header>

    <div class="content">
        {{ post.content|raw }}
    </div>

    {% if post.price %}
    <div class="price">
        {{ post.price|format_currency(post.currency|default('USD')) }}
    </div>
    {% endif %}
</article>
```

## Date Format Options

Available format options for `format_date`, `format_datetime`, and `format_time`:

| Option | Description | Example (en_US) |
|--------|-------------|-----------------|
| `none` | No output | - |
| `short` | Abbreviated | "12/31/25" |
| `medium` | Standard | "Dec 31, 2025" |
| `long` | Full month name | "December 31, 2025" |
| `full` | Complete with weekday | "Wednesday, December 31, 2025" |
