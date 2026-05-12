<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\PropertyData;

/**
 * Service for processing property data before save operations.
 *
 * This service handles business logic that was previously embedded
 * in property data classes, improving separation of concerns.
 */
class PropertyDataProcessor implements PropertyDataProcessorInterface
{
	/**
	 * Process property data before save operations.
	 */
	public function processBeforeSave(PropertyData $property): PropertyData
	{
		// Handle DateData specific processing
		if ($property instanceof DateData) {
			return $this->processDateData($property);
		}

		if ($property instanceof ImageData) {
			return $this->processImageData($property);
		}

		if ($property instanceof GalleryData) {
			return $this->processGalleryData($property);
		}

		return $property;
	}

	/**
	 * Compute a fresh content hash so ImageWorks URLs bust cached crops.
	 */
	private function processImageData(ImageData $imageData): ImageData
	{
		$payload         = $imageData->transform();
		$imageData->hash = ImageHashService::compute($payload);

		return $imageData;
	}

	private function processGalleryData(GalleryData $galleryData): GalleryData
	{
		foreach ($galleryData->images as $image) {
			$this->processImageData($image);
		}

		return $galleryData;
	}

	/**
	 * Process DateData before save operations.
	 */
	private function processDateData(DateData $dateData): DateData
	{
		if (isset($dateData->settings[DateData::CREATION_DATE]) && $dateData->settings[DateData::CREATION_DATE] === true) {
			if ($dateData->date === '' || $dateData->date === DateData::CREATION_DATE) {
				$dateData->date = DateData::cleanDate();
			}
		} elseif (isset($dateData->settings[DateData::UPDATE_DATE]) && $dateData->settings[DateData::UPDATE_DATE] === true) {
			$dateData->date = DateData::cleanDate();
		}

		return $dateData;
	}
}
