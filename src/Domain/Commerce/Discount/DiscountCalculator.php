<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Discount;

use TotalCMS\Domain\Commerce\Service\MoneyConverter;
use TotalCMS\Domain\Money\Money;

/**
 * Works out what a code takes off a cart, and off which lines. It reads
 * nothing and writes nothing.
 *
 * The amount is split across the lines it applies to, in proportion to what
 * each costs, so that the shares add up to the amount exactly and no line
 * is ever discounted by more than it costs.
 */
final readonly class DiscountCalculator
{
	public function __construct(private MoneyConverter $converter)
	{
	}

	/**
	 * @param list<DiscountLine> $lines
	 */
	public function apply(DiscountData $discount, array $lines, string $currency): AppliedDiscount
	{
		$eligible = $this->eligible($discount, $lines);
		// A line in a free trial has nothing due on it now, whatever the code.
		$now       = array_values(array_filter($eligible, static fn (DiscountLine $line): bool => !$line->inTrial));
		$recurring = $discount->recurring === DiscountData::FOREVER
			? array_values(array_filter($eligible, static fn (DiscountLine $line): bool => $line->recurring))
			: [];

		$lineAmounts = $this->shares($discount, $now, $currency);

		return new AppliedDiscount(
			code: $discount->code(),
			type: $discount->type,
			value: $discount->value,
			amount: array_sum($lineAmounts),
			lineAmounts: $lineAmounts,
			recurringLineAmounts: $this->shares($discount, $recurring, $currency),
			freeShipping: $discount->type === DiscountData::FREE_SHIPPING,
			recurring: $discount->recurring,
		);
	}

	/**
	 * The lines a code can apply to: every line when the code names no
	 * products and no categories, otherwise the lines it names. A code for
	 * the first payment only cannot apply to a line whose first payment is
	 * nothing.
	 *
	 * @param list<DiscountLine> $lines
	 *
	 * @return list<DiscountLine>
	 */
	public function eligible(DiscountData $discount, array $lines): array
	{
		$categories = array_map(static fn (string $category): string => mb_strtolower(trim($category)), $discount->categories);
		$everything = $discount->products === [] && $categories === [];

		return array_values(array_filter($lines, static function (DiscountLine $line) use ($discount, $categories, $everything): bool {
			if ($line->inTrial && $discount->recurring !== DiscountData::FOREVER) {
				return false;
			}

			return $everything || in_array($line->productId, $discount->products, true) || in_array(mb_strtolower(trim($line->category)), $categories, true);
		}));
	}

	/**
	 * $amount shared out in proportion to $weights, by largest remainder:
	 * each gets the whole part of its share, and what is left over goes one
	 * unit at a time to those with the largest fractions, the earlier first
	 * on a tie. The shares add up to the amount, as long as it is no more
	 * than the weights do.
	 *
	 * @param array<string,int> $weights line id => what the line costs
	 *
	 * @return array<string,int>
	 */
	public function split(int $amount, array $weights): array
	{
		$base = array_sum($weights);
		if ($base <= 0 || $amount <= 0) {
			return array_fill_keys(array_keys($weights), 0);
		}

		$amount     = min($amount, $base);
		$shares     = [];
		$remainders = [];
		foreach ($weights as $id => $weight) {
			$shares[$id]     = intdiv($amount * $weight, $base);
			$remainders[$id] = ($amount * $weight) % $base;
		}

		$left = $amount - array_sum($shares);
		// Largest fraction first, the earlier line first on a tie.
		$position = array_flip(array_keys($weights));
		$order    = array_keys($weights);
		usort($order, static fn (int|string $a, int|string $b): int => [$remainders[$b], $position[$a]] <=> [$remainders[$a], $position[$b]]);
		foreach ($order as $id) {
			if ($left <= 0) {
				break;
			}
			$shares[$id]++;
			$left--;
		}

		return $shares;
	}

	/**
	 * What the code takes off each of these lines.
	 *
	 * @param list<DiscountLine> $lines
	 *
	 * @return array<string,int> line id => amount; empty when it takes nothing off any line
	 */
	private function shares(DiscountData $discount, array $lines, string $currency): array
	{
		if ($lines === [] || $discount->type === DiscountData::FREE_SHIPPING) {
			return [];
		}

		$weights = [];
		foreach ($lines as $line) {
			$weights[$line->lineId] = max(0, $line->lineTotal);
		}
		$base = array_sum($weights);

		$amount = $discount->type === DiscountData::PERCENT
			? Money::of($base, $currency)->percentage($discount->value)->amount()
			: $this->converter->fromDecimal($discount->value, $currency)->amount();

		return $this->split(min($amount, $base), $weights);
	}
}
