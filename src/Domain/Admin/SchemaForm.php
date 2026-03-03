<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\DataView\Service\DataViewFilter;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

/**
 * Total Form Builder.
 */
class SchemaForm extends TotalForm
{
	public bool $reserved = false;
	public SchemaData $schemaObjectData;

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
	 *
	 * @param array<int,array<string,mixed>> $newActions
	 * @param array<int,array<string,mixed>> $deleteActions
	 * @param array<int,array<string,mixed>> $editActions
	 * @param array<string,mixed>  $data
	 */
	public function __construct(
		protected ObjectFetcher $objectFetcher,
		protected CollectionFetcher $collectionFetcher,
		protected CollectionLister $collectionLister,
		protected IndexReader $collectionReader,
		protected IndexFilter $indexFilter,
		protected SchemaFetcher $schemaFetcher,
		public SchemaLister $schemaLister,
		protected AccessGroupLister $accessGroupLister,
		protected CollectionEditionService $collectionEditionService,
		protected EditionFeatureService $editionFeatures,
		protected SchemaFactory $schemaFactory,
		protected DataViewFilter $dataViewFilter,
		protected CSRFTokenManager $csrfManager,
		protected Config $config,
		protected PropertyMetaResolver $metaResolver,
		public string $api,
		public string $collection = '',
		public string $id          = '',
		protected string $method      = 'POST',
		protected string $class       = '',
		protected string $buildError  = '',
		protected string $helpStyle   = '',
		protected string $save        = '',
		protected string $delete      = '',
		protected string $formType    = '',
		protected string $schema      = '',
		protected string $route       = '',
		protected array $newActions    = [],
		protected array $editActions   = [],
		protected array $deleteActions = [],
		protected array $data         = [],
		protected bool $autosave      = false,
		protected bool $helpOnHover   = false,
		protected bool $helpOnFocus   = false,
		protected bool $hideID        = false,
		protected bool $useFormGrid   = true,
		protected bool $addOnly       = false,
	) {
		parent::__construct(
			$objectFetcher,
			$collectionFetcher,
			$collectionLister,
			$collectionReader,
			$indexFilter,
			$schemaFetcher,
			$schemaLister,
			$accessGroupLister,
			$collectionEditionService,
			$editionFeatures,
			$dataViewFilter,
			$csrfManager,
			$config,
			$metaResolver,
			$api,
			$collection,
			$id,
			$method,
			$class,
			$buildError,
			$helpStyle,
			$save,
			$delete,
			$formType,
			$schema,
			$route,
			$newActions,
			$editActions,
			$deleteActions,
			$data,
			$autosave,
			$helpOnHover,
			$helpOnFocus,
			$hideID,
			$useFormGrid,
			$addOnly,
		);
	}

	protected function init(): void
	{
		parent::init();

		$this->route = '/schemas';

		if ($this->id !== '') {
			$this->route            = '/schemas/' . $this->id;
			$this->method           = 'PUT';
			$this->reserved         = $this->isReservedSchema($this->id);
			// This is the actual schema object data - fetch without flattening
			// so we only see the schema's own properties in the editor
			$this->schemaObjectData = $this->schemaFetcher->fetchRawSchema($this->id);
		}
		// Duplicate Schema
		if ($this->id === '' && $this->data !== []) {
			// Convert property types to refs for the properties field
			$this->data['properties'] = SchemaSaver::propertyTypeToRef($this->data['properties']);
			$this->schemaObjectData   = $this->schemaFactory->generateSchema($this->data);
			$this->reserved           = false;
			// If the schema is being duplicated, we do not want to keep the ID
			$this->schemaObjectData->id = '';
		}

		$this->formType   = 'schema';
		$this->schema     = 'schema';
		// This is the schema for a schema object
		$this->schemaData = $this->schemaFetcher->fetchSchema($this->schema);

		if ($this->reserved) {
			// Do not allow delete or save for reserved schemas
			$this->save   = '';
			$this->delete = '';
		}
	}

	public function autoBuild(string $content = ''): string
	{
		if ($this->id === 'schema' || $this->id === 'collection') {
			return "<p class='alert'>You cannot edit the `{$this->id}` schema.</p>";
		}

		return parent::autoBuild($content);
	}

	private function isReservedSchema(string $id): bool
	{
		$schemas = $this->schemaLister->listReservedSchemas();
		$schemas = array_map(fn (SchemaData $schema): string => $schema->id, $schemas);

		return in_array($id, $schemas);
	}

	/**
	 * Get inherited properties from parent schemas.
	 * Returns array with property details including source schema, field type, and property type.
	 * Only shows properties that are PURELY inherited (not also defined in the schema itself).
	 *
	 * @return array<string,array{source:string,field:string,type:string,definition:array<string,mixed>}>
	 */
	public function getInheritedProperties(): array
	{
		if (!isset($this->schemaObjectData) || $this->schemaObjectData->inheritFrom === []) {
			return [];
		}

		$inheritedProperties = [];
		$ownPropertyNames    = array_keys($this->schemaObjectData->properties);

		// Process each parent schema in order to collect all inherited property details
		foreach ($this->schemaObjectData->inheritFrom as $parentId) {
			try {
				$parentSchema = $this->schemaFetcher->fetchRawSchema($parentId);

				foreach ($parentSchema->properties as $propName => $propDef) {
					// Only add if not already in own properties and not already inherited (first wins)
					if (!in_array($propName, $ownPropertyNames, true) && !isset($inheritedProperties[$propName])) {
						$inheritedProperties[$propName] = [
							'source'     => $parentId,
							'field'      => $propDef['field'] ?? 'text',
							'type'       => SchemaSaver::extractPropertyType($propDef),
							'definition' => $propDef,
						];
					}
				}
			} catch (\Exception) {
				// Skip if parent schema doesn't exist
				continue;
			}
		}

		return $inheritedProperties;
	}

	/**
	 * Filter out inherited properties from a properties array.
	 * Removes purely inherited properties (ones not defined in the schema itself).
	 *
	 * @param array<string,mixed> $properties
	 *
	 * @return array<string,mixed>
	 */
	private function filterInheritedProperties(array $properties): array
	{
		if (!isset($this->schemaObjectData) || $this->schemaObjectData->inheritFrom === []) {
			return $properties;
		}

		// Get inherited property names (already filtered to only purely inherited ones)
		$inheritedProps         = $this->getInheritedProperties();
		$inheritedPropertyNames = array_keys($inheritedProps);

		// Remove inherited properties from the display
		foreach ($inheritedPropertyNames as $propName) {
			unset($properties[$propName]);
		}

		return $properties;
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	protected function buildFieldOptions(string $name, array $options = []): array
	{
		// Get the schema settings for a property
		$defaults = $this->schemaData->properties[$name] ?? [];
		$defaults = TotalForm::filterFieldProperties($defaults);

		$options  = array_merge($defaults, $options);

		// Set the name of the field
		$options['name'] = $name;

		// Setup communication between the field and the form
		$options['form'] = $this;

		if ($this->id !== '' && ($name === 'required' || $name === 'index')) {
			// Set all of the properties as options for the required and index fields
			$options['options'] = array_keys($this->schemaObjectData->toArray()['properties']);
		}

		if ($name === 'inheritFrom') {
			// Get all available schemas (both reserved and custom) for inheritFrom field
			$allSchemas = $this->schemaLister->listAllSchemas();
			$schemaIds  = array_map(fn (SchemaData $schema): string => $schema->id, $allSchemas);

			// Remove 'schema' and 'collection' schemas and the current schema being edited
			$schemaIds = array_filter($schemaIds, fn (string $id): bool => !in_array($id, ['schema', 'collection', $this->id], true));

			$options['options'] = array_values($schemaIds);
		}

		if ($this->reserved) {
			$options['disabled'] = true;
			$options['readonly'] = true;
		}

		// This method is used to build field options for the main schema fields as well as the
		// schema property fields. Since both have a type property, there is a conflict.
		// This following ensures that when the type property is set, it is not overwritten with
		// the type from schemaObjectData
		if ($name === 'type' && isset($options['value'])) {
			return $options;
		}

		if (isset($this->schemaObjectData)) {
			$value = $this->schemaObjectData->toArray()[$name] ?? '';
			if (!empty($value)) {
				// For the properties field, filter out inherited properties
				if ($name === 'properties' && is_array($value)) {
					$value = $this->filterInheritedProperties($value);
				}
				$options['value'] = $value;
			}
		}

		return $options;
	}
}
