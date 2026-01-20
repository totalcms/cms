<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Storage\StorageRepository;

/**
 * Service.
 */
readonly class PropertyFactory
{
	public function __construct(
		private SchemaFetcher $schemaFetcher,
		private DeckCompatibilityChecker $deckCompatibilityChecker,
	) {
	}

	/**
	 * create a property object.
	 *
	 * @param array<string,mixed>  $propertySchema
	 *
	 * @throws \DomainException
	 * @throws \UnexpectedValueException
	 */
	public function generateProperty(array $propertySchema, mixed $value): PropertyData
	{
		$type = $propertySchema['type'] ?? basename($propertySchema['$ref'] ?? '', StorageRepository::FILE_EXT);

		// Special handling for deck properties
		if ($type === 'deck') {
			$settings = $propertySchema['settings'] ?? [];

			return $this->createDeck($propertySchema, $value, $settings);
		}

		$className = 'TotalCMS\\Domain\\Property\\Data\\' . ucfirst((string)$type) . 'Data';
		if (!class_exists($className)) {
			throw new \UnexpectedValueException('Unknown property type for object.');
		}

		if (isset($propertySchema['default'])) {
			$value = $className::defaultValue($value, $propertySchema['default']);
		}

		// Handle array passed to string type (schema/form mismatch)
		if (is_array($value) && $type === 'string') {
			$value = json_encode($value);
		}

		// Handle JSON string passed to array types (form sends JSON strings for complex fields)
		$arrayTypes = ['image', 'gallery', 'file', 'depot', 'deck'];
		if (is_string($value) && in_array($type, $arrayTypes, true) && (str_starts_with($value, '{') || str_starts_with($value, '['))) {
			$decoded = json_decode($value, true);
			if (is_array($decoded)) {
				$value = $decoded;
			}
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
			$schemaId   = SchemaFetcher::extractSchemaId($deckref);
			$deckSchema = $this->schemaFetcher->fetchSchema($schemaId);

			// Validate that the deck schema doesn't contain incompatible properties
			$this->validateDeckSchema($deckSchema, $schemaId);

			$processedDeckData = [];

			// Process each item in the deck
			foreach ($value as $itemId => $itemData) {
				if (!is_array($itemData)) {
					continue; // Skip non-array items
				}

				$processedItemData = [];

				// Iterate over schema properties (like ObjectFactory does for objects)
				// This ensures all schema properties are processed with proper defaults
				foreach ($deckSchema->properties as $fieldName => $fieldSchema) {
					$fieldValue = $itemData[$fieldName] ?? null;
					// Use generateProperty for proper data conversion and default handling
					$propertyObject                = $this->generateProperty($fieldSchema, $fieldValue);
					$processedItemData[$fieldName] = $propertyObject->transform();
				}

				$processedDeckData[$itemId] = $processedItemData;
			}

			return new DeckData($processedDeckData, $settings);
		} catch (\Exception) {
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

			return $processedDeck[$itemData['id']] ?? $itemData;
		} catch (\Exception) {
			// If processing fails, return original data
			return $itemData;
		}
	}

	/**
	 * Validate that a deck schema doesn't contain incompatible property types.
	 *
	 * @param SchemaData $deckSchema The deck schema to validate
	 * @param string $schemaId The schema ID for error messages
	 *
	 * @throws \InvalidArgumentException If schema contains incompatible properties
	 */
	private function validateDeckSchema(SchemaData $deckSchema, string $schemaId): void
	{
		// Use DeckCompatibilityChecker to validate the schema
		$schemaArray = $deckSchema->toArray();

		if (!$this->deckCompatibilityChecker->isCompatible($schemaArray)) {
			$incompatibleProperties = $this->deckCompatibilityChecker->getIncompatibleProperties($schemaArray);
			$propertyList           = implode(', ', $incompatibleProperties);

			// $incompatibleTypes = $this->deckCompatibilityChecker->getSchemaIncompatibleTypes($schemaArray);
			// $typeList = implode(', ', $incompatibleTypes);

			throw new \InvalidArgumentException("Deck schema '$schemaId' contains incompatible properties: $propertyList");
		}
	}
}
