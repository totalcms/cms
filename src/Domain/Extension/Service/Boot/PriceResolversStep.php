<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Commerce\Order\OrderBuildingRegistry;
use TotalCMS\Domain\Commerce\Pricing\PriceResolverRegistry;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/**
 * Wire extension price resolvers into the PriceResolverRegistry, and their
 * order-building listeners into the OrderBuildingRegistry.
 * Strict-deny on collisions, like search providers: the registry throws
 * LogicException for an id that is taken or cannot be used, and each call is
 * wrapped so one bad extension cannot stop the rest.
 */
final readonly class PriceResolversStep extends BootStep
{
	public function wire(ExtensionManager $manager): void
	{
		if (!$this->container->has(PriceResolverRegistry::class)) {
			return;
		}
		/** @var PriceResolverRegistry $registry */
		$registry = $this->container->get(PriceResolverRegistry::class);
		foreach ($manager->getAllPriceResolvers() as $extensionId => $resolvers) {
			foreach ($resolvers as $entry) {
				try {
					$registry->register($entry['resolver'], $entry['priority']);
				} catch (\Throwable $e) {
					$this->logger->warning('Extension price resolver could not be registered; skipped', [
						'extension' => $extensionId,
						'message'   => $e->getMessage(),
					]);
				}
			}
		}

		$this->wireOrderBuildingListeners($manager);
	}

	/** The other half of the `commerce:pricing` capability: listeners that write an order's metadata. */
	private function wireOrderBuildingListeners(ExtensionManager $manager): void
	{
		if (!$this->container->has(OrderBuildingRegistry::class)) {
			return;
		}
		/** @var OrderBuildingRegistry $registry */
		$registry = $this->container->get(OrderBuildingRegistry::class);
		foreach ($manager->getAllOrderBuildingListeners() as $listeners) {
			foreach ($listeners as $entry) {
				$registry->add($entry['listener'], $entry['priority']);
			}
		}
	}
}
