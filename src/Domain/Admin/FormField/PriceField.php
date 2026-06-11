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

		$currency                   = $this->resolveCurrency($locale);
		$this->settings['currency'] = $currency;
		$this->settings['decimals'] = $this->resolveDecimals($locale, $currency);
		$this->currencySymbol       = $this->resolveSymbol($locale, $currency);

		$this->appendCurrencyIcon($locale, $currency);
	}

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes = parent::formFieldAttributes();
		// Whole-currency → digit-only keypad; otherwise a decimal keypad (cents).
		$attributes['inputmode'] = (int)($this->settings['decimals'] ?? 2) === 0 ? 'numeric' : 'decimal';

		return $attributes;
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

	private function resolveDecimals(string $locale, string $currency): int
	{
		if (isset($this->settings['decimals']) && $this->settings['decimals'] !== '') {
			return (int)$this->settings['decimals'];
		}
		if (!extension_loaded('intl')) {
			return 2;
		}

		$fmt = new \NumberFormatter($locale !== '' ? $locale : 'en_US', \NumberFormatter::CURRENCY);
		$fmt->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currency);

		return (int)$fmt->getAttribute(\NumberFormatter::FRACTION_DIGITS);
	}

	/**
	 * Append the currency-symbol icon class (icon-dollar/euro/pound/yen, else
	 * icon-currency) to the field's class list — unless the operator already set
	 * an explicit icon-* class, which wins.
	 */
	private function appendCurrencyIcon(string $locale, string $currency): void
	{
		if (preg_match('/\bicon-[a-z]+\b/', $this->class) === 1) {
			return;
		}

		$this->class = trim($this->class . ' ' . $this->currencyIconClass($locale, $currency));
	}

	private function resolveSymbol(string $locale, string $currency): string
	{
		if (!extension_loaded('intl')) {
			return '';
		}

		$fmt       = new \NumberFormatter($locale !== '' ? $locale : 'en_US', \NumberFormatter::CURRENCY);
		$formatted = (string)$fmt->formatCurrency(0, $currency);
		// Strip digits, unicode separators/spaces, and ./, → leaves the symbol.
		$symbol = preg_replace('/[\p{N}\p{Z}\s.,]/u', '', $formatted) ?? '';
		// Approximate a narrow symbol: drop a leading letter-run when a glyph
		// remains (CA$ → $); keep all-letter symbols intact (CHF, kr).
		$narrow = preg_replace('/^\p{L}+/u', '', $symbol) ?? '';

		return $narrow !== '' ? $narrow : $symbol;
	}

	public function createFormGroup(string $content): string
	{
		if ($this->currencySymbol !== '') {
			$symbol  = HTMLUtils::element('span', $this->currencySymbol, ['class' => 'totalform-currency-symbol']);
			$content = $symbol . $content;
		}

		return parent::createFormGroup($content);
	}

	private function currencyIconClass(string $locale, string $currency): string
	{
		if (!extension_loaded('intl')) {
			return '';
		}

		$fmt    = new \NumberFormatter($locale !== '' ? $locale : 'en_US', \NumberFormatter::CURRENCY);
		$symbol = (string)$fmt->formatCurrency(0, $currency);

		return match (true) {
			str_contains($symbol, '¥')
			|| str_contains($symbol, '￥')                  => 'icon-yen',
			str_contains($symbol, '$')                      => 'icon-dollar',
			str_contains($symbol, '€')                      => 'icon-euro',
			str_contains($symbol, '£')                      => 'icon-pound',
			default                                         => '',
		};
	}
}
