<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Template\Service;

use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

readonly class TemplateMigrationService
{
	public function __construct(
		private StorageAdapterInterface $filesystem,
	) {
	}

	/**
	 * Migrate files from the legacy templates/ directory to builder/.
	 * Whitelabel files move to builder/whitelabel/, everything else to builder/templates/.
	 *
	 * @return int Number of files migrated
	 */
	public function migrateFromLegacyTemplates(): int
	{
		$legacyDir = 'templates/';
		$migrated  = 0;

		try {
			$contents = $this->filesystem->flysystem()->listContents($legacyDir, true);
		} catch (\Throwable) {
			return 0;
		}

		foreach ($contents as $item) {
			if (!$item->isFile()) {
				continue;
			}

			$sourcePath   = $item->path();
			$relativePath = substr($sourcePath, strlen($legacyDir));

			// Whitelabel files stay in whitelabel/, everything else goes to templates/
			$targetPath = str_starts_with($relativePath, 'whitelabel/')
				? TemplateRepository::BUILDER_DIR . $relativePath
				: TemplateRepository::BUILDER_DIR . 'templates/' . $relativePath;

			$this->filesystem->move($sourcePath, $targetPath);
			$migrated++;
		}

		return $migrated;
	}

	/**
	 * Import template files from an external directory into a builder category.
	 * Reads from the local filesystem (package resources), writes via the storage adapter.
	 *
	 * @return int Number of files imported
	 */
	public function importDirectory(string $sourceDir, string $category, bool $overwrite = false): int
	{
		if (!is_dir($sourceDir)) {
			return 0;
		}

		return $this->importRecursive($sourceDir, $category, $overwrite);
	}

	private function importRecursive(string $sourceDir, string $targetFolder, bool $overwrite, string $subfolder = ''): int
	{
		$imported = 0;
		$entries  = scandir($sourceDir);
		if ($entries === false) {
			return 0;
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$sourcePath = $sourceDir . '/' . $entry;

			if (is_dir($sourcePath)) {
				$nestedFolder = $subfolder === '' ? $entry : $subfolder . '/' . $entry;
				$imported += $this->importRecursive($sourcePath, $targetFolder, $overwrite, $nestedFolder);
				continue;
			}

			$contents = file_get_contents($sourcePath);
			if ($contents === false) {
				continue;
			}

			$relativePath = $subfolder === '' ? $entry : $subfolder . '/' . $entry;
			$storagePath  = TemplateRepository::BUILDER_DIR . $targetFolder . '/' . $relativePath;

			if (!$overwrite && $this->filesystem->fileExists($storagePath)) {
				continue;
			}

			$this->filesystem->write($storagePath, $contents);
			$imported++;
		}

		return $imported;
	}
}
