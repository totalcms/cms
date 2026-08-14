<?php

declare(strict_types=1);

namespace FixtureVendor\ShadowExt\UserInstalled;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

/**
 * Non-bundled ("shadowing") half of the id-shadowing fixture pair. See
 * tests/fixtures/bundled-extensions/fixture-vendor/shadow-ext for the bundled
 * half and tests/Unit/Domain/Extension/Service/ExtensionManagerTest.php for
 * the test that exercises the shadow-consent-gap fix.
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
