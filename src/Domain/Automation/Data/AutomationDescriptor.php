<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Data;

/**
 * A runnable automation flattened to just what the dispatch paths need — its
 * id and trigger rows — regardless of whether it is a file-based automation
 * (an object in the `automations` collection) or one contributed by an
 * extension via ExtensionContext::addAutomation(). `isExtension` lets the admin
 * tag the extension-owned ones read-only.
 */
final readonly class AutomationDescriptor
{
	/**
	 * @param list<array<string,mixed>> $triggers
	 */
	public function __construct(
		public string $id,
		public array $triggers,
		public bool $isExtension,
	) {
	}
}
