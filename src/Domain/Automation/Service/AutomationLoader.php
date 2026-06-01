<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\ExternalFieldStore;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Lists enabled automation objects and resolves a handler closure by requiring
 * its externalized handler file. The handler is loaded at runtime and invoked
 * directly — never registered as a container definition.
 */
final readonly class AutomationLoader
{
	public function __construct(
		private IndexReader $indexReader,
		private ObjectFetcher $objectFetcher,
		private ExternalFieldStore $externalFields,
		private CollectionFetcher $collectionFetcher,
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
			->filter(static fn (array $row): bool =>
				($row['id'] ?? '') !== '' && filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN))
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
	 * Resolve a handler closure by requiring its external handler file.
	 */
	public function handler(string $id): callable
	{
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
}
