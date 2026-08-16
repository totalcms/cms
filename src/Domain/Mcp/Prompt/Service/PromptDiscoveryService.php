<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Prompt\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptData;
use TotalCMS\Domain\Mcp\Prompt\Exception\PromptCollisionException;

final class PromptDiscoveryService
{
	public const COLLECTION_ID = 'mcp-prompt';

	/** @var list<PromptData>|null */
	private ?array $cache = null;

	public function __construct(
		private readonly IndexFilter $indexFilter,
		private readonly CollectionRepository $collections,
		private readonly LoggerInterface $logger,
		private readonly ?DataViewLister $views = null,
	) {
	}

	/**
	 * Returns the list of prompts from the mcp-prompt collection.
	 * Result is cached in memory for the lifetime of this service instance.
	 * Call clearCache() to force a re-read (used by PromptChangeListener).
	 *
	 * @return list<PromptData>
	 */
	public function discover(): array
	{
		if ($this->cache !== null) {
			return $this->cache;
		}

		// The mcp-prompt collection is auto-created by EnsureMcpPromptCollectionMigration
		// on Pro installs, but absent on Lite/Standard installs and in fresh test
		// environments. Guard against the missing-collection case so MCP server boot
		// stays silent for sites that don't have prompts (no collection => no prompts).
		if (!$this->collections->collectionExists(self::COLLECTION_ID)) {
			return $this->cache = [];
		}

		$raw     = $this->indexFilter->fetchFilteredIndex(self::COLLECTION_ID);
		$seen    = [];
		$prompts = [];

		foreach ($raw as $row) {
			// Object collections always provide `id` as the canonical key; tolerate
			// `name` as a fallback for code-defined / programmatic construction paths.
			$hasId   = isset($row['id']) && is_string($row['id']) && $row['id'] !== '';
			$hasName = isset($row['name']) && is_string($row['name']) && $row['name'] !== '';
			if (!$hasId && !$hasName) {
				continue;
			}
			$prompt = PromptData::fromArray($row);
			if (isset($seen[$prompt->name])) {
				throw new PromptCollisionException(sprintf(
					'Duplicate MCP prompt name "%s" in collection mcp-prompt.',
					$prompt->name,
				));
			}
			$seen[$prompt->name] = true;

			$resolvedAccess = $this->resolveAccess($prompt);
			$prompts[]      = new PromptData(
				name: $prompt->name,
				description: $prompt->description,
				body: $prompt->body,
				args: $prompt->args,
				target: $prompt->target,
				access: $resolvedAccess,
			);
		}

		return $this->cache = $prompts;
	}

	/**
	 * Clears the in-memory prompt cache.
	 * Called by PromptChangeListener when an mcp-prompt object is created,
	 * updated, or deleted — the next discover() call will re-read from disk.
	 */
	public function clearCache(): void
	{
		$this->cache = null;
	}

	/**
	 * Resolve a prompt's effective access: explicit wins, else inherit from the
	 * target, else admin.
	 *
	 * A target names either a collection or a Data View, in one field. The two
	 * namespaces are independent — nothing stops a view and a collection sharing
	 * an id — so the order is fixed and deliberate: **collection first**.
	 * Collections are filtered by access group; views are not (a view's own
	 * `mcp.access` is its whole gate, by design). Resolving a colliding id to the
	 * collection therefore lands on the stricter of the two models. The other
	 * order would let a view named after a collection silently widen a prompt's
	 * reach.
	 *
	 * Note which question decides the branch: whether the target *exists* as a
	 * collection, not whether that collection happens to set `mcp.access`. A real
	 * collection with no access configured resolves to admin and stops; it does
	 * not fall through and start hunting for a view of the same name.
	 */
	private function resolveAccess(PromptData $prompt): string
	{
		if ($prompt->access !== '') {
			return $prompt->access;
		}

		if ($prompt->target === '') {
			return 'admin';
		}

		// CollectionData::$mcp is array<string,mixed>; MCP access is stored at $mcp['access'].
		try {
			$collection = $this->collections->fetchCollection($prompt->target);
			if ($collection instanceof \TotalCMS\Domain\Collection\Data\CollectionData) {
				return isset($collection->mcp['access']) && is_string($collection->mcp['access']) && $collection->mcp['access'] !== ''
					? $collection->mcp['access']
					: 'admin';
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to resolve target-collection access for prompt', [
				'prompt' => $prompt->name,
				'target' => $prompt->target,
				'error'  => $e->getMessage(),
			]);
		}

		$viewAccess = $this->viewAccess($prompt->target, $prompt->name);
		if ($viewAccess !== null) {
			return $viewAccess;
		}

		return 'admin';
	}

	/**
	 * A Data View's declared MCP access, or null when no view carries this id.
	 *
	 * Views declare `mcp` with the same `mcp-collection.json` shape collections
	 * use, so this reads identically to the collection branch above.
	 */
	private function viewAccess(string $id, string $promptName): ?string
	{
		if (!$this->views instanceof DataViewLister) {
			return null;
		}

		try {
			foreach ($this->views->listViews() as $entry) {
				if (!is_array($entry) || (string)($entry['id'] ?? '') !== $id) {
					continue;
				}

				$mcp = is_array($entry['mcp'] ?? null) ? $entry['mcp'] : [];

				return isset($mcp['access']) && is_string($mcp['access']) && $mcp['access'] !== ''
					? $mcp['access']
					: 'admin';
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to resolve target-view access for prompt', [
				'prompt' => $promptName,
				'target' => $id,
				'error'  => $e->getMessage(),
			]);
		}

		return null;
	}
}
