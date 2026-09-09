<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\TwigExtensionRegistrar;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigExtension;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * Wire extension Twig functions/filters/globals (with collision protection),
 * template namespaces, and admin nav/dashboard-widget globals into the
 * TwigEngine.
 */
final readonly class TwigStep extends BootStep
{
	public function wire(ExtensionManager $manager): void
	{
		if (!$this->container->has(TwigEngine::class)) {
			return;
		}
		/** @var TwigEngine $twigEngine */
		$twigEngine = $this->container->get(TwigEngine::class);

		$twigRegistrar = new TwigExtensionRegistrar($this->logger);
		$twigRegistrar->filterAndRegister(
			$twigEngine,
			$this->container->get(TotalCMSTwigExtension::class),
			$manager->getAllTwigFunctions(),
			$manager->getAllTwigFilters(),
			$manager->getAllTwigGlobals(),
		);

		// Register extension template directories as Twig namespaces
		foreach ($manager->getAllTemplatePaths() as $namespace => $templatesDir) {
			$twigEngine->addExtensionTemplatePath($templatesDir, $namespace);
		}

		// Pass extension nav items and widgets to templates as globals
		$navItems = $manager->getAllAdminNavItems();
		$widgets  = $manager->getAllDashboardWidgets();

		$globals = [];
		if ($navItems !== []) {
			$globals['extensionNavItems'] = $navItems;
		}
		if ($widgets !== []) {
			$globals['extensionDashWidgets'] = $widgets;
		}

		if ($globals !== []) {
			$twigEngine->registerExtensionItems([], [], $globals);
		}
	}
}
