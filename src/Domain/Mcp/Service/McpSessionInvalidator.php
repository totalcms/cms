<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Support\Config;

/**
 * Prunes MCP client session files when settings or schemas change in a way
 * that affects the published tool surface.
 *
 * **Why this exists.** MCP clients cache the `tools/list` response received
 * during `initialize`. If the operator flips `mcp.publicAccess`, toggles a
 * collection's `mcp.access`, or changes per-property `mcp.expose`, existing
 * sessions still hold the old surface and will get "Tool not found" on the
 * next `tools/call`. Wiping their session files forces them to re-initialize;
 * well-behaved MCP clients auto-reconnect on session-not-found, so the agent
 * recovers silently within one round-trip.
 *
 * **Deferred upgrade (3.6+):** `notifications/tools/list_changed` — the SDK
 * can push a broadcast that lets clients refresh in-place without the
 * reconnect blip. We use the reconnect path for now because it's
 * universally supported (every conformant MCP client handles
 * session-not-found) whereas the notification path requires client opt-in.
 *
 * Operator-facing note: the schema editor + MCP settings page warn that
 * tool-surface-changing settings cause a brief reconnect for any active
 * agent. Documented at the `toolPrefix` setting and replicated to other
 * tool-surface-changing settings as they land.
 */
readonly class McpSessionInvalidator
{
	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * Delete every session file under `tmpdir/mcp-sessions/`. Returns the
	 * number of session files removed (zero if the directory doesn't exist
	 * or is empty — both are valid no-op states).
	 *
	 * Non-recursive on purpose: a misconfigured tmpdir could otherwise blow
	 * away unrelated tmp content. Top-level dotfiles (`.DS_Store`, `.gitkeep`)
	 * are also skipped — they aren't sessions.
	 */
	public function invalidateAll(): int
	{
		$dir = $this->config->tmpdir . '/mcp-sessions';
		if (!is_dir($dir)) {
			return 0;
		}

		$removed = 0;
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if (!is_file($path)) {
				continue;
			}
			if (@unlink($path)) {
				$removed++;
			}
		}

		return $removed;
	}
}
