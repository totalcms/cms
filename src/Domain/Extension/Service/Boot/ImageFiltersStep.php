<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Extension\Service\ExtensionGuard;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\GuardedImageFilter;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilter;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilterRegistry;

/**
 * Wire ImageWorks filter registrations from extensions into the filter
 * registry. With no extension filters the registry is left unbuilt: this runs
 * on every request, and building it logs any refused site-config filter.
 */
final readonly class ImageFiltersStep extends BootStep
{
	public function wire(ExtensionManager $manager): void
	{
		$byExtension = $manager->getAllImageFilters();
		if ($byExtension === [] || !$this->container->has(ImageFilterRegistry::class)) {
			return;
		}

		$guard = $this->container->has(ExtensionGuard::class) ? $this->container->get(ExtensionGuard::class) : null;
		if ($guard instanceof ExtensionGuard) {
			foreach ($byExtension as $extensionId => $filters) {
				$byExtension[$extensionId] = array_map(
					fn (array $filter): array => $filter['filter'] instanceof ImageFilter
						? ['name' => $filter['name'], 'filter' => new GuardedImageFilter($filter['filter'], $guard, $extensionId, $filter['name']), 'group' => $filter['group']]
						: $filter,
					$filters,
				);
			}
		}

		/** @var ImageFilterRegistry $registry */
		$registry = $this->container->get(ImageFilterRegistry::class);
		// The registry validates each one (vendor prefix, duplicates, recipe,
		// group) and logs a refusal, so one bad filter never blocks the rest.
		$registry->registerExtensionFilters($byExtension);
	}
}
