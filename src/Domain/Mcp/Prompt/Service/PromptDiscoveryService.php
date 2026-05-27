<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Prompt\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptData;
use TotalCMS\Domain\Mcp\Prompt\Exception\PromptCollisionException;

final readonly class PromptDiscoveryService
{
	public const COLLECTION_ID = 'mcp-prompt';

	public function __construct(
		private IndexFilter $indexFilter,
		private CollectionRepository $collections,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<PromptData>
	 */
	public function discover(): array
	{
		$raw = $this->indexFilter->fetchFilteredIndex(self::COLLECTION_ID);
		$seen    = [];
		$prompts = [];

		foreach ($raw as $row) {
			if (!isset($row['name']) || !is_string($row['name'])) {
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
				name:             $prompt->name,
				description:      $prompt->description,
				body:             $prompt->body,
				args:             $prompt->args,
				targetCollection: $prompt->targetCollection,
				access:           $resolvedAccess,
			);
		}

		return $prompts;
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
