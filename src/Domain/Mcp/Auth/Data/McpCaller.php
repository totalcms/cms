<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Data;

/**
 * The resolved caller of one MCP request: its persona, how it arrived, and —
 * for a session caller — the `collection:id` of the user the session names.
 */
final readonly class McpCaller
{
	public function __construct(
		public McpPersona $persona,
		public McpCallerKind $kind,
		public string $userRef = '',
	) {
	}
}
