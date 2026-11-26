<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Pricing;

use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\CurrentCustomer;

/**
 * The pricing context of the request in hand: the store's currency, the
 * signed-in customer if there is one, and the checkout attributes the
 * settings allow.
 */
final readonly class PricingContextFactory
{
	public function __construct(
		private CommerceSettings $settings,
		private CurrentCustomer $customer,
	) {
	}

	/**
	 * @param array<mixed> $attributes checkout attributes as the buyer sent them; anything the settings do not name is dropped
	 */
	public function current(array $attributes = []): PricingContext
	{
		$customer = $this->customer->get();

		return new PricingContext($this->settings->currency(), $customer['collection'] ?? null, $customer['id'] ?? null, $this->allowed($attributes));
	}

	/**
	 * @param array<mixed> $attributes
	 *
	 * @return array<string,string>
	 */
	private function allowed(array $attributes): array
	{
		$kept = [];
		foreach ($this->settings->checkoutAttributes() as $name) {
			$value = $attributes[$name] ?? null;
			if (is_string($value) && trim($value) !== '') {
				$kept[$name] = trim($value);
			}
		}

		return $kept;
	}
}
