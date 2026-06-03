<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Repair\Data;

/**
 * Result of a repair scan/apply over a collection.
 */
final class RepairReport
{
	public int $scanned = 0;

	/** Blank file-type properties that had no files on disk (nothing to do). */
	public int $blankWithoutFiles = 0;

	/** @var list<RepairCandidate> */
	public array $candidates = [];

	public function __construct(
		public readonly string $collection,
		public readonly bool $applied,
	) {
	}

	public function addCandidate(RepairCandidate $candidate): void
	{
		$this->candidates[] = $candidate;
	}

	public function repairableCount(): int
	{
		return count($this->candidates);
	}

	public function appliedOkCount(): int
	{
		return count(array_filter($this->candidates, static fn (RepairCandidate $c): bool => $c->applied === true));
	}

	public function failedCount(): int
	{
		return count(array_filter($this->candidates, static fn (RepairCandidate $c): bool => $c->error !== null));
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'collection'        => $this->collection,
			'applied'           => $this->applied,
			'scanned'           => $this->scanned,
			'repairable'        => $this->repairableCount(),
			'appliedOk'         => $this->appliedOkCount(),
			'failed'            => $this->failedCount(),
			'blankWithoutFiles' => $this->blankWithoutFiles,
			'candidates'        => array_map(static fn (RepairCandidate $c): array => $c->toArray(), $this->candidates),
		];
	}
}
