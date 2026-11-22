<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Commerce\Pricing\PriceResolverRegistry;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/**
 * Wire extension price resolvers into the PriceResolverRegistry.
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
	}
}
