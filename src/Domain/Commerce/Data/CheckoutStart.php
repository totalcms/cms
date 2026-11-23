<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Data;

use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Payment\EmbeddedCheckout;

/**
 * A checkout that has begun: the order, and where the buyer goes next.
 *
 * Hosted: `redirectUrl` is the provider's payment page. On-page: it is the
 * store's own pay page, and `embed` is what that page mounts.
 */
final readonly class CheckoutStart
{
	public function __construct(
		public OrderData $order,
		public string $redirectUrl,
		public ?EmbeddedCheckout $embed = null,
	) {
	}
}
