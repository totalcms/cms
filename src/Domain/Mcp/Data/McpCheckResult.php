<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Data;

/**
 * Outcome of a single MCP connection probe.
 *
 * `status` values:
 *  - pass         probe succeeded
 *  - warn         works, but a likely operator problem was detected
 *  - fail         a client-visible breakage was detected
 *  - skip         probe not applicable to this install's config
 *  - unreachable  we could not test (host can't reach its own public URL) —
 *                 explicitly NOT a failure; render as "couldn't test"
 */
final readonly class McpCheckResult
{
	public function __construct(
		public string $id,
		public string $label,
		public string $status,
		public string $detail = '',
		public string $fix = '',
	) {
	}

	/** @return array<string,string> */
	public function toArray(): array
	{
		return [
			'id'     => $this->id,
			'label'  => $this->label,
			'status' => $this->status,
			'detail' => $this->detail,
			'fix'    => $this->fix,
		];
	}
}
