<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DeckData;

/**
 * Service for managing deck property operations.
 */
final class DeckManager
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
	) {
	}

	/**
	 * Get a specific deck item from an object property.
	 * 
	 * @param string $collection
	 * @param string $objectId
	 * @param string $propertyName
	 * @param string $itemName
	 * @return array<string,mixed>|null
	 */
	public function getDeckItem(string $collection, string $objectId, string $propertyName, string $itemName): ?array
	{
		$object = $this->objectFetcher->fetchObject($collection, $objectId);
		$property = $object->properties->get($propertyName);

		if (!$property instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$propertyName}' is not a deck property");
		}

		return $property->getItem($itemName);
	}

	/**
	 * Create a new deck item in an object property.
	 * 
	 * @param string $collection
	 * @param string $objectId
	 * @param string $propertyName
	 * @param string $itemName
	 * @param array<string,mixed> $itemData
	 * @return ObjectData
	 */
	public function createDeckItem(
		string $collection,
		string $objectId,
		string $propertyName,
		string $itemName,
		array $itemData
	): ObjectData {
		$object = $this->objectFetcher->fetchObject($collection, $objectId);
		$property = $object->properties->get($propertyName);

		if (!$property instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$propertyName}' is not a deck property");
		}

		if ($property->hasItem($itemName)) {
			throw new \InvalidArgumentException("Deck item '{$itemName}' already exists");
		}

		// Create new deck data with the added item
		$newDeckData = $property->deck;
		$newDeckData[$itemName] = $itemData;

		// Update the object with the new deck data (just pass the raw array)
		$objectData = $object->toArray();
		$objectData[$propertyName] = $newDeckData;

		return $this->objectUpdater->updateObject($collection, $objectId, $objectData);
	}

	/**
	 * Update an existing deck item in an object property.
	 * 
	 * @param string $collection
	 * @param string $objectId
	 * @param string $propertyName
	 * @param string $itemName
	 * @param array<string,mixed> $itemData
	 * @return ObjectData
	 */
	public function updateDeckItem(
		string $collection,
		string $objectId,
		string $propertyName,
		string $itemName,
		array $itemData
	): ObjectData {
		$object = $this->objectFetcher->fetchObject($collection, $objectId);
		$property = $object->properties->get($propertyName);

		if (!$property instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$propertyName}' is not a deck property");
		}

		if (!$property->hasItem($itemName)) {
			throw new \InvalidArgumentException("Deck item '{$itemName}' does not exist");
		}

		// Create new deck data with the updated item
		$newDeckData = $property->deck;
		$newDeckData[$itemName] = $itemData;

		// Update the object with the new deck data (just pass the raw array)
		$objectData = $object->toArray();
		$objectData[$propertyName] = $newDeckData;

		return $this->objectUpdater->updateObject($collection, $objectId, $objectData);
	}

	/**
	 * Delete a deck item from an object property.
	 * 
	 * @param string $collection
	 * @param string $objectId
	 * @param string $propertyName
	 * @param string $itemName
	 * @return ObjectData
	 */
	public function deleteDeckItem(
		string $collection,
		string $objectId,
		string $propertyName,
		string $itemName
	): ObjectData {
		$object = $this->objectFetcher->fetchObject($collection, $objectId);
		$property = $object->properties->get($propertyName);

		if (!$property instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$propertyName}' is not a deck property");
		}

		if (!$property->hasItem($itemName)) {
			throw new \InvalidArgumentException("Deck item '{$itemName}' does not exist");
		}

		// Create new deck data with the item removed
		$newDeckData = $property->deck;
		unset($newDeckData[$itemName]);

		// Update the object with the new deck data (just pass the raw array)
		$objectData = $object->toArray();
		$objectData[$propertyName] = $newDeckData;

		return $this->objectUpdater->updateObject($collection, $objectId, $objectData);
	}

	/**
	 * Get all deck items from an object property.
	 * 
	 * @param string $collection
	 * @param string $objectId
	 * @param string $propertyName
	 * @return array<string,array<string,mixed>>
	 */
	public function getAllDeckItems(string $collection, string $objectId, string $propertyName): array
	{
		$object = $this->objectFetcher->fetchObject($collection, $objectId);
		$property = $object->properties->get($propertyName);

		if (!$property instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$propertyName}' is not a deck property");
		}

		return $property->deck;
	}

	/**
	 * Get the names of all deck items in an object property.
	 * 
	 * @param string $collection
	 * @param string $objectId
	 * @param string $propertyName
	 * @return array<string>
	 */
	public function getDeckItemNames(string $collection, string $objectId, string $propertyName): array
	{
		$object = $this->objectFetcher->fetchObject($collection, $objectId);
		$property = $object->properties->get($propertyName);

		if (!$property instanceof DeckData) {
			throw new \InvalidArgumentException("Property '{$propertyName}' is not a deck property");
		}

		return $property->getItemNames();
	}
}