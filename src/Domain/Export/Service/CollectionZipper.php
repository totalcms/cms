<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Export\Service;

use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

/**
 * Service for creating zip files of collection data including all files and folders.
 */
readonly class CollectionZipper
{
	public function __construct(
		private Config $config,
	) {
	}

	/**
	 * Create a zip file of a collection's data folder.
	 *
	 * @throws \RuntimeException If zip creation fails
	 *
	 * @return string The path to the created zip file
	 */
	public function createCollectionZip(string $collection): string
	{
		$collectionPath = PathUtils::absolutePath($this->config->datadir, $collection);

		if (!is_dir($collectionPath)) {
			throw new \RuntimeException(sprintf('Collection directory not found: %s', $collectionPath));
		}

		$zip = ZipBuilder::temp('collection-' . $collection);
		$zip->addTree($collectionPath, $collection);

		return $zip->close();
	}

	/**
	 * Get the filename for the zip download.
	 */
	public function getZipFilename(string $collection): string
	{
		return sprintf('collection-%s.zip', $collection);
	}
}
