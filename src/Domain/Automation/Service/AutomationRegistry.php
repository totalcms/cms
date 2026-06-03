<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Extension\Data\AutomationDefinition;

/**
 * In-memory registry of extension-contributed automations, keyed
 * `{extensionId}:{automationId}`. Populated once per request by
 * ExtensionManager::bootAll() (from enabled, permitted extensions) and read by
 * AutomationLoader so extension automations join the schedule/event dispatch
 * alongside file-based ones. A shared (singleton) container service, so boot's
 * writes are visible to every later read.
 */
final class AutomationRegistry
{
	/** @var array<string,AutomationDefinition> */
	private array $automations = [];

	public function register(string $key, AutomationDefinition $definition): void
	{
		$this->automations[$key] = $definition;
	}

	/** @return array<string,AutomationDefinition> */
	public function all(): array
	{
		return $this->automations;
	}

	public function get(string $key): ?AutomationDefinition
	{
		return $this->automations[$key] ?? null;
	}
}
