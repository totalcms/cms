<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;

/**
 * Service for removing deck items.
 */
final readonly class DeckItemRemover
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
	) {
	}

	/**
	 * Delete a deck item from an object property.
	 */
	public function removeDeckItem(
		string $collection,
		string $objectId,
		string $propertyName,
		string $itemId,
	): ObjectData {
		$object   = $this->objectFetcher->fetchObject($collection, $objectId);
		$property = $object->properties->get($propertyName);

		if (!$property instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$propertyName}' is not a deck property");
		}

		if (!$property->hasItem($itemId)) {
			throw new \InvalidArgumentException("Deck item '{$itemId}' does not exist");
		}

		// Create new deck data with the item removed
		$newDeckData = $property->deck;
		unset($newDeckData[$itemId]);

		// Update the object with the new deck data (just pass the raw array)
		$objectData                = $object->toArray();
		$objectData[$propertyName] = $newDeckData;

		return $this->objectUpdater->updateObject($collection, $objectId, $objectData);
	}
}
