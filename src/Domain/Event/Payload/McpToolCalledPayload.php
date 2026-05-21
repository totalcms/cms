<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for the `mcp.tool.called` event — fired after a tool dispatch on
 * the MCP server completes (success or error).
 *
 * Listeners can use this for:
 *   - Activity dashboards (per-tool call counts, slow-call detection)
 *   - Audit log enrichment (who called what, with what params)
 *   - Webhook fan-out (extensions reacting to specific tool calls)
 *
 * **Status (Phase 1):** the payload class ships; the actual event firing wires
 * up in Phase 1.5 once we add the PSR-14 bridge between the SDK's request/
 * response events and T3's `EventDispatcher`. The closure-wrapping approach
 * (handler decoration) was rejected because the SDK uses PHP reflection on
 * each handler's named parameters to map JSON-RPC args, and wrapping with a
 * generic `function(...$args)` breaks that mapping. The PSR-14 path doesn't
 * touch handler closures and is non-blocking — extensions registering for
 * `mcp.tool.called` today silently no-op until firing wires up.
 */
readonly class McpToolCalledPayload extends EventPayload
{
	/**
	 * @param string              $tool       Tool name as registered (e.g. `query_collection`, `acme_search_invoices`)
	 * @param string              $persona    `admin` or `public`
	 * @param array<string,mixed> $params     Args the agent passed (may be sensitive — see access docs)
	 * @param bool                $success    True for normal return, false for tool error (`isError: true`)
	 * @param int                 $latencyMs  Wall-clock duration of the handler call
	 * @param string|null         $error      Error message when success=false; null otherwise
	 */
	public function __construct(
		public string $tool,
		public string $persona,
		public array $params,
		public bool $success,
		public int $latencyMs,
		public ?string $error = null,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		$data = [
			'tool'       => $this->tool,
			'persona'    => $this->persona,
			'params'     => $this->params,
			'success'    => $this->success,
			'latency_ms' => $this->latencyMs,
		];
		if ($this->error !== null) {
			$data['error'] = $this->error;
		}

		return $data;
	}
}
