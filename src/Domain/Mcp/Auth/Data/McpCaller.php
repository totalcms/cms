<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Data;

use TotalCMS\Domain\ApiKey\Data\ApiKeyData;

/**
 * The resolved caller of one MCP request: its persona, how it arrived, and —
 * for a session caller — the `collection:id` of the user the session names,
 * or for an API key caller the key's id and, for a key limited to some
 * collections or views, the key itself (its paths are its grants).
 */
final readonly class McpCaller
{
	public function __construct(
		public McpPersona $persona,
		public McpCallerKind $kind,
		public string $userRef = '',
		public string $apiKeyId = '',
		public ?ApiKeyData $limitedKey = null,
	) {
	}
}
