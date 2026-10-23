<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Install;

/**
 * What an install did. `disabled` is true only when an UPDATE of an enabled
 * extension was switched off because the new code failed the source scan;
 * a fresh install is never enabled to begin with.
 */
final readonly class InstallResult
{
	/** @param list<string> $warnings */
	public function __construct(
		public string $id,
		public string $name,
		public string $version,
		public ?string $previousVersion,
		public bool $isUpdate,
		public int $findings,
		public bool $disabled,
		public array $warnings,
		public string $path,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'status'          => $this->isUpdate ? 'updated' : 'installed',
			'id'              => $this->id,
			'name'            => $this->name,
			'version'         => $this->version,
			'previousVersion' => $this->previousVersion,
			'findings'        => $this->findings,
			'disabled'        => $this->disabled,
			'warnings'        => $this->warnings,
			'path'            => $this->path,
		];
	}
}
