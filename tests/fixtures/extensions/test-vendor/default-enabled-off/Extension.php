<?php

declare(strict_types=1);

namespace TestVendor\DefaultEnabledOff;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

/**
 * Fixture for testing that a NON-bundled manifest declaring `default_enabled`
 * has no effect (the security invariant). See
 * tests/Unit/Domain/Extension/Service/ExtensionManagerTest.php.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
