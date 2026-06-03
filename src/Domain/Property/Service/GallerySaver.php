<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Media\Service\ImageMetaReader;
use TotalCMS\Domain\Media\Service\ImagePaletteGenerator;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\Concerns\DerivesImageData;

class GallerySaver extends FileSaver
{
	use DerivesImageData;

	public string $type = 'gallery';

	public function rebuildFromStorage(string $collection, string $id, string $property, ?string $subpath = null): ?PropertyData
	{
		$files = $this->storage->listPropertyFiles($collection, $id, $property, $subpath);
		if ($files === []) {
			return null;
		}

		// Stable, predictable order (original manual ordering is unrecoverable).
		usort($files, static fn (array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));

		$images = array_map(
			fn (array $file): array => $this->deriveImageData($collection, $id, $property, $file, $subpath),
			$files,
		);

		return new GalleryData($images);
	}

	public function save(
		string $collection,
		string $objectID,
		string $property,
		string $filePath,
		?string $subpath = null,
	): ObjectData {
		$this->assertNotSvg($filePath);

		$objectExists = $this->objectFetcher->existsObject($collection, $objectID);
		if (!$objectExists) {
			$this->createObject($collection, $objectID, $property);
		}

		$gallery = $this->fetchProperty($collection, $objectID, $property);
		if (!$gallery instanceof GalleryData) {
			throw new \RuntimeException('Expected instance of GalleryData');
		}

		$fileData  = $this->storage->saveFile($collection, $objectID, $property, $filePath);

		$colorData = ['palette' => []];

		// Safely generate color palette - never let this break the upload
		if ($this->settings['extractPalette'] ?? true) {
			try {
				$colorData = ['palette' => ImagePaletteGenerator::getPalette($filePath)];
			} catch (\RuntimeException $e) {
				// Log palette generation failures
				$this->getLogger()->warning('Palette generation failed', [
					'collection' => $collection,
					'objectID'   => $objectID,
					'property'   => $property,
					'file'       => $filePath,
					'error'      => $e->getMessage(),
				]);
				// Continue with empty palette - upload should not fail
				$colorData = ['palette' => []];
			}
		}

		if ($this->settings['extractExif'] ?? true) {
			$metaData = ImageMetaReader::getMetaData($filePath);
			if (!($this->config->imageworks['gatherLocation'] ?? true)) {
				ImageMetaReader::stripLocationData($metaData);
			}
		} else {
			// Always extract basic image dimensions (width/height)
			$metaData = ImageMetaReader::getBasicImageData($filePath);
		}

		$newImage          = array_merge($fileData, $metaData, $colorData);
		$gallery->images[] = new ImageData($newImage);

		return $this->updateObject($collection, $objectID, $property, $gallery);
	}
}
