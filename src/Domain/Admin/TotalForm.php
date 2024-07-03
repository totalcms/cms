<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Utils\HTMLUtils;
use TotalCMS\Domain\Admin\FormField\SaveButton;
use TotalCMS\Domain\Admin\FormField\DeleteButton;

/**
 * Total Form Builder.
 */
final class TotalForm
{
	/** @var array<FormField> */
	private array $fields = [];
	private string $route;
	private CollectionData $collectionData;
	private ObjectData $objectData;
	private SchemaData $schemaData;

	const FIELD_PROPERTIES = [
		'default',
		'field',
		'help',
		'label',
		'placeholder',
		'settings',
	];

	/**
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 * @SuppressWarnings(PHPMD.Superglobals)
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 */
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private CollectionFetcher $collectionFetcher,
		private SchemaFetcher $schemaFetcher,
		private SchemaLister $schemaLister,
		private string $api,
		private string $collection,
		private string $method     = 'post',
		private string $id         = '',
		private string $class      = '',
		private string $helpStyle  = '',
		private string $newAction  = '',
		private string $editAction = '',
		private string $save       = '',
		private string $delete     = '',
		private bool $autosave     = false,
		private bool $helpOnHover  = false,
		private bool $helpOnFocus  = false,
		private bool $hideID       = false,
	) {
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

		$this->route = "/collections/{$this->collection}";

		if (empty($this->id) && isset($_GET['id'])) {
			$this->id = $_GET['id'];
		}
		if (!empty($this->id) && $this->objectFetcher->existsObject($this->collection, $this->id)) {
			// If the form is for editing an existing item, change the method to PUT
			$this->objectData = $this->objectFetcher->fetchObject($this->collection, $this->id);
			$this->method     = 'put';
			$this->route      = "/collections/{$this->collection}/{$this->id}";
		}

		$collectionData   = $this->collectionFetcher->fetchCollection($this->collection);

		if (is_null($collectionData)) {
			throw new \Exception('Collection not found for TotalForm');
		}

		$this->collectionData = $collectionData;
		$this->schemaData     = $this->schemaFetcher->fetchSchema($this->collectionData->schema);
	}

	/** @param array<string,string> $options */
	public function autoBuild(array $options = []): string
	{
		$this->addFieldsFromSchema();

		return $this->build();
	}

	public function build(): string
	{
		$attributes = [
			'class'           => "totalform {$this->class}",
			'data-schema'     => $this->collectionData->schema,
			'data-collection' => $this->collection,
			'data-method'     => $this->method,
			'data-api'        => $this->api,
			'data-route'      => $this->route,
		];

		if (!empty($this->id)) {
			$attributes['data-id'] = $this->id;
		}
		if ($this->newAction) {
			$json = json_encode($this->newAction);
			if ($json) {
				$attributes['data-new-action'] = $json;
			}
		}
		if ($this->editAction) {
			$json = json_encode($this->editAction);
			if ($json) {
				$attributes['data-edit-action'] = $json;
			}
		}

		$content = $this->fieldContent();
		$content .= $this->saveButton();
		$content .= $this->deleteButton();

		return HTMLUtils::createHTMLElement('form', $content, $attributes);
	}

	private function saveButton(): string
	{
		if (empty($this->save)) {
			return '';
		}

		$saveButton = new SaveButton($this->save);

		return $saveButton->build();
	}

	private function deleteButton(): string
	{
		if (empty($this->delete)) {
			return '';
		}

		$deleteButton = new DeleteButton($this->delete);

		return $deleteButton->build();
	}

	private function fieldContent(): string
	{
		if (!isset($this->fields['id'])) {
			// Add the ID field if it does not exist
			$this->addField('id', ["required" => true]);
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
		return array_filter($properties, fn ($key) => in_array($key, self::FIELD_PROPERTIES), ARRAY_FILTER_USE_KEY);
	}

	/** @return array<string,mixed> */
	public function fieldDefaults(string $property): array
	{
		// Get the schema and collection settings for a property
		$schema     = $this->schemaData->properties[$property] ?? [];
		$collection = $this->collectionData->properties[$property] ?? [];

		$defaults = array_merge($schema, $collection);

		return $this->filterFieldProperties($defaults);
	}

	/**
	 * Get the properties for a object from customProperties in the collection meta data
	 *
	 * @return array<string,mixed>
	 * */
	public function objectFieldProperties(string $property): array
	{
		if (empty($this->id)) {
			return [];
		}

		// Get the schema and collection settings for a property
		$properties = $this->collectionData->customProperties[$this->id][$property] ?? [];

		return $this->filterFieldProperties($properties);
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function buildFieldOptions(string $name, array $options = [])
	{
		$defaults = $this->fieldDefaults($name);
		$options  = array_merge($defaults, $options);

		// Set the name of the field
		$options['name'] = $name;

		// Get the value from the object data if it exists
		if (!empty($this->id)) {
			$options = array_merge($options, $this->objectFieldProperties($name));

			if ($name === 'id') {
				$options['value'] = $this->id;
				// Hide the ID field if requested
				if ($this->hideID) $options['field'] = 'hidden';
			}

			if (isset($this->objectData)) {
				$value = $this->objectData->toArray()[$name] ?? '';
				if (!empty($value)) {
					$options['value'] = $value;
				}
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

		$options = $this->buildFieldOptions($name, $options);

		$typeClass = 'TotalCMS\\Domain\\Admin\\FormField\\' . ucfirst($options['field']) . 'Field';
		if (class_exists($typeClass) && is_subclass_of($typeClass, FormField::class)) {
			$this->fields[$name] = new $typeClass(...$options);

			return;
		}
		$this->fields[$name] = new FormField(...$options);
	}

	public function addFieldsFromSchema(): void
	{
		$properties = array_keys($this->schemaData->properties);
		foreach ($properties as $property) {
			$this->addField($property);
		}
	}

	public function __toString()
	{
		return $this->build();
	}
}
