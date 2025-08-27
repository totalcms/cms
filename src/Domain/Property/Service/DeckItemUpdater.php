<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;

/**
 * Service for updating existing deck items.
 */
readonly class DeckItemUpdater
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
		private PropertyFactory $propertyFactory,
	) {
	}

	/**
	 * Update an existing deck item in an object property.
	 *
	 * @param array<string,mixed> $itemData
	 */
	public function updateDeckItem(
		string $collection,
		string $objectId,
		string $propertyName,
		string $itemId,
		array $itemData,
	): ObjectData {
		$object   = $this->objectFetcher->fetchObject($collection, $objectId);
		$property = $object->properties->get($propertyName);

		if (!$property instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$propertyName}' is not a deck property");
		}

		if (!$property->hasItem($itemId)) {
			throw new \InvalidArgumentException("Deck item '{$itemId}' does not exist");
		}

		// Ensure the ID is stored inside the item data
		$itemData['id'] = $itemId;

		// Process the individual deck item data
		$processedItemData = $this->propertyFactory->processIndividualDeckItem($collection, $propertyName, $itemData);

		// Create new deck data with the updated item
		$newDeckData          = $property->deck;
		$newDeckData[$itemId] = $processedItemData;

		// Update the object with the new deck data (just pass the raw array)
		$objectData                = $object->toArray();
		$objectData[$propertyName] = $newDeckData;

		return $this->objectUpdater->updateObject($collection, $objectId, $objectData);
	}
}
