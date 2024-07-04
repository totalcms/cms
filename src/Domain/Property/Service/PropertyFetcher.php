<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * Service.
 */
final class PropertyFetcher
{
	private ObjectFetcher $objectFetcher;

	public function __construct(ObjectFetcher $objectFetcher)
	{
		$this->objectFetcher = $objectFetcher;
	}

	/**
	 * fetch a property from an object.
	 *
	 * @param string $collection
	 * @param string $objectID
	 * @param string $property
	 *
	 * @return mixed
	 */
	public function fetchProperty(string $collection, string $objectID, string $property)
	{
		$object = $this->objectFetcher->fetchObject($collection, $objectID);

		return $object->properties->get($property);
	}
}
