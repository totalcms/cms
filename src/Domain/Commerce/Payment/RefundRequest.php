<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use TotalCMS\Domain\Money\Money;

final readonly class RefundRequest
{
	public function __construct(
		public string $orderId,
		public string $providerPaymentRef,
		public Money $amount,
		public string $reason,
		public string $idempotencyKey,
	) {
	}
}
