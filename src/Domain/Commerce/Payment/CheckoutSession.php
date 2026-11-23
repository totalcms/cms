<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

/**
 * The provider's side of a checkout: its reference, and either a page to
 * send the buyer to (hosted) or a secret to mount the form with (embedded).
 */
final readonly class CheckoutSession
{
	public function __construct(
		public string $providerSessionRef,
		public string $redirectUrl,
		public ?string $clientSecret = null,
	) {
	}
}
