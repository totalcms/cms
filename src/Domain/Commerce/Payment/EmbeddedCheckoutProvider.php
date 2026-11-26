<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

/**
 * A provider whose checkout can be mounted in the store's own page.
 */
interface EmbeddedCheckoutProvider extends PaymentProvider
{
	/** What the pay page needs to mount the session created with uiMode 'embedded'. */
	public function embed(CheckoutSession $session): EmbeddedCheckout;
}
