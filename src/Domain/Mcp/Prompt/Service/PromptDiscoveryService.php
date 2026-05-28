<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Prompt\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
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
				targetCollection: $prompt->targetCollection,
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

	private function resolveAccess(PromptData $prompt): string
	{
		if ($prompt->access !== '') {
			return $prompt->access;
		}
		if ($prompt->targetCollection !== '') {
			// CollectionData::$mcp is array<string,mixed>; MCP access is stored at $mcp['access'].
			// If the collection doesn't exist or has no mcp.access set, fall through to 'admin'.
			// Chunk B can refine when wiring access enforcement and the mcp-collection schema
			// is confirmed to propagate access into CollectionData::$mcp['access'].
			try {
				$collection = $this->collections->fetchCollection($prompt->targetCollection);
				if ($collection !== null && isset($collection->mcp['access']) && is_string($collection->mcp['access']) && $collection->mcp['access'] !== '') {
					return $collection->mcp['access'];
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Failed to resolve target-collection access for prompt', [
					'prompt'           => $prompt->name,
					'targetCollection' => $prompt->targetCollection,
					'error'            => $e->getMessage(),
				]);
			}
		}

		return 'admin';
	}
}
