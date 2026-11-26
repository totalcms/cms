<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Money\Money;

/**
 * Money as a person reads it: "$19.99", "¥1,500", "1.234,56 €".
 *
 * PHP's intl extension does the work when the server has it. Without it the
 * amount is written the US way, with the symbol for the few currencies that
 * have an unmistakable one and the three-letter code for the rest.
 */
final readonly class MoneyFormatter
{
	private const DEFAULT_LOCALE = 'en_US';

	/** @var array<string,string> */
	private const SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥'];

	/**
	 * @param bool $useIntl false writes the plain form even where intl is installed
	 */
	public function __construct(private bool $useIntl = true)
	{
	}

	public function format(Money $money, ?string $locale = null): string
	{
		if ($this->useIntl && class_exists(\NumberFormatter::class)) {
			// Only for display: the float never goes back into arithmetic.
			$formatted = (new \NumberFormatter($locale ?? self::DEFAULT_LOCALE, \NumberFormatter::CURRENCY))
				->formatCurrency((float)$money->toDecimal(), $money->currency());
			if (is_string($formatted)) {
				return $formatted;
			}
		}

		return $this->plain($money);
	}

	private function plain(Money $money): string
	{
		$decimal = ltrim($money->toDecimal(), '-');
		[$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

		$number = number_format((float)$whole) . ($fraction === '' ? '' : '.' . $fraction);
		$symbol = self::SYMBOLS[$money->currency()] ?? $money->currency() . ' ';
		$sign   = $money->isNegative() ? '-' : '';

		return isset(self::SYMBOLS[$money->currency()]) ? $sign . $symbol . $number : $symbol . $sign . $number;
	}
}
