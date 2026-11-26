<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Discount;

use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderLineDraft;
use TotalCMS\Domain\Commerce\Service\MoneyConverter;
use TotalCMS\Domain\Commerce\Service\MoneyFormatter;
use TotalCMS\Domain\Money\Money;

/**
 * Checks a code a buyer typed and works out what it takes off.
 *
 * A code that cannot be used is refused with one message, whatever the
 * reason: unknown, switched off, out of its dates, used up. A different
 * answer for each would tell a visitor which codes exist.
 */
final readonly class DiscountService
{
	public function __construct(
		private DiscountRepository $discounts,
		private DiscountCalculator $calculator,
		private MoneyConverter $converter,
		private MoneyFormatter $formatter,
	) {
	}

	/**
	 * @param array<string,OrderLineDraft> $lines    the cart's lines, keyed by line id
	 * @param Money                        $subtotal what the cart comes to before any discount
	 * @param string                       $email    the buyer's, for the per-customer limit; '' when not known yet
	 * @param int|null                     $now      the time to check dates against; the real time when null
	 *
	 * @throws CommerceException `discount_invalid`, `discount_minimum` or `discount_no_items`
	 */
	public function resolve(string $code, array $lines, Money $subtotal, string $email, ?int $now = null): AppliedDiscount
	{
		$cart = [];
		foreach ($lines as $lineId => $line) {
			$cart[] = DiscountLine::fromDraft($lineId, $line);
		}

		return $this->resolveLines($code, $cart, $subtotal, $email, $now);
	}

	/**
	 * The same check for a cart that is not an order yet: its lines as the
	 * little a discount needs to know of them.
	 *
	 * @param list<DiscountLine> $lines
	 *
	 * @throws CommerceException `discount_invalid`, `discount_minimum` or `discount_no_items`
	 */
	public function resolveLines(string $code, array $lines, Money $subtotal, string $email, ?int $now = null): AppliedDiscount
	{
		$discount = $this->discounts->find($code);
		if (!$discount instanceof DiscountData || !$this->usable($discount, $email, $now ?? time())) {
			throw CommerceException::discountInvalid();
		}

		if ($discount->minSubtotal !== '') {
			$minimum = $this->converter->fromDecimal($discount->minSubtotal, $subtotal->currency());
			if ($subtotal->amount() < $minimum->amount()) {
				throw CommerceException::discountMinimum($this->formatter->format($minimum));
			}
		}

		$applied = $this->calculator->apply($discount, $lines, $subtotal->currency());
		$nothing = $applied->amount === 0 && $applied->recurringLineAmounts === [] && !$applied->freeShipping;
		if ($nothing || $this->calculator->eligible($discount, $lines) === []) {
			throw CommerceException::discountNoItems();
		}

		return $applied;
	}

	private function usable(DiscountData $discount, string $email, int $now): bool
	{
		if (!$discount->active
			|| ($discount->startsAt !== null && $now < $discount->startsAt)
			|| ($discount->endsAt !== null && $now > $discount->endsAt)
			|| ($discount->usageLimit > 0 && $discount->usedCount >= $discount->usageLimit)) {
			return false;
		}

		// At the cart nobody has said who they are yet; checkout asks again with the email.
		return $email === '' || $discount->perCustomerLimit === 0 || $this->discounts->usesByEmail($discount->id, $email) < $discount->perCustomerLimit;
	}
}
