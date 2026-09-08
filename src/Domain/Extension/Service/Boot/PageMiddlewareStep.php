<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/** Wire page-middleware registrations from extensions into the registry. */
final readonly class PageMiddlewareStep implements ExtensionBootStep
{
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}

	public function wire(ExtensionManager $manager): void
	{
		if (!$this->container->has(PageMiddlewareRegistry::class)) {
			return;
		}
		/** @var PageMiddlewareRegistry $pageMiddlewareRegistry */
		$pageMiddlewareRegistry = $this->container->get(PageMiddlewareRegistry::class);
		foreach ($manager->getAllPageMiddleware() as $id => $middleware) {
			foreach ($middleware as $name => $serviceId) {
				try {
					$pageMiddlewareRegistry->register($name, $serviceId);
				} catch (\InvalidArgumentException $e) {
					$this->logger->warning("Extension '{$id}' page-middleware registration failed: " . $e->getMessage());
				}
			}
		}
	}
}
