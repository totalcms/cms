<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Data\DeckData;

/**
 * Service for fetching deck items.
 */
readonly class DeckItemFetcher
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
	) {
	}

	/**
	 * Get a specific deck item from an object property.
	 *
	 * @return array<string,mixed>|null
	 */
	public function fetchDeckItem(string $collection, string $objectId, string $propertyName, string $itemId): ?array
	{
		$object   = $this->objectFetcher->fetchObject($collection, $objectId);
		$property = $object->properties->get($propertyName);

		if (!$property instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$propertyName}' is not a deck property");
		}

		return $property->getItem($itemId);
	}

	/**
	 * Get all deck items from an object property.
	 *
	 * @return array<int|string,array<string,mixed>>
	 */
	public function fetchAllDeckItems(string $collection, string $objectId, string $propertyName): array
	{
		$object   = $this->objectFetcher->fetchObject($collection, $objectId);
		$property = $object->properties->get($propertyName);

		if (!$property instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$propertyName}' is not a deck property");
		}

		return $property->deck;
	}

	/**
	 * Get the IDs of all deck items in an object property.
	 *
	 * @return array<int|string>
	 */
	public function fetchDeckItemIds(string $collection, string $objectId, string $propertyName): array
	{
		$object   = $this->objectFetcher->fetchObject($collection, $objectId);
		$property = $object->properties->get($propertyName);

		if (!$property instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$propertyName}' is not a deck property");
		}

		return $property->getItemNames(); // Note: getItemNames() returns the keys, which are IDs
	}
}
