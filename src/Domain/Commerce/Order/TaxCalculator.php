<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * Works out the tax on an order.
 */
interface TaxCalculator
{
	public const NONE      = 'none';
	public const EXCLUSIVE = 'exclusive';
	public const INCLUSIVE = 'inclusive';

	/**
	 * Not a mode a store is set to: the tax mode of one order, while it
	 * waits to be paid, when its tax is left to the payment provider (Stripe
	 * Tax). The order is built without tax and takes its tax, its total and
	 * one of the three modes above from the payment.
	 */
	public const PROVIDER = 'provider';

	/** Who works the tax out: the store, from its table of rates, or the payment provider when it can. */
	public const BY_TABLE    = 'table';
	public const BY_PROVIDER = 'provider';

	/**
	 * none | exclusive | inclusive. Exclusive tax is added to the total;
	 * inclusive tax is already inside the prices.
	 */
	public function mode(): string;

	/**
	 * Whether a buyer in this country must say which region they are in,
	 * because the tax there depends on it. A two-letter country code.
	 */
	public function needsRegion(string $country): bool;

	/**
	 * The tax on each line and on shipping, in minor units. A line is taxed
	 * on what is charged for it: {@see OrderLineDraft::netTotal()}.
	 *
	 * @param array<string,OrderLineDraft> $lines
	 *
	 * @return array{lines: array<string,int>, shipping: int, taxLines: list<array{label: string, rate: string, amount: int}>}
	 */
	public function calculate(array $lines, int $shipping, ?Address $address, string $currency): array;
}
