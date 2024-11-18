<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\PropertyData;

final class PropertyFetcher
{
	public function __construct(
		private ObjectFetcher $objectFetcher
	){
	}

	public function fetchProperty(string $collection, string $objectID, string $property) : PropertyData
	{
		$object = $this->objectFetcher->fetchObject($collection, $objectID);

		$propertyData = $object->properties->get($property);

		if (!$propertyData instanceof PropertyData) {
			throw new \UnexpectedValueException("Unable to locate object property $property");
		}

		return $propertyData;
	}
}
