<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Admin\FormField\SelectField;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;

/**
 * Total Form Builder.
 */
final class CollectionForm extends TotalForm
{
	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
	 *
	 * @param array<string,string> $newAction
	 * @param array<string,string> $editAction
	 * @param array<string,string> $deleteAction
	 */
	public function __construct(
		protected CollectionFetcher $collectionFetcher,
		protected SchemaFetcher $schemaFetcher,
		protected SchemaLister $schemaLister,
		public string $api,
		public string $id          = '',
		protected string $method      = 'POST',
		protected string $class       = '',
		protected string $helpStyle   = '',
		protected string $save        = '',
		protected string $delete      = '',
		protected array $deleteAction = [],
		protected array $editAction   = [],
		protected array $newAction    = [
			'action' => 'redirect-object',
			'link'   => '?id=',
		],
		protected bool $autosave    = false,
		protected bool $helpOnHover = false,
		protected bool $helpOnFocus = false,
		protected bool $hideID      = false,
		protected bool $useFormGrid = true,
	) {
		$this->init();
		$this->initClass();
	}

	protected function init(): void
	{
		parent::init();

		$this->route = '/collections';

		if (!empty($this->id)) {
			$this->initCollectionData();
			$this->route  = '/collections/' . $this->id;
			$this->method = 'PUT';
		}
		$this->formType   = 'collection';
		$this->schema     = 'collection';
		$this->schemaData = $this->schemaFetcher->fetchSchema($this->schema);
	}

	public function getCollectionSchema(): ?SchemaData
	{
		$schema = (string)$this->fields['schema']->getValue();
		if (empty($schema)) {
			return null;
		}

		return $this->schemaFetcher->fetchSchema($schema);
	}

	private function initCollectionData(): void
	{
		$collectionData = $this->collectionFetcher->fetchCollection($this->id);

		if (is_null($collectionData)) {
			$this->buildError = "Collection {$this->id} not found for TotalForm";

			return;
		}

		$this->collectionData = $collectionData;
	}

	protected function fieldContent(): string
	{
		// Generate the schema field options
		$schemaField = $this->fields['schema'];
		if ($schemaField instanceof SelectField) {
			$schemaField->setOptions([
				'Custom Schemas'   => $this->customSchemas(),
				'Reserved Schemas' => $this->reservedSchemas(),
			]);
			if ($this->method === 'PUT') {
				// disable schema for edit mode
				$schemaField->disable();
			}
		}
		$sortField = $this->fields['sortBy'];
		if (isset($this->collectionData) && $sortField instanceof SelectField) {
			$schema     = $this->schemaFetcher->fetchSchema($this->collectionData->schema);
			$properties = $schema->properties ?? [];
			$options    = count($properties) > 0 ? array_keys($properties) : ['id'];
			$sortField->setOptions($options);
		}

		return parent::fieldContent();
	}

	/** @return array<string> */
	private function reservedSchemas(): array
	{
		$schemas = $this->schemaLister->listReservedSchemas();
		$schemas = array_map(fn ($schema) => $schema->id, $schemas);
		$ignore  = ['collection', 'schema'];

		if (empty($this->id)) {
			// Do not allow a new collection to be created with the blog-legacy schema
			$ignore[] = 'blog-legacy';
		}

		return array_filter($schemas, fn ($schema) => !in_array($schema, $ignore));
	}

	/** @return array<string> */
	private function customSchemas(): array
	{
		$schemas = $this->schemaLister->listCustomSchemas();

		return array_map(fn ($schema) => $schema->id, $schemas);
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
		$defaults = array_merge($defaults, $this->fieldAttributeSettings($name));

		$options  = array_merge($defaults, $options);

		// Set the name of the field
		$options['name'] = $name;

		// Setup communication between the field and the form
		$options['form'] = $this;

		if (isset($this->collectionData)) {
			$value = $this->collectionData->toArray()[$name] ?? '';
			if (!empty($value)) {
				$options['value'] = $value;
			}
		}

		return $options;
	}
}
