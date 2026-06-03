<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Automation\Data\AutomationDescriptor;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Service\ExternalFieldStore;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Lists enabled automations (file-based objects + extension-contributed ones)
 * and resolves a handler callable. File handlers are required from their
 * externalized `.php` sidecar; extension handlers are the in-memory closure the
 * extension registered. Either way the handler is invoked directly — never
 * registered as a container definition.
 */
final readonly class AutomationLoader
{
	public function __construct(
		private IndexReader $indexReader,
		private ObjectFetcher $objectFetcher,
		private ExternalFieldStore $externalFields,
		private CollectionFetcher $collectionFetcher,
		private AutomationRegistry $registry,
		private Config $config,
	) {
	}

	/**
	 * All enabled automation objects.
	 *
	 * @return list<ObjectData>
	 */
	public function enabled(): array
	{
		// Cheap short-circuit: the event subscriber calls this on every core
		// event, so on sites without an automations collection avoid touching
		// the index reader (which would build an empty index on every event).
		if (!$this->collectionFetcher->collectionExists('automations')) {
			return [];
		}

		// `enabled` (and `id`) are in the collection index, so filter on the index
		// rows first and only fetch the automations that survive — disabled ones
		// are never loaded.
		$ids = $this->indexReader->fetchIndex('automations')->objects
			->filter(static fn (array $row): bool => ($row['id'] ?? '') !== '' && filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN))
			->pluck('id')
			->all();

		$automations = [];

		foreach ($ids as $id) {
			try {
				$automations[] = $this->objectFetcher->fetchObject('automations', (string)$id);
			} catch (\Throwable) {
				// skip a stale index entry whose object is gone
			}
		}

		return $automations;
	}

	/**
	 * Every enabled automation flattened to {id, triggers, isExtension} — the
	 * shape the schedule + event dispatch paths consume. File-based automations
	 * come from enabled(); extension automations come from the registry (already
	 * filtered to enabled, permitted extensions). Webhooks deliberately do NOT
	 * use this (they stay file-only — see AutomationResolver::webhook()).
	 *
	 * @return list<AutomationDescriptor>
	 */
	public function all(): array
	{
		$descriptors = [];

		foreach ($this->enabled() as $object) {
			$descriptors[] = new AutomationDescriptor(
				(string)$object->id,
				$this->normalizeTriggers($object->properties->get('triggers')),
				false,
			);
		}

		foreach ($this->registry->all() as $key => $definition) {
			$descriptors[] = new AutomationDescriptor(
				$key,
				array_values(array_filter($definition->triggers, is_array(...))),
				true,
			);
		}

		return $descriptors;
	}

	/**
	 * Resolve a handler callable. An id containing ':' is an extension
	 * automation (`{vendor/name}:{id}`) whose handler is an in-memory closure;
	 * otherwise it is a file-based automation whose handler is required from its
	 * externalized sidecar. (File automation ids are slug-formatted and never
	 * contain a colon, so the split is unambiguous.).
	 */
	public function handler(string $id): callable
	{
		if (str_contains($id, ':')) {
			$definition = $this->registry->get($id);
			if (!$definition instanceof \TotalCMS\Domain\Extension\Data\AutomationDefinition) {
				throw new \RuntimeException("Extension automation '{$id}' is not registered.");
			}

			return $definition->handler;
		}

		$relative = $this->externalFields->sidecarPath('automations', $id, 'handler', 'php');
		$absolute = PathUtils::absolutePath($this->config->datadir, $relative);

		if (!is_file($absolute)) {
			throw new \RuntimeException("Automation handler file not found for '{$id}'.");
		}

		$fn = require $absolute;
		if (!is_callable($fn)) {
			throw new \RuntimeException("Automation '{$id}' handler did not return a closure.");
		}

		return $fn;
	}

	/**
	 * Normalize a triggers property (DeckData or array) to a plain list of
	 * trigger rows.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function normalizeTriggers(mixed $triggers): array
	{
		$rows = $triggers instanceof DeckData ? $triggers->transform() : $triggers;

		return array_values(array_filter(is_array($rows) ? $rows : [], is_array(...)));
	}
}
