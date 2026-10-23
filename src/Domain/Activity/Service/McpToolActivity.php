<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Activity\Service;

use TotalCMS\Domain\Activity\Data\ActivityEntry;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;

/**
 * Records MCP tool calls in the activity log: which tool, who called it
 * through which client, what it targeted, whether it worked and how long it
 * took. Called from the tool wrapper in McpServerFactory.
 *
 * Only arguments that name a target (collection, id, query…) are kept, and
 * only scalar ones — never the content an agent writes.
 */
final readonly class McpToolActivity
{
	public const EVENT = 'mcp.tool.called';

	/** Arguments that say what a call targeted. */
	private const TARGET_ARGS = [
		'collection', 'id', 'view', 'schema', 'template', 'path', 'uri',
		'name', 'kind', 'query', 'q', 'search', 'folder', 'limit', 'page',
	];

	private const MAX_ARG_LENGTH = 200;

	public function __construct(
		private ActivityRecorder $recorder,
		private ActorContext $actors,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments
	 */
	public function record(string $tool, array $arguments, bool $success, int $latencyMs, ?string $error = null): void
	{
		$actor = $this->actors->current();
		if ($actor->kind === ActorKind::Anonymous && !$this->recorder->recordsAnonymousMcp()) {
			return;
		}

		$target = [];
		foreach (self::TARGET_ARGS as $key) {
			$value = $arguments[$key] ?? null;
			if (is_scalar($value) && $value !== '') {
				$target[$key] = mb_substr((string)$value, 0, self::MAX_ARG_LENGTH);
			}
		}

		$this->recorder->record(self::EVENT, $actor->toArray(), [
			'level'      => $success ? ActivityEntry::LEVEL_INFO : ActivityEntry::LEVEL_WARNING,
			'collection' => $target['collection'] ?? '',
			'objectId'   => $target['id'] ?? '',
			'summary'    => $success ? $tool : "{$tool}: " . ($error ?? 'error'),
			'detail'     => array_filter([
				'tool'      => $tool,
				'arguments' => array_diff_key($target, ['collection' => true, 'id' => true]),
				'latencyMs' => $latencyMs,
				'error'     => $error,
			], static fn (mixed $value): bool => $value !== null && $value !== []),
		]);
	}
}
