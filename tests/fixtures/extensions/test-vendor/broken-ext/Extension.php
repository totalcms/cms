<?php

declare(strict_types=1);

namespace TestVendor\BrokenExt;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		// Register succeeds
	}

	public function boot(ExtensionContext $context): void
	{
		throw new \RuntimeException('Intentional boot failure for testing');
	}
}
