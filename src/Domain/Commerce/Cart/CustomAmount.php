<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Cart;

use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\MoneyConverter;
use TotalCMS\Domain\Money\Money;

/**
 * The amount a buyer names for a product that lets them choose the price.
 *
 * What a buyer types is text, and it is read as written digits, never through
 * float arithmetic. It is rounded to the store currency's minor unit, then
 * held to the product's minimum, the store's maximum, and a floor of
 * FLOOR_MINOR minor units that a card payment can carry. Nothing here sets a
 * price: the caller keeps the result and the checkout checks it again.
 */
final class CustomAmount
{
	/** The least a payment may be, in minor units (50 cents), unless it is nothing. */
	public const FLOOR_MINOR = 50;

	/** More digits than this before the point is not a sane amount. */
	private const MAX_WHOLE_DIGITS = 12;

	/**
	 * The amount in major units, rounded to the currency's minor unit.
	 *
	 * @param string $raw what the buyer typed; the caller treats an empty value as no amount
	 *
	 * @throws CommerceException `amount_invalid`
	 */
	public static function parse(string $raw, ProductView $product, bool $recurring, CommerceSettings $settings): float
	{
		$raw = trim($raw);
		if (preg_match('/^(\d+)(?:\.\d+)?\z/', $raw, $parts) !== 1 || strlen($parts[1]) > self::MAX_WHOLE_DIGITS) {
			throw self::refusal($product, $recurring, $settings);
		}

		$money = (new MoneyConverter())->fromDecimal($raw, $settings->currency());
		if (!self::within($money->amount(), $product, $recurring, $settings)) {
			throw self::refusal($product, $recurring, $settings);
		}

		return (float)$money->toDecimal();
	}

	/** Whether $amount, in major units, passes the rules parse() applies. Never throws. */
	public static function allowed(float $amount, ProductView $product, bool $recurring, CommerceSettings $settings): bool
	{
		if (!is_finite($amount) || $amount < 0) {
			return false;
		}

		try {
			$minor = (new MoneyConverter())->fromPrice($amount, $settings->currency())->amount();
		} catch (\InvalidArgumentException | \OverflowException) {
			return false;
		}

		return self::within($minor, $product, $recurring, $settings);
	}

	/**
	 * Whether a cart line's amount can stand against its product now. A
	 * product that lets the buyer name the price needs an amount that passes
	 * the rules today; a product that does not must carry none, because a
	 * buyer agreed to a number the seller no longer offers. A named price is
	 * one line of one, so any other quantity does not stand. Never throws.
	 */
	public static function stands(CartItem $item, ProductView $product, bool $recurring, CommerceSettings $settings): bool
	{
		if (!$product->customPrice) {
			return $item->amount === null;
		}

		return $item->amount !== null && $item->qty === 1 && self::allowed($item->amount, $product, $recurring, $settings);
	}

	private static function within(int $minor, ProductView $product, bool $recurring, CommerceSettings $settings): bool
	{
		[$least, $most] = self::limits($product, $settings);

		if ($minor > $most || $minor < $least) {
			return false;
		}
		if ($minor === 0) {
			return self::zeroAllowed($least, $recurring);
		}

		return $minor >= self::FLOOR_MINOR;
	}

	/**
	 * The product's minimum and the store's maximum, in minor units. A
	 * minimum that cannot be read counts as none, and a maximum that cannot
	 * be read as the default ceiling: neither fallback removes the ceiling.
	 *
	 * @return array{int, int}
	 */
	private static function limits(ProductView $product, CommerceSettings $settings): array
	{
		$currency  = $settings->currency();
		$converter = new MoneyConverter();

		try {
			$most = $converter->fromPrice($settings->customPriceMax(), $currency)->amount();
		} catch (\InvalidArgumentException | \OverflowException) {
			$most = $converter->fromPrice(CommerceSettings::DEFAULT_CUSTOM_PRICE_MAX, $currency)->amount();
		}

		try {
			$least = $converter->fromPrice($product->minPrice, $currency)->amount();
		} catch (\InvalidArgumentException | \OverflowException) {
			$least = 0;
		}

		return [$least, $most];
	}

	private static function zeroAllowed(int $least, bool $recurring): bool
	{
		return $least === 0 && !$recurring;
	}

	private static function refusal(ProductView $product, bool $recurring, CommerceSettings $settings): CommerceException
	{
		[$least, $most] = self::limits($product, $settings);
		$currency       = $settings->currency();

		return CommerceException::amountInvalid(
			self::plain(max($least, self::FLOOR_MINOR), $currency),
			self::plain($most, $currency),
			self::zeroAllowed($least, $recurring),
		);
	}

	/** Minor units as a plain number in the currency's own decimals: 1250 is 12.50 in USD, 1250 in JPY, 1.250 in KWD. */
	private static function plain(int $minor, string $currency): string
	{
		$exponent = Money::exponent($currency);

		return number_format($minor / 10 ** $exponent, $exponent, '.', '');
	}
}
