<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

/**
 * Event listener that invalidates MCP client sessions when domain events
 * indicate the published tool surface may have changed.
 *
 * **Listens on:**
 *   - `schema.saved`        — per-property `mcp.expose` / `mcp.description`
 *     edits change the catalog inside content-tool descriptions.
 *   - `collection.created`  — new collection may be MCP-visible.
 *   - `collection.updated`  — `mcp.access` toggle changes persona visibility.
 *   - `collection.deleted`  — removed collection drops from `list_collections`.
 *
 * Settings changes are handled directly in `SettingsSaver` because there's no
 * settings-save event yet. If/when one lands, fold settings invalidation into
 * this listener too.
 *
 * Single method on purpose: we don't differentiate by event type — every
 * subscribed event has the same response, "drop the sessions, let clients
 * re-initialize on next request." Adding granularity would just be
 * over-engineering for one cheap operation.
 */
readonly class McpSessionListener
{
	public function __construct(
		private McpSessionInvalidator $invalidator,
	) {
	}

	/**
	 * @param array<string,mixed> $payload Event payload (unused — we don't
	 *                                     differentiate by event type).
	 */
	public function onToolSurfaceChange(array $payload): void
	{
		$this->invalidator->invalidateAll();
	}
}
