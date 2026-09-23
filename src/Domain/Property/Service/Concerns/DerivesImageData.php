<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service\Concerns;

use TotalCMS\Domain\Media\Service\ImageMetaReader;
use TotalCMS\Domain\Media\Service\ImagePaletteGenerator;

/**
 * Palette + EXIF (or bare dimensions) for an image file, per the saver's
 * `extractPalette` / `extractExif` settings. Shared by the upload path
 * (ImageSaver / GallerySaver::save()) and the recovery rebuild path
 * (deriveImageData()). Expects the host class to extend FileSaver, which
 * provides $storage, $config, $settings, getLogger() and describeStoredFile().
 */
trait DerivesImageData
{
	/**
	 * Full image data for a file already in storage — what save() would have
	 * stored, minus the upload move.
	 *
	 * @param array{name:string,path:string} $file
	 *
	 * @return array<string,mixed>
	 */
	protected function deriveImageData(string $collection, string $id, string $property, array $file, ?string $subpath): array
	{
		$absPath  = $this->config->datadir . '/' . $file['path'];
		$fileData = $this->describeStoredFile($collection, $id, $property, (string)$file['name'], $subpath);

		return array_merge($fileData, $this->extractImageMetadata($absPath, [
			'collection' => $collection,
			'objectID'   => $id,
			'property'   => $property,
		]));
	}

	/**
	 * A palette failure is logged and leaves the palette empty: it must never
	 * fail an upload or a rebuild.
	 *
	 * @param array<string,string> $context Logged alongside a palette failure
	 *
	 * @return array<string,mixed> EXIF / dimension fields plus `palette`
	 */
	protected function extractImageMetadata(string $absPath, array $context): array
	{
		$palette = [];
		if ($this->settings['extractPalette'] ?? true) {
			try {
				$palette = ImagePaletteGenerator::getPalette($absPath);
			} catch (\RuntimeException $e) {
				$this->getLogger()->warning('Palette generation failed', $context + ['file' => $absPath, 'error' => $e->getMessage()]);
			}
		}

		if ($this->settings['extractExif'] ?? true) {
			$metaData = ImageMetaReader::getMetaData($absPath);
			if (!($this->config->imageworks['gatherLocation'] ?? true)) {
				ImageMetaReader::stripLocationData($metaData);
			}
		} else {
			// Always extract basic image dimensions (width/height)
			$metaData = ImageMetaReader::getBasicImageData($absPath);
		}

		return array_merge($metaData, ['palette' => $palette]);
	}
}
