<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Storage\StorageRepository;

/**
 * Service.
 */
final class PropertyFactory
{
	public function __construct(
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/**
	 * create a property object.
	 *
	 * @param array<string,mixed>  $propertySchema
	 * @param mixed  $value
	 *
	 * @throws \DomainException
	 * @throws \UnexpectedValueException
	 *
	 * @return PropertyData
	 */
	public function generateProperty(array $propertySchema, mixed $value): PropertyData
	{
		$type = $propertySchema['type'] ?? basename($propertySchema['$ref'] ?? '', StorageRepository::FILE_EXT);

		// Special handling for deck properties
		if ($type === 'deck') {
			$settings = $propertySchema['settings'] ?? [];

			return $this->createDeck($propertySchema, $value, $settings);
		}

		$className = 'TotalCMS\\Domain\\Property\\Data\\' . ucfirst($type) . 'Data';
		if (!class_exists($className)) {
			throw new \UnexpectedValueException('Unknown property type for object.');
		}

		if (isset($propertySchema['default'])) {
			$value = $className::defaultValue($value, $propertySchema['default']);
		}

		$settings = $propertySchema['settings'] ?? [];

		$property = null === $value ? new $className(settings: $settings) : new $className($value, $settings);

		if (!$property instanceof PropertyData) {
			throw new \DomainException('Error creating property for object.');
		}

		return $property;
	}

	/**
	 * Create a DeckData object with properly processed items.
	 *
	 * @param array<string,mixed> $propertySchema The deck property schema
	 * @param mixed $value The raw deck data
	 * @param array<string,mixed> $settings The deck settings
	 *
	 * @return DeckData
	 */
	public function createDeck(array $propertySchema, mixed $value, array $settings = []): DeckData
	{
		// If no deck data provided, return empty deck
		if (empty($value) || !is_array($value)) {
			return new DeckData([], $settings);
		}

		// Get deckref from property schema (can be in settings or directly in property)
		$deckref = $propertySchema['deckref'] ?? $propertySchema['settings']['deckref'] ?? $settings['deckref'] ?? null;

		// If no deckref, return deck as-is (no processing)
		if (empty($deckref)) {
			return new DeckData($value, $settings);
		}

		try {
			// Get the deck item schema
			$schemaId   = $this->extractSchemaId($deckref);
			$deckSchema = $this->schemaFetcher->fetchSchema($schemaId);

			$processedDeckData = [];

			// Process each item in the deck
			foreach ($value as $itemId => $itemData) {
				if (!is_array($itemData)) {
					continue; // Skip non-array items
				}

				$processedItemData = [];

				// Process each field in the item using PropertyFactory
				foreach ($itemData as $fieldName => $fieldValue) {
					$fieldSchema = $deckSchema->properties[$fieldName] ?? null;

					if ($fieldSchema) {
						// Use PropertyFactory for proper data conversion (dates, colors, etc.)
						$propertyObject                = $this->generateProperty($fieldSchema, $fieldValue);
						$processedItemData[$fieldName] = $propertyObject->transform();
					} else {
						// Field not in schema, skip it (proper schema validation)
						// This ensures only schema-defined fields are included
					}
				}

				$processedDeckData[$itemId] = $processedItemData;
			}

			return new DeckData($processedDeckData, $settings);
		} catch (\Exception $e) {
			// If deck processing fails, return original data to avoid breaking the system
			return new DeckData($value, $settings);
		}
	}

	/**
	 * Process an individual deck item (for individual deck API operations).
	 *
	 * This method handles the common case where you need to process a single deck item
	 * through the same pipeline as full deck processing, ensuring data consistency.
	 *
	 * @param string $collection
	 * @param string $propertyName
	 * @param array<string,mixed> $itemData
	 *
	 * @return array<string,mixed>
	 */
	public function processIndividualDeckItem(string $collection, string $propertyName, array $itemData): array
	{
		// Get the schema for the collection to find the deck property configuration
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

		$propertyConfig = $schema->properties[$propertyName] ?? null;
		if (!$propertyConfig) {
			return $itemData; // No property config found, return as-is
		}

		// Create a simulated deck with just this one item to process it through deck processing
		$deckPropertySchema = [
			'type'     => 'deck',
			'settings' => $propertyConfig['settings'] ?? [],
		];

		// Add deckref from property config if it exists
		if (isset($propertyConfig['deckref'])) {
			$deckPropertySchema['deckref'] = $propertyConfig['deckref'];
		}

		$singleItemDeck = [
			$itemData['id'] => $itemData,
		];

		try {
			// Use deck processing to process the single-item deck
			$processedDeckData = $this->createDeck($deckPropertySchema, $singleItemDeck);
			$processedDeck     = $processedDeckData->transform();

			// Return the processed item data
			if (is_array($processedDeck) && isset($processedDeck[$itemData['id']])) {
				return $processedDeck[$itemData['id']];
			}

			return $itemData;
		} catch (\Exception $e) {
			// If processing fails, return original data
			return $itemData;
		}
	}

	/**
	 * Extract schema ID from deckref URL.
	 *
	 * @param string $deckref
	 *
	 * @return string
	 */
	private function extractSchemaId(string $deckref): string
	{
		// Extract schema ID from URL like "https://www.totalcms.co/schemas/custom/features.json"
		$path = parse_url($deckref, PHP_URL_PATH);
		if ($path) {
			return basename($path, '.json');
		}

		return $deckref;
	}
}
