<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\AbSplit;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Twig\Service\TwigEngine;

// Bundled extensions don't ship their own composer autoloader. ExtensionManager
// only require_once's the entrypoint, so any sibling files the extension
// uses must be loaded explicitly here.
require_once __DIR__ . '/AbSplitMiddleware.php';

/**
 * A/B Split — bundled with Total CMS.
 *
 * Registers the `ab-split` page-feature, which renders an alternate template
 * at the same URL for a percentage of visitors. See the README for the
 * per-page configuration shape.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		// The middleware itself — needs TwigEngine to render the alternate
		// template. Register the container definition before registering the
		// middleware name so the registry can resolve it later.
		$context->addContainerDefinition(
			AbSplitMiddleware::class,
			fn ($container) => new AbSplitMiddleware(
				$container->get(TwigEngine::class),
			),
		);

		// Surface as `ab-split` in the page-features picker.
		$context->addPageMiddleware('ab-split', AbSplitMiddleware::class);
	}

	public function boot(ExtensionContext $context): void
	{
		// Nothing to do at boot — the middleware registration in register()
		// is enough.
	}
}
