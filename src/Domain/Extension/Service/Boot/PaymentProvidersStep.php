<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Service\CheckoutValidatorRegistry;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/**
 * Wire extension payment providers into the PaymentProviderRegistry, and
 * their checkout validators into the CheckoutValidatorRegistry.
 *
 * Providers are registered on every site, with or without Commerce: a store
 * whose add-on has lapsed must still hear a provider's webhook about money
 * it already took. The registry offers a buyer none of them there.
 *
 * Strict-deny on collisions, like search providers: the registry throws for
 * an id that is taken or cannot be used, and each call is wrapped so one bad
 * extension cannot stop the rest.
 */
final readonly class PaymentProvidersStep extends BootStep
{
	public function wire(ExtensionManager $manager): void
	{
		$this->wireCheckoutValidators($manager);

		if (!$this->container->has(PaymentProviderRegistry::class)) {
			return;
		}

		/** @var PaymentProviderRegistry $registry */
		$registry = $this->container->get(PaymentProviderRegistry::class);
		foreach ($manager->getAllPaymentProviders() as $extensionId => $providers) {
			foreach ($providers as $provider) {
				try {
					$registry->register($provider);
				} catch (\Throwable $e) {
					$this->logger->warning('Extension payment provider could not be registered; skipped', [
						'extension' => $extensionId,
						'message'   => $e->getMessage(),
					]);
				}
			}
		}
	}

	/**
	 * The checkout's other extension point: validators that may refuse an
	 * order. They need no gate of their own, because nothing asks them
	 * unless a checkout starts, and that is behind the gate.
	 */
	private function wireCheckoutValidators(ExtensionManager $manager): void
	{
		if (!$this->container->has(CheckoutValidatorRegistry::class)) {
			return;
		}
		/** @var CheckoutValidatorRegistry $registry */
		$registry = $this->container->get(CheckoutValidatorRegistry::class);
		foreach ($manager->getAllCheckoutValidators() as $validators) {
			foreach ($validators as $validator) {
				$registry->add($validator);
			}
		}
	}
}
