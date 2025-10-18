<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

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
		protected IndexReader $collectionReader,
		protected IndexFilter $indexFilter,
		protected SchemaFetcher $schemaFetcher,
		public SchemaLister $schemaLister,
		protected SchemaFactory $schemaFactory,
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
		protected ?CSRFTokenManager $csrfManager = null,
	) {
		// CRITICAL: Must call parent constructor to initialize typed properties
		// TotalForm::__construct() calls init() which properly sets:
		// - $this->collectionData, $this->objectData, $this->schemaData
		// Without this, template access to these properties fails with
		// "Typed property must not be accessed before initialization" errors
		parent::__construct(
			$objectFetcher,
			$collectionFetcher,
			$collectionReader,
			$indexFilter,
			$schemaFetcher,
			$schemaLister,
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
			$autosave,
			$helpOnHover,
			$helpOnFocus,
			$hideID,
			$useFormGrid,
			$addOnly,
			$csrfManager
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
			// This is the actual schema object data
			$this->schemaObjectData = $this->schemaFetcher->fetchSchema($this->id);
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
				$options['value'] = $value;
			}
		}

		return $options;
	}
}
