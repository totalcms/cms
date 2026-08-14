<?php

declare(strict_types=1);

namespace FixtureVendor\AutoEnabledExt;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

/**
 * Fixture for testing `default_enabled` auto-enrolment on a BUNDLED manifest.
 * See tests/Unit/Domain/Extension/Service/ExtensionManagerTest.php.
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
