<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use TotalCMS\Domain\Commerce\Order\TaxCalculator;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;

/**
 * The ways to pay that extensions have registered.
 *
 * Three lists come out of it. `all()` is everything installed, for the
 * settings page. `enabled()` is what the store has switched on and that has
 * its keys, in the store's order. `forCart()` is what one cart can use.
 *
 * A provider is registered whether or not the site has Commerce, because
 * its webhook must still be heard on a store whose add-on has lapsed: a
 * payment already taken has to be recorded, and so does its refund. What
 * the add-on decides is what a buyer is offered: on a site without it,
 * `enabled()` is empty.
 */
final class PaymentProviderRegistry
{
	private const ID = '/^[a-z0-9-]+\z/';

	/** @var array<string,PaymentProvider> */
	private array $providers = [];

	public function __construct(
		private readonly CommerceSettings $settings,
		private readonly CommerceGate $gate,
	) {
	}

	/** @throws \LogicException when the id cannot be used or is already taken */
	public function register(PaymentProvider $provider): void
	{
		$id = $provider->id();
		if (preg_match(self::ID, $id) !== 1) {
			throw new \LogicException("A payment provider's id is lower-case letters, digits and hyphens; '{$id}' cannot be used.");
		}
		if (isset($this->providers[$id])) {
			throw new \LogicException("A payment provider with the id '{$id}' is already registered.");
		}

		$this->providers[$id] = $provider;
	}

	public function get(string $id): ?PaymentProvider
	{
		return $this->providers[$id] ?? null;
	}

	/** @return list<PaymentProvider> */
	public function all(): array
	{
		return array_values($this->providers);
	}

	/**
	 * The providers the store has switched on, in the store's order, that are ready to take money.
	 *
	 * @return list<PaymentProvider>
	 */
	public function enabled(): array
	{
		if (!$this->gate->allowed()) {
			return [];
		}

		$enabled = [];
		foreach ($this->settings->enabledProviders() as $id) {
			$provider = $this->providers[$id] ?? null;
			if ($provider instanceof PaymentProvider && $this->ready($provider)) {
				$enabled[] = $provider;
			}
		}

		return $enabled;
	}

	/**
	 * The providers that can take this cart: a cart with a subscription in it
	 * needs one that can bill a subscription, and one with a subscription at
	 * an amount the buyer named needs one that can bill that too.
	 *
	 * @return list<PaymentProvider>
	 */
	public function forCart(bool $hasRecurringLine, bool $hasNamedPlan = false): array
	{
		$enabled = $this->enabled();
		if (!$hasRecurringLine) {
			return $enabled;
		}

		return array_values(array_filter(
			$enabled,
			static fn (PaymentProvider $provider): bool => $provider instanceof SubscriptionProvider
				&& (!$hasNamedPlan || $provider->capabilities()->recurringCustomAmounts),
		));
	}

	/**
	 * Whether this provider works out the tax on the orders it takes: the
	 * store charges tax, leaves it to the provider, and this one can. For
	 * any other the store works it out, from its own rates.
	 */
	public function worksOutTax(PaymentProvider $provider): bool
	{
		return $this->settings->taxMode() !== TaxCalculator::NONE
			&& $this->settings->taxBy() === TaxCalculator::BY_PROVIDER
			&& $provider->capabilities()->tax;
	}

	public function subscriptionProvider(string $id): ?SubscriptionProvider
	{
		$provider = $this->providers[$id] ?? null;

		return $provider instanceof SubscriptionProvider ? $provider : null;
	}

	/** A provider is third-party code: one that cannot answer is one that is not ready. */
	private function ready(PaymentProvider $provider): bool
	{
		try {
			return $provider->isConfigured();
		} catch (\Throwable) {
			return false;
		}
	}
}
