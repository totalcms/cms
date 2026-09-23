<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Form\Builder;

use TotalCMS\Domain\Admin\TotalForm;
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
		// Public-registration mode forces add-only before parent::init() runs,
		// so the parent's $_GET['id'] auto-load path doesn't fire either.
		if ($this->register) {
			$this->addOnly = true;
		}

		parent::init();

		$this->route = "/collections/{$this->collection}";

		// Retarget the form at the public registration endpoint. `data-api`
		// drops the `/api` prefix because `/admin/register` lives at the
		// config base, not under the API prefix — same convention as the
		// admin-routed forms in TotalFormFactory::totalform().
		if ($this->register) {
			$this->api   = $this->services->config->api;
			$this->route = "/admin/register/{$this->collection}";
		}

		$objectExists = $this->services->objectFetcher->existsObject($this->collection, $this->id);

		// For addOnly forms, never load existing objects even if an ID is somehow present
		if (!$this->addOnly && $this->id !== '' && $objectExists) {
			// If the form is for editing an existing item, change the method to PUT
			$this->objectData = $this->services->objectFetcher->fetchObject($this->collection, $this->id);
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
			$keepId = $this->services->config->dashboard['keepIdOnDuplicate'] ?? false;
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

		if (self::isCompositeChild($options)) {
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
		// Set value from object data
		if ($this->id !== '' && $this->objectData instanceof ObjectData && !isset($options['value'])) {
			$value = $this->objectData->toArray()[$name] ?? null;
			// Use strict checks to preserve zero values (0, 0.0, '0')
			if ($value !== '' && $value !== null) {
				$options['value'] = $value;
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
		$defaults = $this->services->metaResolver->resolve($this->collection, $property, $this->id);

		// Handle schema reference for deck/card fields — move to settings after resolve
		// This is a form-specific concern, not part of general resolution.
		// Accept both the canonical `schemaref` and the legacy `deckref` alias.
		if (isset($defaults['schemaref'])) {
			$defaults['settings']['schemaref'] = $defaults['schemaref'];
			unset($defaults['schemaref']);
		}
		if (isset($defaults['deckref'])) {
			$defaults['settings']['schemaref'] ??= $defaults['deckref'];
			unset($defaults['deckref']);
		}
		if (isset($defaults['deckItemLabel'])) {
			$defaults['settings']['deckItemLabel'] = $defaults['deckItemLabel'];
			unset($defaults['deckItemLabel']);
		}

		return $defaults;
	}

	private function isRequired(string $property): bool
	{
		if (!$this->schemaData instanceof SchemaData) {
			return false;
		}

		return in_array($property, $this->schemaData->required);
	}

	private function initCollectionData(): void
	{
		$collectionData = $this->services->collectionFetcher->fetchCollection($this->collection);

		if (is_null($collectionData)) {
			$this->buildError = "Collection {$this->collection} not found for TotalForm";

			return;
		}

		$this->collectionData = $collectionData;
		$this->schema         = $this->collectionData->schema;
		$this->schemaData     = $this->services->schemaFetcher->fetchSchema($this->schema);
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
