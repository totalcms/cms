<?php

declare(strict_types=1);

namespace TestVendor\IncompatibleExt;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
