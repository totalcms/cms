---
title: "Supported Locales"
description: "Reference table of every locale code Total CMS recognizes — code, native-language name, English name, and writing direction. Used by the system locale setting and by content-localization (localizedtext / localizedstyledtext) field types."
audience: intermediate
related:
  - fields/localized-text
---

# Supported Locales

Total CMS ships a curated registry of locale codes used by:

- **Default locale** (Settings → Internationalization → Default Locale) — drives PHP `intl`, CakePHP I18n, and Faker for date / number / currency formatting, the admin UI translation language, and (Pro) the default tab on localized content fields. One setting drives both runtime formatting and the content default. Operators who need the two to differ can override `$settings['locale']` at the top level in `config/tcms.php` — that wins for `$config->locale` while `i18n.default` keeps driving content defaults.
- **Available content locales** (Settings → Internationalization → Available Content Locales) — Pro edition. List of codes that show up as tabs in `localizedtext`, `localizedtextarea`, and `localizedstyledtext` fields.

All codes use the **mixed-case POSIX format** (lowercase language, uppercase region, underscore separator: `en_US`, `pt_BR`, `zh_CN`). Bare language codes (`de`, `fr`, `pt`) are also valid and stand for "any region speaking that language" — useful when a site doesn't care about regional variants.

## Label conventions

The **native name** column is what operators see on locale-picker tabs and in the admin form. Two patterns:

- **Single-variant languages** (Czech, Japanese, Italian, Polish, etc.) get just the language name — `Čeština`, `日本語`, `Italiano`. The country is redundant when only one variant exists.
- **Multi-variant languages** (English, German, French, Spanish, Portuguese, Arabic, Chinese) get the language name plus the ISO 3166 alpha-2 country code in parens — `English (US)`, `Português (BR)`, `Deutsch (AT)`. Keeps the localized-field tab strip compact while still distinguishing variants.

The settings UI picker appends the full POSIX code in square brackets to every label so operators see the code when configuring (`English (US) [en_US]`). The English name column is for documentation reference and English-speaking integrators who don't recognize a native script.

## Registry

| Code | Native name (UI) | English name | Direction |
|------|------------------|--------------|-----------|
| `ar` | العربية | Arabic | RTL |
| `ar_SA` | العربية (SA) | Arabic (Saudi Arabia) | RTL |
| `bn_BD` | বাংলা | Bengali (Bangladesh) | LTR |
| `cs_CZ` | Čeština | Czech (Czechia) | LTR |
| `da_DK` | Dansk | Danish (Denmark) | LTR |
| `de` | Deutsch | German | LTR |
| `de_AT` | Deutsch (AT) | German (Austria) | LTR |
| `de_CH` | Deutsch (CH) | German (Switzerland) | LTR |
| `de_DE` | Deutsch (DE) | German (Germany) | LTR |
| `el_GR` | Ελληνικά | Greek (Greece) | LTR |
| `en` | English | English | LTR |
| `en_AU` | English (AU) | English (Australia) | LTR |
| `en_CA` | English (CA) | English (Canada) | LTR |
| `en_GB` | English (GB) | English (United Kingdom) | LTR |
| `en_SG` | English (SG) | English (Singapore) | LTR |
| `en_US` | English (US) | English (United States) | LTR |
| `es` | Español | Spanish | LTR |
| `es_ES` | Español (ES) | Spanish (Spain) | LTR |
| `es_MX` | Español (MX) | Spanish (Mexico) | LTR |
| `fa_IR` | فارسی | Persian (Iran) | RTL |
| `fi_FI` | Suomi | Finnish (Finland) | LTR |
| `fr` | Français | French | LTR |
| `fr_CA` | Français (CA) | French (Canada) | LTR |
| `fr_FR` | Français (FR) | French (France) | LTR |
| `he_IL` | עברית | Hebrew (Israel) | RTL |
| `hi_IN` | हिन्दी | Hindi (India) | LTR |
| `hu_HU` | Magyar | Hungarian (Hungary) | LTR |
| `id_ID` | Bahasa Indonesia | Indonesian (Indonesia) | LTR |
| `it_IT` | Italiano | Italian (Italy) | LTR |
| `ja_JP` | 日本語 | Japanese (Japan) | LTR |
| `jv_ID` | Basa Jawa | Javanese (Indonesia) | LTR |
| `km_KH` | ខ្មែរ | Khmer (Cambodia) | LTR |
| `ko_KR` | 한국어 | Korean (South Korea) | LTR |
| `ms_MY` | Bahasa Melayu | Malay (Malaysia) | LTR |
| `nl_NL` | Nederlands | Dutch (Netherlands) | LTR |
| `no_NO` | Norsk | Norwegian (Norway) | LTR |
| `pa_IN` | ਪੰਜਾਬੀ | Punjabi (India) | LTR |
| `pl_PL` | Polski | Polish (Poland) | LTR |
| `pt` | Português | Portuguese | LTR |
| `pt_BR` | Português (BR) | Portuguese (Brazil) | LTR |
| `pt_PT` | Português (PT) | Portuguese (Portugal) | LTR |
| `ro_RO` | Română | Romanian (Romania) | LTR |
| `ru_RU` | Русский | Russian (Russia) | LTR |
| `sv_SE` | Svenska | Swedish (Sweden) | LTR |
| `sw_KE` | Kiswahili | Swahili (Kenya) | LTR |
| `ta_IN` | தமிழ் | Tamil (India) | LTR |
| `th_TH` | ไทย | Thai (Thailand) | LTR |
| `tl_PH` | Tagalog | Tagalog (Philippines) | LTR |
| `tr_TR` | Türkçe | Turkish (Turkey) | LTR |
| `uk_UA` | Українська | Ukrainian (Ukraine) | LTR |
| `ur_PK` | اردو | Urdu (Pakistan) | RTL |
| `vi_VN` | Tiếng Việt | Vietnamese (Vietnam) | LTR |
| `zh_CN` | 中文 (CN) | Chinese (Mainland) | LTR |
| `zh_TW` | 中文 (TW) | Chinese (Taiwan) | LTR |

## How the registry is used

| Surface | Reads from | What it shows |
|---|---|---|
| Settings UI dropdowns (default locale) | `LocaleRegistry::options()` | `Native name [code]` — e.g. `Deutsch (DE) [de_DE]` |
| Settings UI list field (available content locales) | `LocaleRegistry::options()` | Same — autocomplete suggests matching codes as the operator types |
| Localized field tabs in the admin form | `LocaleRegistry::expand()` (driven by `Config::$i18n['available']`) | Native name on the tab; `dir` propagates to inputs |
| Twig: `cms.locale.languages()` | `LocaleRegistry::all()` | `{ "Deutsch": "de", ... }` (label → code map for custom pickers) |

The registry is the **single source of truth** for what locales T3 understands. Adding a new locale anywhere means adding to the registry (`src/Domain/Locale/LocaleRegistry.php`) — every consumer picks it up automatically.
