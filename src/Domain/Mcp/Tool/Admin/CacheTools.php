<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * Admin tool: `clear_cache` — flush every available cache backend.
 *
 * Wraps `CacheManager::clearAllCaches()` which returns a per-backend status
 * map. We surface that map plus an aggregate `all_cleared` boolean so the AI
 * can tell at a glance whether anything failed without having to inspect each
 * backend entry.
 *
 * Marked destructive — clearing isn't deleting customer content, but it IS a
 * side-effect that affects subsequent read latency. Destructive hint lets MCP
 * hosts surface this with confirmation if their UX warrants it.
 *
 * Carries a `ToolRequirement` (Phase 4) alongside `access: 'admin'`: the
 * requirement doesn't loosen the base persona gate (an ADMIN-only tool stays
 * hidden from AUTHENTICATED unless requires is satisfied — access:
 * 'authenticated' would short-circuit tools/list visibility BEFORE requires
 * is ever consulted, see McpToolDefinition::isVisibleTo()), it EXTENDS it: an
 * AUTHENTICATED caller whose access-group grants the `cache` util also sees
 * and can call this tool — mirrors the REST `/api/cache` group gate
 * (`AccessGroupData::allowsUtil('cache')`).
 */
readonly class CacheTools
{
	public function __construct(
		private CacheManager $cacheManager,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'clear_cache',
			description: 'Flush every available cache backend (APCu, Redis, Memcached, filesystem, OPcache). Returns a per-backend status map plus an `all_cleared` boolean. Use after schema changes, template edits, or other operations that may leave stale data cached.',
			access: 'admin',
			handler: $this->handler(...),
			inputSchema: [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			annotations: new ToolAnnotations(
				title: 'Clear All Caches',
				readOnlyHint: false,
				destructiveHint: true,
				idempotentHint: false,
				openWorldHint: false,
			),
			// 'cache' is not a target-bearing domain — no collectionArg. The
			// operation value doesn't affect the check itself (ToolRequirement
			// maps every 'cache' operation to canUtil('cache')); 'update' is
			// the closest semantic fit for a mutating flush.
			requires: new ToolRequirement(domain: 'cache', operation: 'update'),
		));
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(): array
	{
		$backends = $this->cacheManager->clearAllCaches();

		// Aggregate: every AVAILABLE backend must have succeeded. "Not available"
		// backends (e.g. Redis on a host without Redis) don't count as failure
		// — they were never going to clear anything.
		$allCleared = true;
		foreach ($backends as $status) {
			if (!is_array($status)) {
				continue;
			}
			$cleared = $status['cleared'] ?? false;
			$reason  = (string)($status['reason'] ?? '');
			if (!$cleared && $reason !== 'not available') {
				$allCleared = false;
				break;
			}
		}

		return [
			'backends'    => $backends,
			'all_cleared' => $allCleared,
		];
	}
}
