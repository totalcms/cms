<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Contract;

use TotalCMS\Domain\Commerce\Order\OrderDraft;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;

/**
 * A veto on a checkout. It sees the order the server built, before any
 * provider is asked for money, and may refuse it. It cannot change it.
 *
 * Register one with `$context->addCheckoutValidator()`.
 */
interface CheckoutValidator
{
	/** null to allow, or the reason to show the buyer. */
	public function validate(OrderDraft $draft, PricingContext $context): ?string;
}
