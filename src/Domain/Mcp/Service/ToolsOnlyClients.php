<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

/**
 * Decides whether a connecting MCP client should be served the tools-only
 * capability surface (no resources/prompts/subscriptions).
 *
 * ChatGPT's connector importer rejects servers that advertise `resources` or
 * `prompts` (confirmed live against the openai-mcp client). Full-featured
 * clients — Claude (`Anthropic/ClaudeAI`), Cursor, Claude Desktop, etc. — use
 * the rich surface, so we trim it ONLY for clients that need it, keyed off the
 * self-reported `clientInfo.name` from the initialize handshake.
 *
 * Direction is deliberately safe: the default is the full surface; a client is
 * trimmed only on a positive match. If OpenAI ever renames their client the
 * worst case is ChatGPT fails to connect (as it did before) — no other client
 * is ever affected. Matching is broad + case-insensitive to survive minor
 * naming changes (`openai-mcp`, `ChatGPT`, …).
 */
final readonly class ToolsOnlyClients
{
	/** Substrings (lower-cased) that identify a tools-only client. */
	private const NEEDLES = ['openai', 'chatgpt'];

	public static function matches(string $clientName): bool
	{
		if ($clientName === '') {
			return false;
		}

		$name = strtolower($clientName);
		foreach (self::NEEDLES as $needle) {
			if (str_contains($name, $needle)) {
				return true;
			}
		}

		return false;
	}
}
