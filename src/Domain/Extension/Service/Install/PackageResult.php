<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Install;

final readonly class PackageResult
{
	/** @param list<string> $warnings */
	public function __construct(
		public string $zipPath,
		public int $fileCount,
		public array $warnings,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'zipPath'   => $this->zipPath,
			'fileCount' => $this->fileCount,
			'warnings'  => $this->warnings,
		];
	}
}
