<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;

/**
 * Service for saving new deck items.
 */
final class DeckItemSaver
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
		private PropertyFactory $propertyFactory,
	) {
	}

	/**
	 * Create a new deck item in an object property.
	 *
	 * @param string $collection
	 * @param string $objectId
	 * @param string $propertyName
	 * @param string $itemId
	 * @param array<string,mixed> $itemData
	 *
	 * @return ObjectData
	 */
	public function saveDeckItem(
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

		if ($property->hasItem($itemId)) {
			throw new \InvalidArgumentException("Deck item '{$itemId}' already exists");
		}

		// Ensure the ID is stored inside the item data
		$itemData['id'] = $itemId;

		// Process the individual deck item data
		$processedItemData = $this->propertyFactory->processIndividualDeckItem($collection, $propertyName, $itemData);

		// Create new deck data with the added item
		$newDeckData          = $property->deck;
		$newDeckData[$itemId] = $processedItemData;

		// Update the object with the new deck data (just pass the raw array)
		$objectData                = $object->toArray();
		$objectData[$propertyName] = $newDeckData;

		return $this->objectUpdater->updateObject($collection, $objectId, $objectData);
	}
}
