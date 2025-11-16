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

		// Handle deckref for deck fields - move it to settings
		if (isset($defaults['deckref'])) {
			$settings             = $defaults['settings'] ?? [];
			$settings['deckref']  = $defaults['deckref'];
			$defaults['settings'] = $settings;
			unset($defaults['deckref']);
		}

		return TotalForm::filterFieldProperties($defaults);
	}

	/** @return array<string,mixed> */
	protected function fieldAttributeSettings(string $property): array
	{
		// Get the schema and collection settings for a property
		$schema     = $this->schemaData->properties[$property]['settings'] ?? [];
		$collection = $this->collectionData->properties[$property]['settings'] ?? [];

		$attributes = array_merge($schema, $collection);

		return self::filterFieldAttributes($attributes);
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
