<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

/**
 * Twig sub-adapter for locale and internationalization.
 *
 * Accessed in Twig as `cms.locale.*`.
 */
readonly class LocaleTwigAdapter
{
	/** @return array<string,string> */
	public function languages(): array
	{
		return [
			'Arabic'          => 'ar_SA',
			'Bengali'         => 'bn_BD',
			'Czech'           => 'cs_CZ',
			'Dutch'           => 'nl_NL',
			'English'         => 'en_US',
			'French'          => 'fr_FR',
			'German'          => 'de_DE',
			'Greek'           => 'el_GR',
			'Hebrew'          => 'he_IL',
			'Hindi'           => 'hi_IN',
			'Italian'         => 'it_IT',
			'Japanese'        => 'ja_JP',
			'Javanese'        => 'jv_ID',
			'Korean'          => 'ko_KR',
			'Malay'           => 'ms_MY',
			'Mandarin'        => 'zh_CN',
			'Persian (Farsi)' => 'fa_IR',
			'Polish'          => 'pl_PL',
			'Portuguese'      => 'pt_BR',
			'Punjabi'         => 'pa_IN',
			'Romanian'        => 'ro_RO',
			'Russian'         => 'ru_RU',
			'Spanish'         => 'es_ES',
			'Swahili'         => 'sw_KE',
			'Tamil'           => 'ta_IN',
			'Tagalog'         => 'tl_PH',
			'Thai'            => 'th_TH',
			'Turkish'         => 'tr_TR',
			'Ukrainian'       => 'uk_UA',
			'Urdu'            => 'ur_PK',
			'Vietnamese'      => 'vi_VN',
		];
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
}
