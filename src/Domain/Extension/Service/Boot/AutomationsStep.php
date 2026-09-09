<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Automation\Service\AutomationRegistry;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/**
 * Wire extension-contributed automations into the shared registry so they
 * join the schedule/event dispatch (read-only — handler is an in-memory
 * closure). Permission-gated inside getAllAutomations().
 */
final readonly class AutomationsStep extends BootStep
{
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
