<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Locale;

/**
 * Canonical registry of locale codes T3 understands.
 *
 * Maps mixed-case POSIX codes → native-language label + English name + writing direction.
 * Used by:
 *   - The i18n settings UI (system locale select, default-locale select,
 *     available-languages list) — `propertyOptions: "locales"` resolves here.
 *   - `Config::__construct()` to expand a flat array of operator-chosen codes
 *     into the full `[{code, label, dir}, ...]` shape that FormFields and
 *     the Twig adapter consume.
 *   - `LocaleTwigAdapter::languages()` (delegates to `LocaleRegistry::all()`).
 *   - The `Supported Locales` reference docs page (lists every code).
 *
 * `label` is written in the language it describes (Deutsch, Português,
 * العربية, 日本語) — that's the right convention for any locale-picker UI:
 * a speaker of the language always sees their language's name in their
 * own script, regardless of the visitor's current locale.
 *
 * `english` is the English name of the locale — used in docs and search-only
 * surfaces where an English-speaking integrator needs to find a code without
 * recognizing the native script.
 *
 * Add new locales here. The list is intentionally curated, not operator-
 * extensible in 3.5 — keeps labels consistent across sites and prevents
 * typo-driven inconsistencies. Extensibility hook is a 3.6+ concern.
 */
final class LocaleRegistry
{
	/**
	 * @var array<string,array{label: string, english: string, dir: string}>
	 */
	// Label conventions:
	//   • Single-variant languages get just the native language name (no parens) —
	//     `Čeština`, `日本語`, `Italiano`, etc. The country is redundant when only
	//     one variant exists.
	//   • Multi-variant languages get the native name plus the ISO 3166 alpha-2
	//     country code in parens — `English (US)`, `Português (BR)`, `Deutsch (AT)`.
	//     This keeps the localized-field tab strip compact while still
	//     distinguishing regional variants.
	//   • Bare codes (`en`, `de`, `pt`, etc.) stay as just the language name.
	//
	// The settings UI picker appends the full POSIX code to every label via
	// `LocaleRegistry::options()` ("English (US) (en_US)") so operators still
	// see the code when configuring. The `english` column carries the full
	// country name for the docs reference and English-speaking integrators.
	public const LOCALES = [
		'ar'    => ['label' => 'العربية',                  'english' => 'Arabic',                  'dir' => 'rtl'],
		'ar_SA' => ['label' => 'العربية (SA)',             'english' => 'Arabic (Saudi Arabia)',   'dir' => 'rtl'],
		'bn_BD' => ['label' => 'বাংলা',                     'english' => 'Bengali (Bangladesh)',    'dir' => 'ltr'],
		'cs_CZ' => ['label' => 'Čeština',                  'english' => 'Czech (Czechia)',         'dir' => 'ltr'],
		'da_DK' => ['label' => 'Dansk',                    'english' => 'Danish (Denmark)',        'dir' => 'ltr'],
		'de'    => ['label' => 'Deutsch',                  'english' => 'German',                  'dir' => 'ltr'],
		'de_AT' => ['label' => 'Deutsch (AT)',             'english' => 'German (Austria)',        'dir' => 'ltr'],
		'de_CH' => ['label' => 'Deutsch (CH)',             'english' => 'German (Switzerland)',    'dir' => 'ltr'],
		'de_DE' => ['label' => 'Deutsch (DE)',             'english' => 'German (Germany)',        'dir' => 'ltr'],
		'el_GR' => ['label' => 'Ελληνικά',                 'english' => 'Greek (Greece)',          'dir' => 'ltr'],
		'en'    => ['label' => 'English',                  'english' => 'English',                 'dir' => 'ltr'],
		'en_AU' => ['label' => 'English (AU)',             'english' => 'English (Australia)',     'dir' => 'ltr'],
		'en_CA' => ['label' => 'English (CA)',             'english' => 'English (Canada)',        'dir' => 'ltr'],
		'en_GB' => ['label' => 'English (GB)',             'english' => 'English (United Kingdom)', 'dir' => 'ltr'],
		'en_SG' => ['label' => 'English (SG)',             'english' => 'English (Singapore)',     'dir' => 'ltr'],
		'en_US' => ['label' => 'English (US)',             'english' => 'English (United States)', 'dir' => 'ltr'],
		'es'    => ['label' => 'Español',                  'english' => 'Spanish',                 'dir' => 'ltr'],
		'es_ES' => ['label' => 'Español (ES)',             'english' => 'Spanish (Spain)',         'dir' => 'ltr'],
		'es_MX' => ['label' => 'Español (MX)',             'english' => 'Spanish (Mexico)',        'dir' => 'ltr'],
		'fa_IR' => ['label' => 'فارسی',                    'english' => 'Persian (Iran)',          'dir' => 'rtl'],
		'fi_FI' => ['label' => 'Suomi',                    'english' => 'Finnish (Finland)',       'dir' => 'ltr'],
		'fr'    => ['label' => 'Français',                 'english' => 'French',                  'dir' => 'ltr'],
		'fr_CA' => ['label' => 'Français (CA)',            'english' => 'French (Canada)',         'dir' => 'ltr'],
		'fr_FR' => ['label' => 'Français (FR)',            'english' => 'French (France)',         'dir' => 'ltr'],
		'he_IL' => ['label' => 'עברית',                    'english' => 'Hebrew (Israel)',         'dir' => 'rtl'],
		'hi_IN' => ['label' => 'हिन्दी',                     'english' => 'Hindi (India)',           'dir' => 'ltr'],
		'hu_HU' => ['label' => 'Magyar',                   'english' => 'Hungarian (Hungary)',     'dir' => 'ltr'],
		'id_ID' => ['label' => 'Bahasa Indonesia',         'english' => 'Indonesian (Indonesia)',  'dir' => 'ltr'],
		// `it` (bare) intentionally omitted — Italian-of-Italy is the universal
		// Italian and the bare code added picker noise without semantic benefit.
		// Same rule applies to `ja` below.
		'it_IT' => ['label' => 'Italiano',                 'english' => 'Italian (Italy)',         'dir' => 'ltr'],
		'ja_JP' => ['label' => '日本語',                   'english' => 'Japanese (Japan)',        'dir' => 'ltr'],
		'jv_ID' => ['label' => 'Basa Jawa',                'english' => 'Javanese (Indonesia)',    'dir' => 'ltr'],
		'km_KH' => ['label' => 'ខ្មែរ',                      'english' => 'Khmer (Cambodia)',        'dir' => 'ltr'],
		'ko_KR' => ['label' => '한국어',                    'english' => 'Korean (South Korea)',    'dir' => 'ltr'],
		'ms_MY' => ['label' => 'Bahasa Melayu',            'english' => 'Malay (Malaysia)',        'dir' => 'ltr'],
		'nl_NL' => ['label' => 'Nederlands',               'english' => 'Dutch (Netherlands)',     'dir' => 'ltr'],
		'no_NO' => ['label' => 'Norsk',                    'english' => 'Norwegian (Norway)',      'dir' => 'ltr'],
		'pa_IN' => ['label' => 'ਪੰਜਾਬੀ',                     'english' => 'Punjabi (India)',         'dir' => 'ltr'],
		'pl_PL' => ['label' => 'Polski',                   'english' => 'Polish (Poland)',         'dir' => 'ltr'],
		'pt'    => ['label' => 'Português',                'english' => 'Portuguese',              'dir' => 'ltr'],
		'pt_BR' => ['label' => 'Português (BR)',           'english' => 'Portuguese (Brazil)',     'dir' => 'ltr'],
		'pt_PT' => ['label' => 'Português (PT)',           'english' => 'Portuguese (Portugal)',   'dir' => 'ltr'],
		'ro_RO' => ['label' => 'Română',                   'english' => 'Romanian (Romania)',      'dir' => 'ltr'],
		'ru_RU' => ['label' => 'Русский',                 'english' => 'Russian (Russia)',        'dir' => 'ltr'],
		'sv_SE' => ['label' => 'Svenska',                  'english' => 'Swedish (Sweden)',        'dir' => 'ltr'],
		'sw_KE' => ['label' => 'Kiswahili',                'english' => 'Swahili (Kenya)',         'dir' => 'ltr'],
		'ta_IN' => ['label' => 'தமிழ்',                     'english' => 'Tamil (India)',           'dir' => 'ltr'],
		'th_TH' => ['label' => 'ไทย',                      'english' => 'Thai (Thailand)',         'dir' => 'ltr'],
		'tl_PH' => ['label' => 'Tagalog',                  'english' => 'Tagalog (Philippines)',   'dir' => 'ltr'],
		'tr_TR' => ['label' => 'Türkçe',                   'english' => 'Turkish (Turkey)',        'dir' => 'ltr'],
		'uk_UA' => ['label' => 'Українська',              'english' => 'Ukrainian (Ukraine)',     'dir' => 'ltr'],
		'ur_PK' => ['label' => 'اردو',                      'english' => 'Urdu (Pakistan)',         'dir' => 'rtl'],
		'vi_VN' => ['label' => 'Tiếng Việt',               'english' => 'Vietnamese (Vietnam)',    'dir' => 'ltr'],
		'zh_CN' => ['label' => '中文 (CN)',                'english' => 'Chinese (Mainland)',      'dir' => 'ltr'],
		'zh_TW' => ['label' => '中文 (TW)',                'english' => 'Chinese (Taiwan)',        'dir' => 'ltr'],
	];

	/**
	 * Return the full registry.
	 *
	 * @return array<string,array{label: string, english: string, dir: string}>
	 */
	public static function all(): array
	{
		return self::LOCALES;
	}

	/**
	 * Return registry entries formatted for a `<select>` / list-field
	 * `propertyOptions` source — each entry is `['value' => code, 'label' => "label [code]"]`.
	 * The code is appended in square brackets so operators can see at a glance
	 * what code they're picking. Brackets are deliberately distinct from the
	 * parens used by regional-variant labels (`English (US)`) to avoid the
	 * `English (US) (en_US)` double-paren collision.
	 *
	 * @return array<int,array{value: string, label: string}>
	 */
	public static function options(): array
	{
		$out = [];
		foreach (self::LOCALES as $code => $meta) {
			$out[] = [
				'value' => $code,
				'label' => $meta['label'] . ' [' . $code . ']',
			];
		}

		return $out;
	}

	/**
	 * Whether a locale code is in the registry.
	 */
	public static function has(string $code): bool
	{
		return isset(self::LOCALES[$code]);
	}

	/**
	 * Return the metadata for a single code (label + english + dir) or null
	 * if unknown.
	 *
	 * @return array{label: string, english: string, dir: string}|null
	 */
	public static function meta(string $code): ?array
	{
		return self::LOCALES[$code] ?? null;
	}

	/**
	 * Expand a flat list of locale codes into the dict-of-dicts shape
	 * consumed by `LocalizedtextField` and `LocaleTwigAdapter::text()`:
	 * `[['code' => 'en_US', 'label' => 'English (US)', 'dir' => 'ltr'], ...]`.
	 *
	 * Unknown codes are dropped (silent). Order is preserved.
	 *
	 * @param array<int,string> $codes
	 *
	 * @return array<int,array{code: string, label: string, dir: string}>
	 */
	public static function expand(array $codes): array
	{
		$out = [];
		foreach ($codes as $code) {
			if (!isset(self::LOCALES[$code])) {
				continue;
			}
			$meta  = self::LOCALES[$code];
			$out[] = [
				'code'  => $code,
				'label' => $meta['label'],
				'dir'   => $meta['dir'],
			];
		}

		return $out;
	}

	/**
	 * Lenient counterpart to `expand()` — accepts the locale-data input in
	 * either of two shapes and returns the canonical dict-of-dicts form:
	 *
	 *   - **Flat code list** (canonical 3.5): `['en_US', 'de', 'ar']` —
	 *     looked up against the registry and expanded; unknown codes drop.
	 *   - **Pre-expanded dicts** (legacy sliver): `[['code' => 'en_US',
	 *     'label' => ..., 'dir' => ...], ...]` — coerced into the canonical
	 *     shape with string keys/values, missing `label` falls back to code,
	 *     missing `dir` falls back to `ltr`.
	 *
	 * Any other shape (non-array input, an empty list, an entry that isn't
	 * a string or `['code' => ...]` dict) yields an empty result.
	 *
	 * Used by `Config::normalizeI18nSettings()` so the operator's settings
	 * file may have either input shape — the dispatcher tolerates both
	 * while callers downstream only ever see the normalized form.
	 *
	 * @return array<int,array{code: string, label: string, dir: string}>
	 */
	public static function normalize(mixed $available): array
	{
		if (!is_array($available)) {
			return [];
		}

		// Detect shape from the first entry: a string means the list is
		// a flat code list; an array means it's pre-expanded.
		$first = null;
		foreach ($available as $entry) {
			$first = $entry;
			break;
		}

		if (is_string($first)) {
			/** @var array<int,string> $codes */
			$codes = array_values(array_filter($available, is_string(...)));

			return self::expand($codes);
		}

		if (is_array($first)) {
			$out = [];
			foreach ($available as $entry) {
				if (!is_array($entry) || !isset($entry['code'])) {
					continue;
				}
				$out[] = [
					'code'  => (string)$entry['code'],
					'label' => (string)($entry['label'] ?? $entry['code']),
					'dir'   => (string)($entry['dir'] ?? 'ltr'),
				];
			}

			return $out;
		}

		return [];
	}
}
