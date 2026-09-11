<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Service\CoreAdminAssetRegistrar;
use TotalCMS\Domain\Twig\Service\CoreFrontendAssetRegistrar;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;

/**
 * Wire core + extension admin and frontend assets into the CMS Twig
 * adapter, powering cms.adminAssetsHead/Body() and cms.assetsHead/Body().
 */
final readonly class AssetsStep extends BootStep
{
	public function wire(ExtensionManager $manager): void
	{
		// Wire admin + frontend assets through the CMS adapter for the
		// new cms.adminAssetsHead/Body() and cms.assetsHead/Body() helpers.
		if (!$this->container->has(TwigEngine::class) || !$this->container->has(TotalCMSTwigAdapter::class)) {
			return;
		}
		/** @var TotalCMSTwigAdapter $cmsAdapter */
		$cmsAdapter = $this->container->get(TotalCMSTwigAdapter::class);

		// Core T3 assets first so they render before extension assets. A site
		// can leave core frontend features it never renders out of the page
		// (`frontendAssets.except` in tcms.php); the admin set is not tunable.
		(new CoreAdminAssetRegistrar())->register($cmsAdapter);
		(new CoreFrontendAssetRegistrar())->register($cmsAdapter, $this->frontendAssetsExcept());

		$adminAssets = $manager->getAllAdminAssets();
		if ($adminAssets !== []) {
			$cmsAdapter->addAdminAssets($adminAssets);
		}

		$frontendAssets = $manager->getAllFrontendAssets();
		if ($frontendAssets !== []) {
			$cmsAdapter->addFrontendAssets($frontendAssets);
		}
	}

	/** @return list<string> */
	private function frontendAssetsExcept(): array
	{
		if (!$this->container->has(Config::class)) {
			return [];
		}

		/** @var Config $config */
		$config = $this->container->get(Config::class);
		$except = $config->frontendAssets['except'] ?? [];

		return is_array($except) ? array_values(array_filter($except, 'is_string')) : [];
	}
}
