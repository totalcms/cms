<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

/**
 * Whether the provider took the refund request. The order is not refunded
 * until the provider's webhook says so.
 */
final readonly class RefundResult
{
	public function __construct(
		public bool $accepted,
		public string $providerRefundRef = '',
		public string $error = '',
	) {
	}
}
