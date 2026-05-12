<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\PropertyData;

/**
 * Interface for processing property data before save operations.
 */
interface PropertyDataProcessorInterface
{
	/**
	 * Process property data before save operations.
	 *
	 * @param PropertyData $property The property data to process
	 *
	 * @return PropertyData The processed property data
	 */
	public function processBeforeSave(PropertyData $property): PropertyData;
}
