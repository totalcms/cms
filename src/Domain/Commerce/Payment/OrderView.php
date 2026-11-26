<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use TotalCMS\Domain\Money\Money;

/**
 * An order as a payment provider sees it: enough to find its own payment,
 * and nothing it could change. It never carries the order's access token.
 */
final readonly class OrderView
{
	public function __construct(
		public string $id,
		public int $number,
		public string $email,
		public Money $grandTotal,
		public string $paymentStatus,
		public string $provider,
		public string $providerSessionRef = '',
		public string $providerPaymentRef = '',
	) {
	}
}
