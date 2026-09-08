<?php

namespace TotalCMS\Domain\Property\Service;

use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\DeckData;
use TotalCMS\Domain\Property\Data\PriceData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

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
	 * Create a property object from a schema definition and value.
	 *
	 * @throws \DomainException
	 * @throws \UnexpectedValueException
	 */
	public function generateProperty(PropertyDefinition $definition, mixed $value, string $propertyName = ''): PropertyData
	{
		$type  = $definition->resolveType();
		$field = $definition->field;

		// Special handling for deck/card properties — dispatch on `field` too,
		// because schemas authored via the form-driven editor set `type: "array"`
		// for these even though `field: "card"`/`"deck"` is the real shape signal.
		// Without this, deck/card values fall through to ArrayData which strips
		// keys via array_values() and corrupts the structure into a list.
		if ($type === 'deck' || $field === 'deck') {
			return $this->createDeck($definition, $value, $definition->settings);
		}
		if ($type === 'card' || $field === 'card') {
			return $this->createCard($definition, $value, $definition->settings, $propertyName);
		}

		// `price` stores as a number but needs currency-aware parsing on input,
		// so it has its own Data class even though resolveType() returns 'number'.
		if ($field === 'price') {
			$className = PriceData::class;
		} else {
			$className = 'TotalCMS\\Domain\\Property\\Data\\' . ucfirst($type) . 'Data';
		}

		if (!class_exists($className)) {
			throw new \UnexpectedValueException('Unknown property type for object.');
		}

		if ($definition->default !== null) {
			$value = $className::defaultValue($value, $definition->default);
		}

		// Handle array passed to string type (schema/form mismatch)
		if (is_array($value) && $type === 'string') {
			$value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		// Handle JSON string passed to array types (form sends JSON strings for complex fields)
		$arrayTypes = ['image', 'gallery', 'file', 'depot', 'localizedtext', 'video'];
		if (is_string($value) && in_array($type, $arrayTypes, true)) {
			if (str_starts_with($value, '{') || str_starts_with($value, '[')) {
				$decoded = json_decode($value, true);
				$value   = is_array($decoded) ? $decoded : null;
			} elseif ($type !== 'video') {
				// Non-JSON string for an array type (e.g. PHP's "Array" cast) — treat as null.
				// A video is the exception: a bare string is the URL (CSV import, an
				// API client sending just the link) and VideoData reads it as such.
				$value = null;
			}
		}

		$property = null === $value ? new $className(settings: $definition->settings) : new $className($value, $definition->settings);

		if (!$property instanceof PropertyData) {
			throw new \DomainException('Error creating property for object.');
		}

		return $property;
	}

	/**
	 * Create a DeckData object with properly processed items.
	 *
	 * @param mixed $value The raw deck data
	 * @param array<string,mixed> $settings The deck settings
	 */
	public function createDeck(PropertyDefinition $definition, mixed $value, array $settings = []): DeckData
	{
		// If no deck data provided, return empty deck
		if (empty($value) || !is_array($value)) {
			return new DeckData([], $settings);
		}

		$schemaref = $definition->schemaref;

		// If no schema reference, return deck as-is (no processing)
		if (in_array($schemaref, [null, '', '0'], true)) {
			return new DeckData($value, $settings);
		}

		try {
			// Get the deck item schema
			$schemaId   = SchemaFetcher::extractSchemaId($schemaref);
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
					$propertyObject                = $this->generateProperty(PropertyDefinition::fromArray($fieldSchema), $fieldValue);
					$processedItemData[$fieldName] = $propertyObject->transform();
				}

				// The deck key is the item's identity. An `id` child is usually a
				// slug field, and slugifying lowercases — so `Item_B` would come
				// back as `item_b`, fail DeckData's key-equals-id rule, and push
				// the whole deck onto the unprocessed fallback below. Keep the id
				// bound to its key instead.
				if (array_key_exists('id', $processedItemData)) {
					$processedItemData['id'] = (string)$itemId;
				}

				$processedDeckData[$itemId] = $processedItemData;
			}

			$deck                                     = new DeckData($processedDeckData, $settings);
			[$deck->childTypes, $deck->childSettings] = $this->childTypesAndSettings($deckSchema);

			return $deck;
		} catch (\Exception) {
			// If deck processing fails, return original data to avoid breaking the system
			return new DeckData($value, $settings);
		}
	}

	/**
	 * Create a CardData object with properly processed fields.
	 *
	 * A card stores a single nested object whose shape is defined by another schema
	 * (referenced via `schemaref`). Each field is processed through generateProperty
	 * so defaults, type coercion, and validation behave the same as on a top-level object.
	 *
	 * @param mixed $value The raw card data (associative array of field values)
	 * @param array<string,mixed> $settings The card settings
	 */
	public function createCard(PropertyDefinition $definition, mixed $value, array $settings = [], string $propertyName = ''): CardData
	{
		// If no card data provided, return empty card
		if (empty($value) || !is_array($value)) {
			return new CardData([], $settings);
		}

		$schemaref = $definition->schemaref;

		// If no schema reference, return card data as-is (no processing)
		if (in_array($schemaref, [null, '', '0'], true)) {
			return new CardData($value, $settings);
		}

		try {
			// Get the card sub-schema
			$schemaId   = SchemaFetcher::extractSchemaId($schemaref);
			$cardSchema = $this->schemaFetcher->fetchSchema($schemaId);

			// Validate that the card schema doesn't contain incompatible properties
			// (file-based fields aren't supported inside cards/decks)
			$this->validateDeckSchema($cardSchema, $schemaId);

			$processed = [];

			// Iterate over schema properties (like ObjectFactory does for objects)
			// so all sub-fields are processed with proper defaults and type conversion.
			// `id` is a special case for cards: it's required by the schema but
			// meaningless to the user (CardField hides the field). We force it to
			// the parent property name so the saved data feels intentional and
			// stable — e.g. `mycard.id = "mycard"`.
			foreach ($cardSchema->properties as $fieldName => $fieldSchema) {
				if ($fieldName === 'id') {
					$processed[$fieldName] = $propertyName !== '' ? $propertyName : 'card';
					continue;
				}
				$fieldValue            = $value[$fieldName] ?? null;
				$propertyObject        = $this->generateProperty(PropertyDefinition::fromArray($fieldSchema), $fieldValue);
				$processed[$fieldName] = $propertyObject->transform();
			}

			$card                                     = new CardData($processed, $settings);
			[$card->childTypes, $card->childSettings] = $this->childTypesAndSettings($cardSchema);

			return $card;
		} catch (\Exception) {
			// If card processing fails, return original data to avoid breaking the system
			return new CardData($value, $settings);
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

		$deckDefinition = PropertyDefinition::fromArray($propertyConfig);

		$singleItemDeck = [
			$itemData['id'] => $itemData,
		];

		try {
			// Use deck processing to process the single-item deck
			$processedDeckData = $this->createDeck($deckDefinition, $singleItemDeck);
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
	/**
	 * The resolved field type and settings of every child in a card/deck
	 * sub-schema, so the built value object can say what its plain-array
	 * children are (PropertyDataProcessor re-hydrates nested videos from it).
	 *
	 * @return array{0:array<string,string>,1:array<string,array<string,mixed>>}
	 */
	private function childTypesAndSettings(SchemaData $subSchema): array
	{
		$types    = [];
		$settings = [];
		foreach ($subSchema->properties as $fieldName => $fieldSchema) {
			if (!is_array($fieldSchema)) {
				continue;
			}
			$name            = (string)$fieldName;
			$types[$name]    = PropertyDefinition::fromArray($fieldSchema)->resolveType();
			$settings[$name] = is_array($fieldSchema['settings'] ?? null) ? $fieldSchema['settings'] : [];
		}

		return [$types, $settings];
	}

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
