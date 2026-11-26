<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\MoneyConverter;
use TotalCMS\Domain\Money\Money;

/**
 * Shipping from the store's own settings: nothing, one flat rate, or a
 * rate by where the order is going (Settings → Commerce).
 *
 * There is one rate for an order. With zones it is the first zone the
 * address is in, in the order the store lists them, so the store puts the
 * most exact first. A zone that names no country covers everywhere. No
 * rate at all means the store does not ship there.
 *
 * An order at or over a "free over" amount ships free. That is compared
 * with what the lines come to after any discount.
 */
final readonly class TableShippingCalculator implements ShippingCalculator
{
	private const FLAT  = 'flat';
	private const ZONES = 'zones';

	private const LABEL = 'Shipping';
	private const FREE  = 'Free shipping';

	public function __construct(
		private CommerceSettings $settings,
		private MoneyConverter $money,
	) {
	}

	public function rates(array $lines, Money $subtotal, ?Address $address): array
	{
		$currency = $subtotal->currency();

		return match ($this->settings->shippingMode()) {
			self::FLAT  => [$this->rate(self::FLAT, self::LABEL, $this->settings->shippingFlatRate(), $this->settings->shippingFreeOver(), $subtotal)],
			self::ZONES => $this->zone($address, $subtotal),
			default     => [new ShippingRate(NoShippingCalculator::RATE, self::LABEL, Money::zero($currency))],
		};
	}

	/**
	 * The rate of the first zone the address is in.
	 *
	 * @return list<ShippingRate> none when no zone covers the address
	 */
	private function zone(?Address $address, Money $subtotal): array
	{
		$country = strtoupper(trim($address->country ?? ''));
		if ($country === '') {
			return [];
		}

		foreach ($this->settings->shippingZones() as $index => $zone) {
			if (!$this->covers($zone, $country, $address->region ?? '')) {
				continue;
			}

			$id    = is_string($zone['id'] ?? null) && $zone['id'] !== '' ? $zone['id'] : 'zone_' . $index;
			$label = is_string($zone['label'] ?? null) && trim($zone['label']) !== '' ? trim($zone['label']) : self::LABEL;

			return [$this->rate($id, $label, $this->decimal($zone['rate'] ?? ''), $this->decimal($zone['freeOver'] ?? ''), $subtotal)];
		}

		return [];
	}

	/**
	 * Whether a zone covers an address. A zone with no countries covers
	 * every country. A zone with regions covers only those regions of its
	 * countries; a region is matched as the tax table matches one.
	 *
	 * @param array<string,mixed> $zone
	 */
	private function covers(array $zone, string $country, string $region): bool
	{
		$countries = array_map(static fn (string $code): string => strtoupper(trim($code)), $this->strings($zone['countries'] ?? []));
		if ($countries !== [] && !in_array($country, $countries, true)) {
			return false;
		}

		$regions = $this->strings($zone['regions'] ?? []);
		if ($regions === []) {
			return true;
		}

		foreach ($regions as $candidate) {
			if ($region !== '' && Regions::same($country, $candidate, $region)) {
				return true;
			}
		}

		return false;
	}

	private function rate(string $id, string $label, string $amount, string $freeOver, Money $subtotal): ShippingRate
	{
		$currency = $subtotal->currency();
		$over     = $freeOver !== '' ? $this->parse($freeOver, $currency) : null;
		if ($over instanceof Money && !$over->isZero() && $subtotal->amount() >= $over->amount()) {
			return new ShippingRate($id, self::FREE, Money::zero($currency));
		}

		return new ShippingRate($id, $label, $this->parse($amount, $currency) ?? Money::zero($currency));
	}

	/** An amount as it was typed, in the order's currency. Null when it is not an amount. */
	private function parse(string $amount, string $currency): ?Money
	{
		try {
			return $this->money->fromDecimal($amount, $currency);
		} catch (\InvalidArgumentException|\OverflowException) {
			return null;
		}
	}

	/** A price field's value as a plain decimal, or '' when it holds none. */
	private function decimal(mixed $value): string
	{
		if (is_int($value) || is_float($value)) {
			$value = (string)$value;
		}

		return is_string($value) && preg_match('/^\d+(\.\d+)?\z/', trim($value)) === 1 ? trim($value) : '';
	}

	/**
	 * @return list<string> the non-empty strings of a list field's value
	 */
	private function strings(mixed $value): array
	{
		return array_values(array_filter(is_array($value) ? $value : [], static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
	}
}
