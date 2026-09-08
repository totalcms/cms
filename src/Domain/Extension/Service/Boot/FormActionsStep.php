<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\FormActionRegistry;

/** Wire form-action registrations from extensions into the registry. */
final readonly class FormActionsStep implements ExtensionBootStep
{
	public function __construct(
		private ContainerInterface $container,
		// @phpstan-ignore property.onlyWritten
		private LoggerInterface $logger,
	) {
	}

	public function wire(ExtensionManager $manager): void
	{
		if (!$this->container->has(FormActionRegistry::class)) {
			return;
		}
		/** @var FormActionRegistry $formActionRegistry */
		$formActionRegistry = $this->container->get(FormActionRegistry::class);
		foreach ($manager->getAllFormActions() as $formActions) {
			foreach ($formActions as $formAction) {
				$formActionRegistry->register($formAction);
			}
		}
	}
}
