<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Repair\Data;

/**
 * One property that was (or would be) repaired: a blank file-type property whose
 * files still exist on disk. `applied` is null in dry-run, true/false after an
 * apply; `error` is set when the rebuild or write failed. `subpath` is set for a
 * field nested inside a card/deck (e.g. `image` for a card child, `one/image`
 * for a deck-item child) and null for a top-level property.
 */
final class RepairCandidate
{
	public ?bool $applied   = null;
	public ?string $error   = null;
	public ?string $subpath = null;

	public function __construct(
		public readonly string $objectId,
		public readonly string $property,
		public readonly string $type,
		public readonly int $fileCount,
	) {
	}

	/**
	 * Dotted display path: `property` for a top-level field, `property.child` for
	 * a card child, `property.item.child` for a deck-item child.
	 */
	public function path(): string
	{
		return $this->subpath === null
			? $this->property
			: $this->property . '.' . str_replace('/', '.', $this->subpath);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'objectId'  => $this->objectId,
			'property'  => $this->property,
			'subpath'   => $this->subpath,
			'path'      => $this->path(),
			'type'      => $this->type,
			'fileCount' => $this->fileCount,
			'applied'   => $this->applied,
			'error'     => $this->error,
		];
	}
}
