<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Admin\FormField\DeleteButton;
use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Admin\FormField\SelectField;
use TotalCMS\Domain\Admin\FormField\SaveButton;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Utils\HTMLUtils;

/**
 * Total Form Builder.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
final class CollectionForm extends TotalForm
{
	/** @var array<string,FormField> */
	protected array $fields = [];
	protected string $route;
	protected CollectionData $collectionData;
	protected SchemaData $schemaData;

	/**
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 *
	 * @param array<string,string> $newAction
	 * @param array<string,string> $editAction
	 */
	public function __construct(
		protected CollectionFetcher $collectionFetcher,
		protected SchemaFetcher $schemaFetcher,
		protected SchemaLister $schemaLister,
		public string $api,
		public string $id           = '',
		protected string $method    = 'POST',
		private string $class       = '',
		private string $helpStyle   = '',
		private string $save        = '',
		private array $newAction    = [],
		private array $editAction   = [],
		private bool $autosave      = false,
		private bool $helpOnHover   = false,
		private bool $helpOnFocus   = false,
	) {
		$this->init();
		$this->initClass();
		$this->autoBuild();
	}

	/** @SuppressWarnings(PHPMD.Superglobals) */
	protected function init(): void
	{
		$this->route = '/collections';

		if (empty($this->id) && isset($_GET['id'])) {
			$this->id = $_GET['id'];
			$this->initCollectionData();
			$this->route  = '/collections/' . $this->id;
			$this->method = 'PUT';
		}

		$this->schemaData = $this->schemaFetcher->fetchSchema('collection');
	}

	private function initClass(): void
	{
		if ($this->autosave === true) {
			$this->class .= ' autosave';
		}
		if ($this->helpOnHover === true) {
			$this->class .= ' help-on-hover';
		}
		if ($this->helpOnFocus === true) {
			$this->class .= ' help-on-focus';
		}
		if (!empty($this->helpStyle)) {
			$this->class .= " help-{$this->helpStyle}";
		}
		if ($this->method === 'PUT') {
			$this->class .= ' edit-mode';
		}
	}

	protected function initCollectionData(): void
	{
		$collectionData = $this->collectionFetcher->fetchCollection($this->id);

		if (is_null($collectionData)) {
			// throw new \Exception('Collection not found for TotalForm');
			return;
		}

		$this->collectionData = $collectionData;
	}

	public function addFieldsFromSchema(): void
	{
		$properties = array_keys($this->schemaData->properties);
		foreach ($properties as $property) {
			$this->addField($property);
		}
	}

	/** @return array<string> */
	private function reservedSchemas(): array
	{
		$schemas = $this->schemaLister->listReservedSchemas();

		return array_map(fn ($schema) => $schema->id, $schemas);
	}

	/** @return array<string> */
	private function customSchemas(): array
	{
		$schemas = $this->schemaLister->listCustomSchemas();

		return array_map(fn ($schema) => $schema->id, $schemas);
	}

	public function autoBuild(string $content = ''): string
	{
		$this->addFieldsFromSchema();

		// Generate the schema field options
		$schemaField = $this->fields['schema'];
		if ($schemaField instanceof SelectField) {
			$schemaField->setOptions([
				'Custom Schemas'   => $this->customSchemas(),
				'Reserved Schemas' => $this->reservedSchemas(),
			]);
		}

		return $this->build($content);
	}

	public function build(string $content = ''): string
	{
		$attributes = [
			'class'       => "totalform {$this->class}",
			'data-form'   => "collection",
			'data-schema' => "collection",
			'data-method' => $this->method,
			'data-api'    => $this->api,
			'data-route'  => $this->route,
		];

		if (!empty($this->id)) {
			$attributes['data-id'] = $this->id;
		}
		if (!empty($this->newAction)) {
			$json = json_encode($this->newAction);
			if ($json) {
				$attributes['data-new-action'] = $json;
			}
		}
		if (!empty($this->editAction)) {
			$json = json_encode($this->editAction);
			if ($json) {
				$attributes['data-edit-action'] = $json;
			}
		}

		$content .= $this->fieldContent();
		$content .= $this->saveButton();

		return HTMLUtils::createHTMLElement('form', $content, $attributes);
	}

	private function saveButton(): string
	{
		if (empty($this->save)) {
			return '';
		}
		$button = new SaveButton($this->save);

		return $button->build();
	}

	private function fieldContent(): string
	{
		if (!empty($this->fields) && !isset($this->fields['id'])) {
			// Add the ID field if it does not exist1
			$this->addField('id', ['required' => true]);
		}

		$content = '';
		foreach ($this->fields as $field) {
			$content .= $field->build();
		}

		return $content;
	}

	/**
	 * @param array<string,mixed> $properties
	 *
	 * @return array<string,mixed>
	 */
	private function filterFieldProperties(array $properties): array
	{
		// Remove any keys that are not needed for the field
		// Since PHP will unknown named parameters
		return array_filter($properties, fn ($key) => in_array($key, TotalForm::FIELD_PROPERTIES), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	private function buildFieldOptions(string $name, array $options = [])
	{
		// Get the schema settings for a property
		$defaults = $this->schemaData->properties[$name] ?? [];
		$defaults = $this->filterFieldProperties($defaults);

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

	/** @param array<string,mixed> $options */
	public function addField(string $name, array $options = []): void
	{
		if (!isset($this->schemaData->properties[$name])) {
			// Field '{$name}' not found in schema
			return;
		}

		$field = $this->createDynamicField($name, $options);

		$this->fields[$name] = $field;
	}

	/** @param array<string,mixed> $options */
	private function createDynamicField(string $name, array $options = []): FormField
	{
		$options = $this->buildFieldOptions($name, $options);

		$typeClass = 'TotalCMS\\Domain\\Admin\\FormField\\' . ucfirst($options['field'] ?? '') . 'Field';
		if (class_exists($typeClass) && is_subclass_of($typeClass, FormField::class)) {
			return new $typeClass(...$options);
		}

		return new FormField(...$options);
	}
}
