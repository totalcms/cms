<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\DeckData;

/**
 * Resolves which enabled automation(s) a webhook request or a dispatched event
 * should fire. Webhooks are keyed by the automation id (one automation = one
 * endpoint, `POST /automations/<id>`); events match on event name + optional
 * collection filter.
 */
final readonly class AutomationResolver
{
	public function __construct(private AutomationLoader $loader)
	{
	}

	/**
	 * The webhook trigger for an automation id, or null if none/disabled.
	 *
	 * @return array{id: string, trigger: array<string,mixed>}|null
	 */
	public function webhook(string $automationId): ?array
	{
		foreach ($this->loader->enabled() as $automation) {
			if ($automation->id !== $automationId) {
				continue;
			}
			foreach ($this->triggers($automation) as $trigger) {
				if (($trigger['type'] ?? '') === 'webhook') {
					return ['id' => $automation->id, 'trigger' => $trigger];
				}
			}
		}

		return null;
	}

	/**
	 * All event triggers (across enabled automations) matching an event +
	 * collection. A blank collection filter on a trigger matches any collection.
	 *
	 * @return list<array{id: string, trigger: array<string,mixed>}>
	 */
	public function eventTriggers(string $event, string $collection): array
	{
		$out = [];

		// all() so extension-contributed automations match events too.
		foreach ($this->loader->all() as $automation) {
			foreach ($automation->triggers as $trigger) {
				if (($trigger['type'] ?? '') !== 'event' || ($trigger['event'] ?? '') !== $event) {
					continue;
				}
				$filter = (string)($trigger['collection'] ?? '');
				if ($filter !== '' && $filter !== $collection) {
					continue;
				}
				$out[] = ['id' => $automation->id, 'trigger' => $trigger];
			}
		}

		return $out;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function triggers(ObjectData $automation): array
	{
		$triggers = $automation->properties->get('triggers');
		$rows     = $triggers instanceof DeckData ? $triggers->transform() : $triggers;

		return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
	}
}
