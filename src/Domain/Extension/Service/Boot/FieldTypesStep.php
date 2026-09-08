<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/** Register extension field types in the form builder and schema property editor. */
final readonly class FieldTypesStep implements ExtensionBootStep
{
	public function __construct(
		// @phpstan-ignore property.onlyWritten
		private ContainerInterface $container,
		// @phpstan-ignore property.onlyWritten
		private LoggerInterface $logger,
	) {
	}

	public function wire(ExtensionManager $manager): void
	{
		$extFieldTypes = $manager->getAllFieldTypes();
		if ($extFieldTypes !== []) {
			TotalForm::registerExtensionFieldTypes($extFieldTypes);
			TotalForm::registerExtensionFieldDefaultTypes($manager->getAllFieldDefaultTypes());
		}
	}
}
