<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Pricing;

/**
 * Who a price is being worked out for.
 */
final readonly class PricingContext
{
	/**
	 * @param string               $currency           the store's currency; every price is in it
	 * @param string|null          $customerCollection the auth collection of the signed-in customer, or null for a guest
	 * @param string|null          $customerId         the signed-in customer, or null for a guest
	 * @param array<string,string> $attributes         the checkout attributes the settings allow, such as ['licenseKey' => '…']
	 */
	public function __construct(
		public string $currency,
		public ?string $customerCollection,
		public ?string $customerId,
		public array $attributes,
	) {
	}
}
