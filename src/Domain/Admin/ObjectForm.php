<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Total Form Builder.
 */
class ObjectForm extends TotalForm
{
	/** @var array<string,mixed> Duplicate data for prefilling form (raw values, not PropertyData) */
	private array $duplicateData = [];

	protected function init(): void
	{
		parent::init();

		$this->route = "/collections/{$this->collection}";

		$objectExists = $this->objectFetcher->existsObject($this->collection, $this->id);

		// For addOnly forms, never load existing objects even if an ID is somehow present
		if (!$this->addOnly && $this->id !== '' && $objectExists) {
			// If the form is for editing an existing item, change the method to PUT
			$this->objectData = $this->objectFetcher->fetchObject($this->collection, $this->id);
			$this->route      = "/collections/{$this->collection}/{$this->id}";
			if ($this->method === 'POST') {
				$this->method = 'PUT';
			}
		}

		$this->initCollectionData();

		// Handle duplicate object - filter out file-based properties and store raw data
		if ($this->id === '' && $this->data !== []) {
			$this->duplicateData = $this->filterFileProperties($this->data);
			$this->isDuplicate   = true;
			// Blank out ID to allow autogen rules to work (unless keepIdOnDuplicate setting is enabled)
			$keepId = $this->config?->dashboard['keepIdOnDuplicate'] ?? false;
			if (!$keepId) {
				$this->duplicateData['id'] = '';
			}
		}
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	protected function buildFieldOptions(string $name, array $options = []): array
	{
		// Set the name of the field
		$options['name'] = $name;

		// Setup communication between the field and the form
		$options['form'] = $this;

		// For deck context fields, skip parent schema lookup - the DeckItem already
		// provides complete field configuration from the deck schema
		if (isset($options['deck_context']) && $options['deck_context'] === true) {
			return $options;
		}

		$defaults = $this->fieldDefaults($name);
		$defaults = array_merge($defaults, $this->fieldAttributeSettings($name));

		// Only set required from schema if not explicitly provided in options
		if (!isset($options['required'])) {
			$defaults['required'] = $this->isRequired($name);
		}

		// Handle ID field hiding (must be checked before object data check)
		if ($name === 'id' && !isset($options['deck_context'])) {
			// Hide the ID field if requested
			if ($this->hideID) {
				$options['field'] = 'hidden';
			}

			// Set ID value if form has an ID
			if ($this->id !== '') {
				$options['value'] = $this->id;
			}
		}

		// Get the value from the object data if it exists (for editing)
		if ($this->id !== '' && $this->objectData instanceof ObjectData) {
			$defaults = array_merge($defaults, $this->objectFieldProperties($name));

			// Set value from object data
			if (!isset($options['value'])) {
				$value = $this->objectData->toArray()[$name] ?? null;
				// Use strict checks to preserve zero values (0, 0.0, '0')
				if ($value !== '' && $value !== null) {
					$options['value'] = $value;
				}
			}
		}

		// Get the value from duplicate data if it exists (for duplicating)
		if ($this->duplicateData !== [] && !isset($options['value'])) {
			$value = $this->duplicateData[$name] ?? null;
			// Use strict checks to preserve zero values (0, 0.0, '0')
			if ($value !== '' && $value !== null) {
				$options['value'] = $value;
			}
		}

		// Deep merge settings to preserve schema settings when overriding specific values
		if (isset($defaults['settings']) && isset($options['settings']) && (is_array($defaults['settings']) && is_array($options['settings']))) {
			$options['settings'] = array_merge($defaults['settings'], $options['settings']);
		}

		return array_merge($defaults, $options);
	}

	/** @return array<string,mixed> */
	private function fieldDefaults(string $property): array
	{
		// Get the schema and collection settings for a property
		$schema     = $this->schemaData->properties[$property] ?? [];
		$collection = $this->collectionData->properties[$property] ?? [];

		$defaults = array_merge($schema, $collection);

		// Resolve presets within the settings key
		$defaults['settings'] = $this->resolveFieldSettings($property, $defaults['field'] ?? '');

		// Handle deckref for deck fields - move it to settings after resolve
		// This must happen after resolveFieldSettings() to avoid being overwritten
		if (isset($defaults['deckref'])) {
			$defaults['settings']['deckref'] = $defaults['deckref'];
			unset($defaults['deckref']);
		}
		if (isset($defaults['deckItemLabel'])) {
			$defaults['settings']['deckItemLabel'] = $defaults['deckItemLabel'];
			unset($defaults['deckItemLabel']);
		}

		return TotalForm::filterFieldProperties($defaults);
	}

	/**
	 * Resolve the full settings for a field property, including presets.
	 *
	 * Merges settings from schema, collection, and custom levels.
	 * Resolves named presets at each level and falls back to type-default presets.
	 *
	 * @return array<string,mixed>
	 */
	private function resolveFieldSettings(string $property, string $fieldType): array
	{
		$schema     = $this->schemaData->properties[$property]['settings'] ?? [];
		$collection = $this->collectionData->properties[$property]['settings'] ?? [];
		$custom     = $this->collectionData->customProperties[$this->id][$property]['settings'] ?? [];

		// Resolve preset references at any level
		$schema     = $this->resolvePreset($schema);
		$collection = $this->resolvePreset($collection);
		$custom     = $this->resolvePreset($custom);

		$settings = array_merge($schema, $collection, $custom);

		// If no settings exist at any level, check for a type-default preset
		// A preset named after the field type (e.g., "styledtext") auto-applies
		if ($settings === [] && $fieldType !== '') {
			$settings = $this->resolveTypePreset($fieldType);
		}

		return $settings;
	}

	/** @return array<string,mixed> */
	protected function fieldAttributeSettings(string $property): array
	{
		// Get the schema settings for a property
		$schema = $this->schemaData->properties[$property]['settings'] ?? [];

		return self::filterFieldAttributes($schema);
	}

	/**
	 * Resolve a preset reference in settings.
	 *
	 * If settings contain a "preset" key, load the named preset as the base
	 * and merge any additional explicit settings on top.
	 *
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string,mixed>
	 */
	private function resolvePreset(array $settings): array
	{
		if (!isset($settings['preset']) || !is_string($settings['preset'])) {
			return $settings;
		}

		$presetName = $settings['preset'];
		unset($settings['preset']);

		$preset = $this->config->presets[$presetName] ?? [];

		// Deck format stores presets as {id, settings}, extract the settings
		$presetValues = is_array($preset['settings'] ?? null) ? $preset['settings'] : $preset;

		if (!is_array($presetValues) || $presetValues === []) {
			return $settings;
		}

		// Preset is the base, explicit settings override
		return array_merge($presetValues, $settings);
	}

	/**
	 * Look up a type-default preset matching the field type name.
	 *
	 * If a preset exists with a name matching the field type (e.g., "styledtext"),
	 * it automatically applies as the default settings for all fields of that type
	 * that have no explicit settings.
	 *
	 * @return array<string,mixed>
	 */
	private function resolveTypePreset(string $fieldType): array
	{
		$preset = $this->config->presets[$fieldType] ?? [];

		// Deck format stores presets as {id, settings}, extract the settings
		$presetValues = is_array($preset['settings'] ?? null) ? $preset['settings'] : $preset;

		if (!is_array($presetValues) || $presetValues === []) {
			return [];
		}

		return $presetValues;
	}

	private function isRequired(string $property): bool
	{
		if (!$this->schemaData instanceof SchemaData) {
			return false;
		}

		return in_array($property, $this->schemaData->required);
	}

	/**
	 * Get the properties for a object from customProperties in the collection meta data.
	 *
	 * @return array<string,mixed>
	 * */
	private function objectFieldProperties(string $property): array
	{
		if ($this->id === '') {
			return [];
		}

		// Get the schema and collection settings for a property
		$properties = $this->collectionData->customProperties[$this->id][$property] ?? [];

		// Resolve preset references in settings
		if (isset($properties['settings']) && is_array($properties['settings'])) {
			$properties['settings'] = $this->resolvePreset($properties['settings']);
		}

		return TotalForm::filterFieldProperties($properties);
	}

	private function initCollectionData(): void
	{
		$collectionData = $this->collectionFetcher->fetchCollection($this->collection);

		if (is_null($collectionData)) {
			$this->buildError = "Collection {$this->collection} not found for TotalForm";

			return;
		}

		$this->collectionData = $collectionData;
		$this->schema         = $this->collectionData->schema;
		$this->schemaData     = $this->schemaFetcher->fetchSchema($this->schema);
	}

	/**
	 * Filter out file-based properties from duplicate data.
	 * Excludes: file, image, depot, gallery (but keeps SVG).
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	private function filterFileProperties(array $data): array
	{
		$excludedTypes = ['file', 'image', 'depot', 'gallery'];

		if (!$this->schemaData instanceof SchemaData) {
			return $data;
		}

		foreach ($this->schemaData->properties as $propertyName => $propertySchema) {
			// Get the property type from $ref or type field
			$propertyType = null;

			if (isset($propertySchema['$ref'])) {
				// Extract type from $ref URL
				foreach (SchemaData::PROPERTY_TYPE_TO_REF as $type => $ref) {
					if ($propertySchema['$ref'] === $ref) {
						$propertyType = $type;
						break;
					}
				}
			} elseif (isset($propertySchema['type'])) {
				$propertyType = $propertySchema['type'];
			}

			// Remove property if it's a file-based type
			if ($propertyType !== null && in_array($propertyType, $excludedTypes, true)) {
				unset($data[$propertyName]);
			}
		}

		return $data;
	}
}
