<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service\Concerns;

use TotalCMS\Domain\Media\Service\ImageMetaReader;
use TotalCMS\Domain\Media\Service\ImagePaletteGenerator;

/**
 * Derives full image metadata (file basics + palette + EXIF/dimensions) from a
 * file already in storage — mirroring ImageSaver::save() minus the upload move.
 * Used by ImageSaver and GallerySaver for the recovery rebuild path. Expects the
 * host class to extend FileSaver (provides $storage, $config, $settings, and
 * describeStoredFile()).
 */
trait DerivesImageData
{
	/**
	 * @param array{name:string,path:string} $file
	 *
	 * @return array<string,mixed>
	 */
	protected function deriveImageData(string $collection, string $id, string $property, array $file, ?string $subpath): array
	{
		$absPath  = $this->config->datadir . '/' . $file['path'];
		$fileData = $this->describeStoredFile($collection, $id, $property, (string)$file['name'], $subpath);

		$colorData = ['palette' => []];
		if ($this->settings['extractPalette'] ?? true) {
			try {
				$colorData = ['palette' => ImagePaletteGenerator::getPalette($absPath)];
			} catch (\RuntimeException) {
				$colorData = ['palette' => []];
			}
		}

		if ($this->settings['extractExif'] ?? true) {
			$metaData = ImageMetaReader::getMetaData($absPath);
			if (!($this->config->imageworks['gatherLocation'] ?? true)) {
				ImageMetaReader::stripLocationData($metaData);
			}
		} else {
			$metaData = ImageMetaReader::getBasicImageData($absPath);
		}

		return array_merge($fileData, $metaData, $colorData);
	}
}
