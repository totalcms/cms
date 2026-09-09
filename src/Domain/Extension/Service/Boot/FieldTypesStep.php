<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/** Register extension field types in the form builder and schema property editor. */
final readonly class FieldTypesStep extends BootStep
{
	public function wire(ExtensionManager $manager): void
	{
		$extFieldTypes = $manager->getAllFieldTypes();
		if ($extFieldTypes !== []) {
			TotalForm::registerExtensionFieldTypes($extFieldTypes);
			TotalForm::registerExtensionFieldDefaultTypes($manager->getAllFieldDefaultTypes());
		}
	}
}
