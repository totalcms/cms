<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Automation\Service\AutomationRegistry;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/**
 * Wire extension-contributed automations into the shared registry so they
 * join the schedule/event dispatch (read-only — handler is an in-memory
 * closure). Permission-gated inside getAllAutomations().
 */
final readonly class AutomationsStep implements ExtensionBootStep
{
	public function __construct(
		private ContainerInterface $container,
		// @phpstan-ignore property.onlyWritten
		private LoggerInterface $logger,
	) {
	}

	public function wire(ExtensionManager $manager): void
	{
		if (!$this->container->has(AutomationRegistry::class)) {
			return;
		}

		$extensionAutomations = $manager->getAllAutomations();
		if ($extensionAutomations !== []) {
			/** @var AutomationRegistry $automationRegistry */
			$automationRegistry = $this->container->get(AutomationRegistry::class);
			foreach ($extensionAutomations as $key => $definition) {
				$automationRegistry->register($key, $definition);
			}
		}
	}
}
