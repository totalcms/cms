<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Repair\Data;

/**
 * One property that was (or would be) repaired: a blank file-type property whose
 * files still exist on disk. `applied` is null in dry-run, true/false after an
 * apply; `error` is set when the rebuild or write failed.
 */
final class RepairCandidate
{
	public ?bool $applied = null;
	public ?string $error = null;

	public function __construct(
		public readonly string $objectId,
		public readonly string $property,
		public readonly string $type,
		public readonly int $fileCount,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'objectId'  => $this->objectId,
			'property'  => $this->property,
			'type'      => $this->type,
			'fileCount' => $this->fileCount,
			'applied'   => $this->applied,
			'error'     => $this->error,
		];
	}
}
