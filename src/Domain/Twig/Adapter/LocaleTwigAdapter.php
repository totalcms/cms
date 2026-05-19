<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use TotalCMS\Domain\Locale\LocaleRegistry;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

/**
 * Twig sub-adapter for locale and internationalization.
 *
 * Accessed in Twig as `cms.locale.*`.
 */
readonly class LocaleTwigAdapter
{
	public function __construct(
		private TranslationService $translator,
		private Config $config,
	) {
	}

	/**
	 * Return a `{label => code}` map for use in language pickers.
	 *
	 * Reads from `LocaleRegistry` so the locale table has a single source of
	 * truth across the settings UI, FormFields, Twig helper, and this method.
	 * Labels are the registry's native-language strings (Deutsch, 日本語,
	 * العربية, etc.) — same convention as the locale-picker dropdowns.
	 *
	 * @return array<string,string>
	 */
	public function languages(): array
	{
		$out = [];
		foreach (LocaleRegistry::all() as $code => $meta) {
			$out[$meta['label']] = $code;
		}

		return $out;
	}

	/**
	 * Set the locale for internationalization (dates, numbers, relative time).
	 * Useful for multilingual sites to switch locale per page.
	 * Requires the PHP intl extension to be installed.
	 *
	 * Usage in Twig: {{ cms.locale.set('de_DE') }}
	 *
	 * @param string $locale The locale code (e.g., 'de_DE', 'fr_FR', 'ja_JP')
	 *
	 * @return string Empty string (no output in template)
	 */
	public function set(string $locale): string
	{
		if (extension_loaded('intl')) {
			\Locale::setDefault($locale);
			\Cake\I18n\I18n::setLocale($locale);
		}

		return '';
	}

	/**
	 * Get the current locale.
	 * Requires the PHP intl extension to be installed.
	 *
	 * Usage in Twig: {{ cms.locale.get() }}
	 *
	 * @return string The current locale code (defaults to 'en_US' if intl not available)
	 */
	public function get(): string
	{
		if (!extension_loaded('intl')) {
			return 'en_US';
		}

		return \Cake\I18n\I18n::getLocale();
	}

	/**
	 * Translate a key from the admin domain.
	 *
	 * Usage in Twig: {{ cms.locale.t('nav.collections') }}
	 *
	 * @param array<string,string> $params
	 */
	public function t(string $key, array $params = []): string
	{
		return $this->translator->trans($key, $params, 'admin');
	}

	/**
	 * Translate a key (alias for t()).
	 *
	 * @param array<string,string> $params
	 */
	public function translate(string $key, array $params = []): string
	{
		return $this->t($key, $params);
	}

	/**
	 * Get JavaScript translations as an array.
	 * Used to inject translations into the page for JS consumption.
	 *
	 * Usage in Twig: {{ cms.locale.jsTranslations()|json_encode|raw }}
	 *
	 * @return array<string,string>
	 */
	public function jsTranslations(): array
	{
		return $this->translator->getCatalog('js');
	}

	//-------------------------------------------------------------------
	// Localized field-value helpers (Pro). Operate on the locale-keyed
	// dict stored by `localizedtext` / `localizedstyledtext` field types.
	// Direct array access (post.title.de, post.title['en_US']) bypasses
	// these helpers and gives you the raw value with no fallback chain.
	//-------------------------------------------------------------------

	/**
	 * Look up a localized text value from a locale-keyed dict, applying the
	 * deterministic fallback chain:
	 *   1. Canonicalize the requested locale (case-insensitive input).
	 *   2. Exact match on the dict.
	 *   3. Region fall-up: `de_DE` → bare `de`.
	 *   4. Region fall-down: bare `en` → first matching `en_*` in the order
	 *      of the site's configured locales.
	 *   5. Site default — try `$config->i18n['default']` as the last resort
	 *      before giving up. Sparse-data templates (object has only an
	 *      English value but a German request comes in) get the English
	 *      value instead of an empty cell.
	 *   6. Empty string.
	 *
	 * Usage in Twig: {{ cms.locale.text(post.title, 'de') }}
	 *
	 * @param mixed  $value  Expected: array<string,string>. Other shapes return ''.
	 * @param string $locale Requested locale (case-insensitive on input).
	 */
	public function text(mixed $value, string $locale): string
	{
		if (!is_array($value) || $value === []) {
			return '';
		}

		$canonical = self::canonicalizeLocale($locale);
		if ($canonical === '') {
			return '';
		}

		// 1. Exact match
		if (isset($value[$canonical]) && is_scalar($value[$canonical])) {
			return (string)$value[$canonical];
		}

		// 2. Region fall-up: `de_DE` → `de`
		if (str_contains($canonical, '_')) {
			$bare = explode('_', $canonical, 2)[0];
			if (isset($value[$bare]) && is_scalar($value[$bare])) {
				return (string)$value[$bare];
			}
		}

		// 3. Region fall-down: bare `en` → first matching `en_*` in
		//    configured locales order. Falls back to alphabetical dict
		//    order if no site locales are configured.
		if (!str_contains($canonical, '_')) {
			$prefix = $canonical . '_';

			foreach ($this->config->i18n['available'] as $configured) {
				$code = (string)($configured['code'] ?? '');
				if ($code !== '' && str_starts_with($code, $prefix) && isset($value[$code]) && is_scalar($value[$code])) {
					return (string)$value[$code];
				}
			}

			// Fallback: scan the value dict directly in insertion order.
			foreach ($value as $key => $val) {
				if (is_string($key) && str_starts_with($key, $prefix) && is_scalar($val)) {
					return (string)$val;
				}
			}
		}

		// 4. Site-default fallback. When the requested locale yields nothing,
		//    try the site's `i18n.default` as a last resort before giving up.
		//    Skip when the default IS the requested code (already tried in
		//    step 1) to avoid redundant work.
		$siteDefault = self::canonicalizeLocale($this->config->i18n['default']);
		if ($siteDefault !== '' && $siteDefault !== $canonical && isset($value[$siteDefault]) && is_scalar($value[$siteDefault])) {
			return (string)$value[$siteDefault];
		}

		return '';
	}

	/**
	 * Same as text() but reserved for `localizedstyledtext` content. Returned
	 * value is HTML — pipe through `|raw` in templates to render unescaped.
	 *
	 * Usage in Twig: {{ cms.locale.styledtext(post.body, 'de')|raw }}
	 */
	public function styledtext(mixed $value, string $locale): string
	{
		return $this->text($value, $locale);
	}

	/**
	 * Normalize a locale code to the canonical `{lang}_{REGION}` form:
	 * language lowercased, region uppercased. Bare-language codes
	 * (`de`, `fr`) stay bare. Empty input returns empty string.
	 */
	public static function canonicalizeLocale(string $locale): string
	{
		$locale = trim($locale);
		if ($locale === '') {
			return '';
		}

		// Accept either `en_US` or `en-US` style on input; canonical is underscore.
		$locale = str_replace('-', '_', $locale);
		$parts  = explode('_', $locale, 2);
		$lang   = strtolower($parts[0]);
		if ($lang === '') {
			return '';
		}

		if (!isset($parts[1]) || $parts[1] === '') {
			return $lang;
		}

		return $lang . '_' . strtoupper($parts[1]);
	}
}
