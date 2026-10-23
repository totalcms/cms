<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Install;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;

/**
 * What inspecting an extension archive found, before anything is written.
 *
 * `prefix` is the single wrapper folder (`thing/`) every entry sits under,
 * or `''` when extension.json is at the zip root. `existing` is the manifest
 * discovery currently resolves the id to, set by the installer; null means a
 * fresh install.
 */
final readonly class InstallPlan
{
	/** @param list<string> $warnings */
	public function __construct(
		public ExtensionManifest $manifest,
		public string $prefix,
		public int $fileCount,
		public int $uncompressedBytes,
		public array $warnings = [],
		public ?ExtensionManifest $existing = null,
	) {
	}

	public function isUpdate(): bool
	{
		return $this->existing instanceof ExtensionManifest;
	}

	public function withExisting(?ExtensionManifest $existing): self
	{
		return new self($this->manifest, $this->prefix, $this->fileCount, $this->uncompressedBytes, $this->warnings, $existing);
	}
}
