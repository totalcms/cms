<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Data;

/**
 * A single automation run, persisted to
 * `.system/automations/<id>/runs/<runId>.json`.
 */
final readonly class RunRecord
{
	/**
	 * @param array<string,mixed> $trigger
	 * @param mixed $return
	 */
	public function __construct(
		public string $runId,
		public string $automation,
		public array $trigger,
		public string $status,        // 'success' | 'failed'
		public string $startedAt,
		public string $finishedAt,
		public int $durationMs,
		public mixed $return,
		public ?string $exception,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'runId'      => $this->runId,
			'automation' => $this->automation,
			'trigger'    => $this->trigger,
			'status'     => $this->status,
			'startedAt'  => $this->startedAt,
			'finishedAt' => $this->finishedAt,
			'durationMs' => $this->durationMs,
			'return'     => $this->return,
			'exception'  => $this->exception,
		];
	}
}
