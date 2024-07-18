<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;

/**
 * Total Form Builder.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
final class SchemaForm extends TotalForm
{
	public bool $reserved = false;
	public SchemaData $schemaObjectData;

	/**
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 *
	 * @param array<string,string> $newAction
	 * @param array<string,string> $editAction
	 */
	public function __construct(
		protected SchemaFetcher $schemaFetcher,
		protected SchemaLister $schemaLister,
		public string $api,
		public string $id        = '',
		protected string $method    = 'POST',
		protected string $class     = '',
		protected string $helpStyle = '',
		protected string $save      = '',
		protected array $editAction = [],
		protected array $newAction  = [
			'action' => 'redirect-object',
			'link'   => '?id=',
		],
		protected bool $autosave    = false,
		protected bool $helpOnHover = false,
		protected bool $helpOnFocus = false,
	) {
		$this->init();
		$this->initClass();
	}

	protected function init(): void
	{
		parent::init();

		$this->route = '/schemas';

		if (!empty($this->id)) {
			$this->route            = '/schemas/' . $this->id;
			$this->method           = 'PUT';
			$this->reserved         = $this->isReservedSchema($this->id);
			// This is the actual schema object data
			$this->schemaObjectData = $this->schemaFetcher->fetchSchema($this->id);
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

	private function isReservedSchema(string $id): bool
	{
		$schemas = $this->schemaLister->listReservedSchemas();
		$schemas = array_map(fn ($schema) => $schema->id, $schemas);

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
		$defaults = $this->filterFieldProperties($defaults);

		$options  = array_merge($defaults, $options);

		// Set the name of the field
		$options['name'] = $name;

		// Setup communication between the field and the form
		$options['form'] = $this;

		if (!empty($this->id) && ($name === 'required' || $name === 'index')) {
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
