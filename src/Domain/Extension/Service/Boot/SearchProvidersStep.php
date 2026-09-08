<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;

/**
 * Wire extension search providers into the SearchProviderRegistry.
 * Strict-deny on collisions (matches MCP tool registrar policy). The
 * registry's register() method already throws LogicException on
 * duplicate ids — wrap each call so one bad extension can't break the
 * rest of the drain.
 */
final readonly class SearchProvidersStep implements ExtensionBootStep
{
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	public function wire(ExtensionManager $manager): void
	{
		if (!$this->container->has(SearchProviderRegistry::class)) {
			return;
		}
		/** @var SearchProviderRegistry $searchRegistry */
		$searchRegistry = $this->container->get(SearchProviderRegistry::class);
		foreach ($manager->getAllMcpSearchProviders() as $extensionId => $providers) {
			foreach ($providers as $provider) {
				try {
					$searchRegistry->register($provider);
				} catch (\LogicException $e) {
					$this->logger->warning('Extension search provider registration collision; skipped', [
						'extension'   => $extensionId,
						'provider_id' => $provider->id(),
						'message'     => $e->getMessage(),
					]);
				}
			}
		}
	}
}
