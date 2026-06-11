<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Price field — a plain text input that the JS layer (price.js) formats as
 * locale-aware currency while typing. The stored value remains a number; the
 * input is text so separators are allowed. Currency, decimal count, and the
 * currency icon are resolved here (server-side) and passed to the JS via the
 * auto-serialized `data-settings`.
 *
 * Settings (all optional):
 *   - currency: ISO code (USD/EUR/…). Default: derived from the resolved locale.
 *   - decimals: fraction digits. Default: the currency's standard (USD 2, JPY 0).
 *   - locale:   BCP-47 tag. Default: the site i18n default.
 */
class PriceField extends FormField
{
	protected string $defaultInputType = 'text';
	protected string $defaultFieldType = 'price';

	private string $currencySymbol = '';

	/** Shared currency formatter for the resolved locale (null when intl is absent). */
	private ?\NumberFormatter $formatter = null;

	/** Region → ISO currency for locale-derived defaults. */
	private const REGION_CURRENCY = [
		'US' => 'USD', 'GB' => 'GBP', 'CA' => 'CAD', 'AU' => 'AUD', 'NZ' => 'NZD',
		'JP' => 'JPY', 'CN' => 'CNY', 'IN' => 'INR', 'CH' => 'CHF', 'HK' => 'HKD',
		'SG' => 'SGD', 'MX' => 'MXN', 'BR' => 'BRL', 'ZA' => 'ZAR',
		'DE' => 'EUR', 'FR' => 'EUR', 'ES' => 'EUR', 'IT' => 'EUR', 'NL' => 'EUR',
		'IE' => 'EUR', 'PT' => 'EUR', 'AT' => 'EUR', 'BE' => 'EUR', 'FI' => 'EUR',
	];

	/**
	 * Likely default region for a bare language tag (no explicit region) — a
	 * small slice of CLDR likely-subtags covering the currencies we map. Used
	 * when a locale like 'en' or 'ja' carries no region to read.
	 */
	private const LANGUAGE_REGION = [
		'en' => 'US', 'ja' => 'JP', 'zh' => 'CN', 'hi' => 'IN', 'pt' => 'BR',
		'de' => 'DE', 'fr' => 'FR', 'es' => 'ES', 'it' => 'IT', 'nl' => 'NL',
	];

	public function init(): void
	{
		parent::init();

		$locale = $this->resolveLocale();
		if ($locale !== '') {
			$this->settings['locale'] = $locale;
		}

		if (extension_loaded('intl')) {
			$this->formatter = new \NumberFormatter($locale !== '' ? $locale : 'en_US', \NumberFormatter::CURRENCY);
		}

		$currency                   = $this->resolveCurrency($locale);
		$this->settings['currency'] = $currency;

		// A single formatCurrency() feeds both the symbol shown beside the value
		// and the icon class — they read the same rendered currency string.
		$rendered             = $this->formatter?->formatCurrency(0, $currency);
		$rendered             = is_string($rendered) ? $rendered : '';
		$this->currencySymbol = $this->extractSymbol($rendered);
		$this->appendCurrencyIcon($rendered);

		$this->settings['decimals'] = $this->resolveDecimals($currency);
	}

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes = parent::formFieldAttributes();
		// Whole-currency → digit-only keypad; otherwise a decimal keypad (cents).
		$attributes['inputmode'] = (int)($this->settings['decimals'] ?? 2) === 0 ? 'numeric' : 'decimal';

		return $attributes;
	}

	public function createFormGroup(string $content): string
	{
		if ($this->currencySymbol !== '') {
			$symbol  = HTMLUtils::element('span', $this->currencySymbol, ['class' => 'totalform-currency-symbol']);
			$content = $symbol . $content;
		}

		return parent::createFormGroup($content);
	}

	private function resolveLocale(): string
	{
		$explicit = isset($this->settings['locale']) ? trim((string)$this->settings['locale']) : '';
		if ($explicit !== '') {
			return $explicit;
		}

		return trim($this->form->getDefaultLocale());
	}

	private function resolveCurrency(string $locale): string
	{
		$explicit = isset($this->settings['currency']) ? strtoupper(trim((string)$this->settings['currency'])) : '';
		if ($explicit !== '') {
			return $explicit;
		}

		// Region-bearing tags (de-DE, en-US, ja-JP) yield a region directly; the
		// regex is a fallback for those when intl is unavailable. Bare languages
		// (en, ja) carry no region, so we map them through likely-subtags below.
		$region = '';
		if (extension_loaded('intl') && $locale !== '') {
			$region = (string)\Locale::getRegion($locale);
		}
		if ($region === '' && preg_match('/[_-]([A-Za-z]{2})(?:[_-]|$)/', $locale, $m) === 1) {
			$region = strtoupper($m[1]);
		}
		if ($region === '') {
			$language = strtolower(trim((string)preg_replace('/[_-].*$/', '', $locale)));
			$region   = self::LANGUAGE_REGION[$language] ?? '';
		}

		return self::REGION_CURRENCY[$region] ?? 'USD';
	}

	private function resolveDecimals(string $currency): int
	{
		if (isset($this->settings['decimals']) && $this->settings['decimals'] !== '') {
			return (int)$this->settings['decimals'];
		}
		if ($this->formatter === null) {
			return 2;
		}

		$this->formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currency);

		return (int)$this->formatter->getAttribute(\NumberFormatter::FRACTION_DIGITS);
	}

	/**
	 * The narrow currency symbol from a rendered currency string: strip digits,
	 * separators, and spaces, then drop a leading letter-run so "CA$" → "$" while
	 * all-letter symbols (CHF, kr) stay intact.
	 */
	private function extractSymbol(string $rendered): string
	{
		if ($rendered === '') {
			return '';
		}

		$symbol = preg_replace('/[\p{N}\p{Z}\s.,]/u', '', $rendered) ?? '';
		$narrow = preg_replace('/^\p{L}+/u', '', $symbol) ?? '';

		return $narrow !== '' ? $narrow : $symbol;
	}

	/**
	 * Append the currency-symbol icon class (icon-dollar/euro/pound/yen) inferred
	 * from the rendered currency's glyph. Currencies with no dedicated glyph
	 * append nothing and fall through to the `.price-field` default
	 * (icon-currency). An explicit operator icon-* class always wins.
	 */
	private function appendCurrencyIcon(string $rendered): void
	{
		if (preg_match('/\bicon-[a-z]+\b/', $this->class) === 1) {
			return;
		}

		$icon = match (true) {
			str_contains($rendered, '¥') || str_contains($rendered, '￥') => 'icon-yen',
			str_contains($rendered, '$')                                  => 'icon-dollar',
			str_contains($rendered, '€')                                  => 'icon-euro',
			str_contains($rendered, '£')                                  => 'icon-pound',
			default                                                        => '',
		};

		if ($icon !== '') {
			$this->class = trim($this->class . ' ' . $icon);
		}
	}
}
