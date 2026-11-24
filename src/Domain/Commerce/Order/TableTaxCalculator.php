<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Service\CommerceSettings;

/**
 * Tax from the store's own table: a rate for a country, or for one region
 * of it, set in Settings → Commerce.
 *
 * The buyer's address picks the rate, the most exact one winning: a rate
 * for their region, then one for their country, then one for everywhere.
 * With no address, or no rate for it, there is no tax.
 *
 * Every sum is in whole numbers. A rate is a percentage with up to four
 * decimals, held as that many ten-thousandths (`8.875` is 88 750), and a
 * result is rounded half up, one line at a time, so the tax on the lines
 * adds up to the tax on the order.
 */
final readonly class TableTaxCalculator implements TaxCalculator
{
	/** What a rate is held in: ten-thousandths of a percent. */
	private const SCALE = 10000;

	/** A whole price, in the same units: 100 percent. */
	private const WHOLE = 100 * self::SCALE;

	public function __construct(private CommerceSettings $settings)
	{
	}

	public function mode(): string
	{
		return $this->settings->taxMode();
	}

	public function needsRegion(string $country): bool
	{
		$country = strtoupper(trim($country));
		if ($country === '' || !in_array($this->mode(), self::OWN, true)) {
			return false;
		}

		foreach ($this->settings->taxRates() as $rate) {
			if ($rate['country'] === $country && $rate['region'] !== '') {
				return true;
			}
		}

		return false;
	}

	public function calculate(array $lines, int $shipping, ?Address $address, string $currency): array
	{
		$nothing = ['lines' => array_fill_keys(array_keys($lines), 0), 'shipping' => 0, 'taxLines' => []];
		// When the payment provider works the tax out, the table is not used.
		$rate    = !in_array($this->mode(), self::OWN, true) || !$address instanceof Address ? null : $this->rateFor($address);
		if ($rate === null) {
			return $nothing;
		}

		$taxed = [];
		foreach ($lines as $id => $line) {
			$taxed[$id] = $line->taxable ? $this->taxOn($line->netTotal(), $rate['scaled']) : 0;
		}
		$onShipping = $this->settings->taxShipping() ? $this->taxOn($shipping, $rate['scaled']) : 0;
		$total      = array_sum($taxed) + $onShipping;

		return [
			'lines'    => $taxed,
			'shipping' => $onShipping,
			'taxLines' => $total > 0 ? [['label' => $rate['label'] !== '' ? $rate['label'] : 'Tax', 'rate' => $rate['rate'], 'amount' => $total]] : [],
		];
	}

	/**
	 * The tax on an amount. Added to prices, it is the rate on the amount.
	 * Already in prices, it is the part of the amount that is tax: what is
	 * left when the price before tax is taken away.
	 */
	private function taxOn(int $amount, int $scaled): int
	{
		if ($amount <= 0) {
			return 0;
		}

		if ($this->mode() === self::INCLUSIVE) {
			return $amount - $this->halfUp($amount * self::WHOLE, self::WHOLE + $scaled);
		}

		return $this->halfUp($amount * $scaled, self::WHOLE);
	}

	/** $top / $bottom, to the nearest whole number, a half going up. Both are positive. */
	private function halfUp(int $top, int $bottom): int
	{
		return intdiv(2 * $top + $bottom, 2 * $bottom);
	}

	/**
	 * The most exact rate for an address, or null when none is for it.
	 *
	 * @return array{rate: string, label: string, scaled: int}|null
	 */
	private function rateFor(Address $address): ?array
	{
		$best  = null;
		$score = 0;
		foreach ($this->settings->taxRates() as $rate) {
			$scaled = $this->scaled($rate['rate']);
			$fits   = $scaled === null ? 0 : $this->fits($rate, $address);
			if ($scaled !== null && $fits > $score) {
				$best  = ['rate' => $rate['rate'], 'label' => $rate['label'], 'scaled' => $scaled];
				$score = $fits;
			}
		}

		return $best;
	}

	/**
	 * How exactly a rate is for an address: 3 for its region, 2 for its
	 * country, 1 for everywhere, 0 when it is for somewhere else.
	 *
	 * @param array{country: string, region: string, rate: string, label: string} $rate
	 */
	private function fits(array $rate, Address $address): int
	{
		if ($rate['country'] === '' || $rate['country'] === '*') {
			return 1;
		}
		if ($rate['country'] !== strtoupper($address->country)) {
			return 0;
		}
		if ($rate['region'] === '') {
			return 2;
		}

		// The store may write a region in more than one way: "NSW, New South Wales".
		foreach (explode(',', $rate['region']) as $written) {
			if (Regions::same($rate['country'], $written, $address->region)) {
				return 3;
			}
		}

		return 0;
	}

	/**
	 * A rate such as "8.875" in ten-thousandths of a percent, or null when
	 * it is not a percentage from 0 to 100 with up to four decimals.
	 */
	private function scaled(string $rate): ?int
	{
		if (preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?\z/', trim($rate), $parts) !== 1) {
			return null;
		}

		$scaled = (int)$parts[1] * self::SCALE + (int)str_pad($parts[2] ?? '', 4, '0');

		return $scaled <= self::WHOLE ? $scaled : null;
	}
}
