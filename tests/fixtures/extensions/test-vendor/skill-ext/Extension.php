<?php

declare(strict_types=1);

namespace TestVendor\SkillExt;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

/**
 * Test fixture: an extension that ships an agent skill in `skill/`. Used to
 * verify the pre-enable review shows the skill text and that skill:install
 * copies it into `.claude/skills/{vendor}-{name}/` while the extension is on.
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
