<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Pricing;

use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Data\VariantView;
use TotalCMS\Domain\Money\Money;

/**
 * Sets a price that depends on who is asking: an upgrade price for someone
 * who owns the lower edition, a member's price, a volume price.
 *
 * Registered by an extension with `$context->addPriceResolver()`. The same
 * call prices the cart a visitor sees and the order that is charged.
 */
interface PriceResolver
{
	/** A short id of your own, such as `upgrade`. It is recorded on every order line you price. */
	public function id(): string;

	/**
	 * The unit price for this buyer, or null for no opinion.
	 *
	 * Give the same answer for the same inputs within one request. The amount
	 * must be in `$ctx->currency` and not negative, or it is ignored.
	 */
	public function resolve(ProductView $product, ?VariantView $variant, int $qty, PricingContext $ctx): ?Money;
}
