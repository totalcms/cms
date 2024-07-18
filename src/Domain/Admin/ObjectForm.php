<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Admin\FormField\DeleteButton;
use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Admin\FormField\SaveButton;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Utils\HTMLUtils;

/**
 * Total Form Builder.
 */
final class ObjectForm extends TotalForm
{
	protected function init(): void
	{
		parent::init();

		$this->route = "/collections/{$this->collection}";

		if (!empty($this->id) && $this->objectFetcher->existsObject($this->collection, $this->id)) {
			// If the form is for editing an existing item, change the method to PUT
			$this->objectData = $this->objectFetcher->fetchObject($this->collection, $this->id);
			$this->method     = 'PUT';
			$this->route      = "/collections/{$this->collection}/{$this->id}";
		}

		$this->initCollectionData();
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	protected function buildFieldOptions(string $name, array $options = [])
	{
		// Set the name of the field
		$options['name'] = $name;

		// Setup communication between the field and the form
		$options['form'] = $this;

		$defaults = $this->fieldDefaults($name);

		// Get the value from the object data if it exists
		if (!empty($this->id)) {
			$defaults = array_merge($defaults, $this->objectFieldProperties($name));

			if ($name === 'id') {
				$options['value'] = $this->id;
				// Hide the ID field if requested
				if ($this->hideID) {
					$options['field'] = 'hidden';
				}
			}

			if (isset($this->objectData)) {
				$value = $this->objectData->toArray()[$name] ?? '';
				if (!empty($value)) {
					$options['value'] = $value;
				}
			}
		}

		$options = array_merge($defaults, $options);

		return $options;
	}

	/** @return array<string,mixed> */
	private function fieldDefaults(string $property): array
	{
		// Get the schema and collection settings for a property
		$schema     = $this->schemaData->properties[$property] ?? [];
		$collection = $this->collectionData->properties[$property] ?? [];

		$defaults = array_merge($schema, $collection);

		return TotalForm::filterFieldProperties($defaults);
	}

	/**
	 * Get the properties for a object from customProperties in the collection meta data.
	 *
	 * @return array<string,mixed>
	 * */
	private function objectFieldProperties(string $property): array
	{
		if (empty($this->id)) {
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
			throw new \Exception('Collection not found for TotalForm');
		}

		$this->collectionData = $collectionData;
		$this->schema         = $this->collectionData->schema;
		$this->schemaData     = $this->schemaFetcher->fetchSchema($this->schema);
	}
}
