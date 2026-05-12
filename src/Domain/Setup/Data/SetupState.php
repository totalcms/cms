<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Setup\Data;

/**
 * Durable wizard state — the "what's been completed" snapshot persisted
 * by SetupStateRepository under `tcms-data/.system/setup-state.json`.
 *
 * `completedAt` is null while the wizard is still in progress and is
 * stamped with an ISO timestamp once every step has been marked done.
 * SetupCheckMiddleware uses that stamp to decide whether the wizard
 * should still be reachable — it's the durable signal that survives the
 * session destroy() during login.
 */
final readonly class SetupState
{
	/**
	 * @param list<string> $completedSteps
	 */
	public function __construct(
		public array $completedSteps = [],
		public ?string $completedAt = null,
	) {
	}

	public static function empty(): self
	{
		return new self();
	}

	public function isComplete(): bool
	{
		return $this->completedAt !== null;
	}

	public function hasStep(string $step): bool
	{
		return in_array($step, $this->completedSteps, true);
	}

	/**
	 * Return a copy with the given step added. Idempotent — returns the
	 * same instance when the step is already present.
	 */
	public function withStep(string $step): self
	{
		if ($this->hasStep($step)) {
			return $this;
		}

		return new self([...$this->completedSteps, $step], $this->completedAt);
	}

	/**
	 * Return a copy with the completion timestamp stamped to `date('c')`.
	 * Idempotent — returns the same instance when already complete.
	 */
	public function markComplete(): self
	{
		if ($this->completedAt !== null) {
			return $this;
		}

		return new self($this->completedSteps, date('c'));
	}

	/**
	 * @return array{completed_steps: list<string>, completed_at: string|null}
	 */
	public function toArray(): array
	{
		return [
			'completed_steps' => $this->completedSteps,
			'completed_at'    => $this->completedAt,
		];
	}

	/**
	 * Build a SetupState from a decoded JSON payload. Tolerates malformed
	 * or partial input — missing keys default to empty/null.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		$rawSteps = $data['completed_steps'] ?? [];
		$steps    = is_array($rawSteps)
			? array_values(array_filter($rawSteps, is_string(...)))
			: [];

		$completedAt = $data['completed_at'] ?? null;
		if ($completedAt !== null && !is_string($completedAt)) {
			$completedAt = null;
		}

		return new self($steps, $completedAt);
	}
}
