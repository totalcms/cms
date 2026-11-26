<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Property\Data\PriceData;

/**
 * Turns a price as it is stored or typed into whole minor units.
 *
 * Products hold prices the way the price field saves them: a number such as
 * 19.99, or text such as "$5,000.00". Everything after this point is integer
 * arithmetic, so this is the one place a decimal is read, and it is read as
 * written digits, never through float arithmetic: 19.99 is 1999 and 1.005 is
 * 101 on every PHP version. Halves round up.
 */
final class MoneyConverter
{
	/**
	 * A price field's value in the currency's minor units.
	 *
	 * @throws \InvalidArgumentException when the price is negative or not a number
	 * @throws \OverflowException        when the amount is too large to hold exactly
	 */
	public function fromPrice(float|int|string $price, string $currency): Money
	{
		return $this->fromDecimal(is_string($price) ? PriceData::normalize($price) : self::written($price), $currency);
	}

	/**
	 * A plain decimal such as "4.95" in the currency's minor units. No
	 * symbols and no grouping: use fromPrice() for anything a person typed
	 * into a price field.
	 *
	 * @throws \InvalidArgumentException when the text is negative or not a plain decimal
	 * @throws \OverflowException        when the amount is too large to hold exactly
	 */
	public function fromDecimal(string $decimal, string $currency): Money
	{
		$decimal = trim($decimal);
		if (preg_match('/^(-?)(\d*)(?:\.(\d*))?\z/', $decimal, $parts) !== 1 || $parts[2] . ($parts[3] ?? '') === '') {
			throw new \InvalidArgumentException("'{$decimal}' is not an amount such as 19.99");
		}

		$fraction = $parts[3] ?? '';
		if ($parts[1] === '-' && trim($parts[2] . $fraction, '0') !== '') {
			throw new \InvalidArgumentException("An amount cannot be negative: '{$decimal}'");
		}

		$exponent = Money::exponent($currency);
		$digits   = ltrim($parts[2] . substr(str_pad($fraction, $exponent, '0'), 0, $exponent), '0');
		if (strlen($digits) > 18) {
			throw new \OverflowException("'{$decimal}' is too large to hold exactly");
		}

		// The first digit past the currency's last decides: 5 or more rounds up.
		$roundsUp = ($fraction[$exponent] ?? '0') >= '5';

		return Money::of((int)$digits + ($roundsUp ? 1 : 0), $currency);
	}

	/**
	 * A number as its shortest exact decimal text: 19.99 is "19.99", not the
	 * 19.989999999999998 it is in binary.
	 */
	private static function written(float|int $number): string
	{
		if (is_int($number)) {
			return (string)$number;
		}
		if (!is_finite($number)) {
			throw new \InvalidArgumentException('A price must be a number');
		}

		$written = (string)json_encode($number);

		return str_contains($written, 'e') || str_contains($written, 'E')
			? throw new \InvalidArgumentException("{$written} cannot be read as a price")
			: $written;
	}
}
