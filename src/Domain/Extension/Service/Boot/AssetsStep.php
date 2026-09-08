<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Service\CoreAdminAssetRegistrar;
use TotalCMS\Domain\Twig\Service\CoreFrontendAssetRegistrar;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * Wire core + extension admin and frontend assets into the CMS Twig
 * adapter, powering cms.adminAssetsHead/Body() and cms.assetsHead/Body().
 */
final readonly class AssetsStep implements ExtensionBootStep
{
	public function __construct(
		private ContainerInterface $container,
		// @phpstan-ignore property.onlyWritten
		private LoggerInterface $logger,
	) {
	}

	public function wire(ExtensionManager $manager): void
	{
		// Wire admin + frontend assets through the CMS adapter for the
		// new cms.adminAssetsHead/Body() and cms.assetsHead/Body() helpers.
		if (!$this->container->has(TwigEngine::class) || !$this->container->has(TotalCMSTwigAdapter::class)) {
			return;
		}
		/** @var TotalCMSTwigAdapter $cmsAdapter */
		$cmsAdapter = $this->container->get(TotalCMSTwigAdapter::class);

		// Core T3 assets first so they render before extension assets.
		(new CoreAdminAssetRegistrar())->register($cmsAdapter);
		(new CoreFrontendAssetRegistrar())->register($cmsAdapter);

		$adminAssets = $manager->getAllAdminAssets();
		if ($adminAssets !== []) {
			$cmsAdapter->addAdminAssets($adminAssets);
		}

		$frontendAssets = $manager->getAllFrontendAssets();
		if ($frontendAssets !== []) {
			$cmsAdapter->addFrontendAssets($frontendAssets);
		}
	}
}
