<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Data;

readonly class SecurityReport
{
	/** @param list<SecurityFinding> $findings */
	public function __construct(
		public array $findings,
		public string $checkedAt,
	) {
	}

	public function criticalCount(): int
	{
		return count(array_filter($this->findings, static fn (SecurityFinding $f): bool => $f->isCountable()));
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'checkedAt' => $this->checkedAt,
			'findings'  => array_map(static fn (SecurityFinding $f): array => $f->toArray(), $this->findings),
		];
	}

	/** @param array<string,mixed> $data */
	public static function fromArray(array $data): self
	{
		$rows = is_array($data['findings'] ?? null) ? $data['findings'] : [];

		return new self(
			array_values(array_map(
				static fn (mixed $row): SecurityFinding => SecurityFinding::fromArray(is_array($row) ? $row : []),
				$rows,
			)),
			(string)($data['checkedAt'] ?? ''),
		);
	}
}
