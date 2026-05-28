<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Prompt\Handler;

use TotalCMS\Domain\Mcp\Prompt\Service\PromptDiscoveryService;

/**
 * Invalidates the PromptDiscoveryService in-memory cache whenever an object
 * is created, updated, or deleted in the mcp-prompt collection.
 *
 * The next call to PromptDiscoveryService::discover() will re-read from disk,
 * so prompt changes go live immediately without a process restart.
 *
 * Wired to object.created, object.updated, and object.deleted in container.php.
 * The same method handles all three events — the cache should be cleared
 * regardless of whether a prompt was added, modified, or removed.
 */
final readonly class PromptChangeListener
{
	public function __construct(
		private PromptDiscoveryService $discovery,
	) {
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function onObjectChanged(array $payload): void
	{
		$collection = (string)($payload['collection'] ?? '');
		if ($collection === PromptDiscoveryService::COLLECTION_ID) {
			$this->discovery->clearCache();
		}
	}
}
