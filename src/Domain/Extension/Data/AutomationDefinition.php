<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

/**
 * An automation contributed by an extension via
 * ExtensionContext::addAutomation(). The handler closure is held in memory only
 * — it is never persisted as a sidecar file nor registered as a container
 * definition — so extension automations are surfaced read-only in the admin
 * (they have no editable handler file to write back to).
 */
final readonly class AutomationDefinition
{
	/**
	 * @param list<array<string,mixed>> $triggers
	 */
	public function __construct(
		public string $id,
		public string $label,
		public array $triggers,
		public \Closure $handler,
	) {
	}
}
