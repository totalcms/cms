<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Object\Data\ObjectData;
/**
 * Total Form Builder.
 */
class ObjectForm extends TotalForm
{
	protected function init(): void
	{
		parent::init();

		$this->route = "/collections/{$this->collection}";

		if ($this->id !== '' && $this->objectFetcher->existsObject($this->collection, $this->id)) {
			// If the form is for editing an existing item, change the method to PUT
			$this->objectData = $this->objectFetcher->fetchObject($this->collection, $this->id);
			$this->route      = "/collections/{$this->collection}/{$this->id}";
			if ($this->method === 'POST') {
				$this->method = 'PUT';
			}
		}

		$this->initCollectionData();
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

		// Get the value from the object data if it exists
		if ($this->id !== '') {
			$defaults = array_merge($defaults, $this->objectFieldProperties($name));

			// A DeckItem will set the deck_context option if it is a deck field
			if ($name === 'id' && !isset($options['deck_context'])) {
				$options['value'] = $this->id;
				// Hide the ID field if requested
				if ($this->hideID) {
					$options['field'] = 'hidden';
				}
			}

			// if the value is not already set, try to get it from the object data
			if (!isset($options['value']) && $this->objectData instanceof ObjectData) {
				$value = $this->objectData->toArray()[$name] ?? null;
				// Use strict checks to preserve zero values (0, 0.0, '0')
				if ($value !== '' && $value !== null) {
					$options['value'] = $value;
				}
			}
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
}
