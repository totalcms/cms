<?php

declare(strict_types=1);

namespace TestVendor\CleanExt;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use Twig\TwigFunction;

/**
 * Test fixture: a benign extension. It registers a single Twig function — a
 * non-risky capability — and contains no source patterns the DangerousCodeScanner
 * flags. Used to verify the "clean extension → no flags → enable directly" path.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$context->addTwigFunction(new TwigFunction('clean_hello', fn (): string => 'Hello, clean world!'));
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
