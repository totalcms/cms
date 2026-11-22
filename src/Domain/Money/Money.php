<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Money;

/**
 * An amount of money: a whole number of minor units (cents) in one currency.
 *
 * No float is used anywhere, and nothing here needs bcmath. A rate or a
 * divisor arrives as a decimal string (`"8.875"`), which is read as an integer
 * and a power of ten; every division names its {@see Rounding}. A sum that
 * would not fit in an integer throws instead of quietly becoming a float.
 *
 * This is arithmetic only. Turning a `price` field into Money, and Money into
 * text for a person, belong to the code that uses it.
 *
 * A value object's public methods are its arithmetic: twelve small ones, none
 * of which belongs anywhere else.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
final readonly class Money
{
	/** Minor-unit digits by ISO 4217 code; a currency not listed has 2. */
	private const EXPONENTS = [
		'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
		'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
		'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
	];

	private function __construct(
		private int $amount,
		private string $currency,
	) {
	}

	/**
	 * @param int    $minor    the amount in minor units: 1999 is 19.99 in USD, 1999 in JPY
	 * @param string $currency a three-letter ISO 4217 code, in any case
	 *
	 * @throws \InvalidArgumentException when the currency is not three letters
	 */
	public static function of(int $minor, string $currency): self
	{
		$currency = strtoupper($currency);
		if (preg_match('/^[A-Z]{3}\z/', $currency) !== 1) {
			throw new \InvalidArgumentException("'{$currency}' is not a currency code: three letters, such as USD");
		}

		return new self($minor, $currency);
	}

	public static function zero(string $currency): self
	{
		return self::of(0, $currency);
	}

	/** How many digits of the currency's amount are minor units: 2 for USD, 0 for JPY, 3 for BHD. */
	public static function exponent(string $currency): int
	{
		return self::EXPONENTS[strtoupper($currency)] ?? 2;
	}

	public function amount(): int
	{
		return $this->amount;
	}

	public function currency(): string
	{
		return $this->currency;
	}

	/** @throws \InvalidArgumentException on a different currency */
	public function add(self $other): self
	{
		return new self($this->whole($this->amount + $this->same($other)->amount), $this->currency);
	}

	/** @throws \InvalidArgumentException on a different currency */
	public function subtract(self $other): self
	{
		return new self($this->whole($this->amount - $this->same($other)->amount), $this->currency);
	}

	public function multiply(int $quantity): self
	{
		return new self($this->whole($this->amount * $quantity), $this->currency);
	}

	/**
	 * A percentage of this amount.
	 *
	 * @param string $rate the percent as a decimal string: `"8.875"` is 8.875%
	 *
	 * @throws \InvalidArgumentException when the rate is not a plain decimal
	 */
	public function percentage(string $rate, Rounding $mode = Rounding::HalfUp): self
	{
		[$digits, $scale] = self::decimal($rate);

		return new self(self::divideRounded($this->whole($this->amount * $digits), $this->whole(10 ** $scale * 100), $mode), $this->currency);
	}

	/**
	 * This amount divided by a number, as inclusive tax needs: a price of
	 * 108.88 that includes 8.875% is 100.00 before tax, `divide('1.08875')`.
	 *
	 * @param string $divisor a decimal string above zero
	 *
	 * @throws \InvalidArgumentException when the divisor is not a plain decimal, or is zero
	 */
	public function divide(string $divisor, Rounding $mode = Rounding::HalfUp): self
	{
		[$digits, $scale] = self::decimal($divisor);
		if ($digits === 0) {
			throw new \InvalidArgumentException('Money cannot be divided by zero');
		}

		return new self(self::divideRounded($this->whole($this->amount * $this->whole(10 ** $scale)), $digits, $mode), $this->currency);
	}

	public function isZero(): bool
	{
		return $this->amount === 0;
	}

	public function isNegative(): bool
	{
		return $this->amount < 0;
	}

	public function equals(self $other): bool
	{
		return $this->amount === $other->amount && $this->currency === $other->currency;
	}

	/** The amount with its decimal point, by the currency's exponent: `"19.99"`, `"1500"`, `"1.235"`. No symbol, no grouping. */
	public function toDecimal(): string
	{
		$exponent = self::exponent($this->currency);
		if ($exponent === 0) {
			return (string)$this->amount;
		}

		$unit  = 10 ** $exponent;
		$whole = intdiv($this->amount, $unit);
		$minor = abs($this->amount % $unit);

		// intdiv() drops the sign of an amount between -1 and 0.
		return ($this->amount < 0 && $whole === 0 ? '-' : '') . $whole . '.' . str_pad((string)$minor, $exponent, '0', STR_PAD_LEFT);
	}

	private function same(self $other): self
	{
		if ($other->currency !== $this->currency) {
			throw new \InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}");
		}

		return $other;
	}

	/**
	 * PHP turns an integer sum that does not fit into a float. That float is
	 * the overflow, and money must never carry on with it.
	 */
	private function whole(int|float $value): int
	{
		return is_int($value) ? $value : throw new \OverflowException('The amount is too large to hold exactly');
	}

	/**
	 * A plain decimal string as its digits and how many of them follow the
	 * point: `"8.875"` is [8875, 3].
	 *
	 * @return array{0:int, 1:int}
	 */
	private static function decimal(string $number): array
	{
		if (preg_match('/^(\d+)(?:\.(\d+))?\z/', $number, $parts) !== 1) {
			throw new \InvalidArgumentException("'{$number}' is not a decimal number such as 8.875");
		}

		$fraction = $parts[2] ?? '';
		$digits   = $parts[1] . $fraction;
		if (strlen(ltrim($digits, '0')) > 18) {
			throw new \OverflowException("'{$number}' has too many digits to hold exactly");
		}

		return [(int)$digits, strlen($fraction)];
	}

	/** Whole-number division with the fraction rounded as asked. The divisor is above zero. */
	private static function divideRounded(int $dividend, int $divisor, Rounding $mode): int
	{
		$quotient  = intdiv($dividend, $divisor);
		$remainder = abs($dividend % $divisor);
		if ($remainder === 0) {
			return $quotient;
		}

		// Compared as "remainder against what is left of the divisor", so that
		// doubling the remainder can never overflow.
		$rest = $divisor - $remainder;
		$away = match ($mode) {
			Rounding::Up       => true,
			Rounding::Down     => false,
			Rounding::HalfUp   => $remainder >= $rest,
			Rounding::HalfEven => $remainder > $rest || ($remainder === $rest && $quotient % 2 !== 0),
		};

		return $away ? $quotient + ($dividend < 0 ? -1 : 1) : $quotient;
	}
}
