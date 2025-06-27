<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Admin\FormField\DeleteButton;
use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Admin\FormField\SaveButton;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Utils\HTMLUtils;

/**
 * Total Form Builder.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
abstract class TotalForm
{
	/** @var array<string,FormField> */
	protected array $fields = [];
	protected string $route;
	public CollectionData $collectionData;
	public ObjectData $objectData;
	public SchemaData $schemaData;

	public const FIELDS_BY_TYPE = [
		'Text (String) Fields' => [
			'email',
			'hidden',
			'json',
			'phone',
			'radio',
			'select',
			'styledtext',
			'svg',
			'text',
			'textarea',
			'time',
			'url',
		],
		'Boolean Fields' => [
			'checkbox',
			'toggle',
		],
		'Number Fields' => [
			'number',
			'range',
		],
		'Date Fields' => [
			'date',
			'datetime',
		],
		'List (Array) Fields' => [
			'list',
			'multiselect',
		],
		'Special Fields' => [
			'color',
			'deck',
			'depot',
			'file',
			'gallery',
			'id',
			'image',
			'password',
		],
	];

	public const FIELDS = [
		'checkbox',
		'color',
		'date',
		'datetime',
		'deck',
		'depot',
		'email',
		'file',
		'gallery',
		'hidden',
		'id',
		'image',
		'json',
		'list',
		'multiselect',
		'number',
		'password',
		'phone',
		'radio',
		'range',
		'select',
		'styledtext',
		'svg',
		'text',
		'textarea',
		'time',
		'toggle',
		'url',
	];

	public const PROPERTY_FIELDS = [
		'default',
		'field',
		'help',
		'label',
		'placeholder',
		'settings',
		'options',
	];

	// These are settings that can get passed to the FormField class
	public const ATTRIBUTE_SETTINGS = [
		'minlength',
		'maxlength',
		'pattern',
		'min',
		'rows',
		'max',
		'step',
		'size',
		'readonly',
		'disabled',
		'class',
	];

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
	 *
	 * @param array<string,string> $newAction
	 * @param array<string,string> $editAction
	 * @param array<string,string> $deleteAction
	 */
	public function __construct(
		protected ObjectFetcher $objectFetcher,
		protected CollectionFetcher $collectionFetcher,
		protected IndexReader $collectionReader,
		protected SchemaFetcher $schemaFetcher,
		protected SchemaLister $schemaLister,
		public string $api,
		public string $collection,
		public string $id          = '',
		protected string $method      = 'POST',
		protected string $class       = '',
		protected string $buildError  = '',
		protected string $helpStyle   = '',
		protected string $save        = '',
		protected string $delete      = '',
		protected string $formType    = '',
		protected string $schema      = '',
		protected array $newAction    = [],
		protected array $editAction   = [],
		protected array $deleteAction = [],
		protected bool $autosave      = false,
		protected bool $helpOnHover   = false,
		protected bool $helpOnFocus   = false,
		protected bool $hideID        = false,
	) {
		$this->init();
		$this->initClass();
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	protected function init(): void
	{
		if (empty($this->id) && isset($_GET['id'])) {
			$this->id = $_GET['id'];
		}
	}

	protected function initClass(): void
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
		if ($this->method !== 'POST') {
			$this->class .= ' edit-mode';
		}
	}

	public function autoBuild(string $content = ''): string
	{
		$this->addFieldsFromSchema();

		return $this->build($content);
	}

	protected function buildError(): string
	{
		if (empty($this->buildError)) {
			return '';
		}

		return HTMLUtils::element('p', $this->buildError, ['class' => 'cms-twig-error']);
	}

	public function build(string $content = ''): string
	{
		$formgrid = null;
		if (!empty($this->schemaData->formgrid)) {
			$this->class .= ' formgrid';
			$gridBuilder  = new FormGridBuilder($this->schemaData->formgrid);
			$formgrid     = $gridBuilder->toCss();
		}

		$attributes = array_filter([
			'class'           => "totalform {$this->class}",
			'data-form'       => $this->formType,
			'data-schema'     => $this->schema,
			'data-collection' => empty($this->collection) ? null : $this->collection,
			'data-method'     => $this->method,
			'data-api'        => $this->api,
			'data-route'      => $this->route,
			'data-id'         => empty($this->id) ? null : $this->id,
			'style'           => empty($formgrid) ? null : $formgrid,
		]);

		$actions = [
			'newAction'    => 'data-new-action',
			'editAction'   => 'data-edit-action',
			'deleteAction' => 'data-delete-action',
		];
		foreach ($actions as $action => $attribute) {
			if (!empty($this->$action)) {
				$json = json_encode($this->$action);
				if ($json) {
					$attributes[$attribute] = $json;
				}
			}
		}
		$content  = $this->buildError() . $content;
		$content .= $this->fieldContent();

		if (!empty($this->save) || !empty($this->delete)) {
			$save     = $this->saveButton();
			$delete   = $this->deleteButton();
			$content .= HTMLUtils::element('div', $save . $delete, [
				'class' => 'form-inline-fields',
			]);
		}

		return HTMLUtils::element('form', $content, $attributes);
	}

	public function layout2Columns(string $col1, string $col2): string
	{
		$col1   = HTMLUtils::element('section', $col1);
		$col2   = HTMLUtils::element('section', $col2);
		$layout = HTMLUtils::element('div', $col1 . $col2, ['class' => 'form-columns col-2']);

		return $layout;
	}

	public function layoutInline(string $content): string
	{
		$layout = HTMLUtils::element('div', $content, ['class' => 'form-inline-fields']);

		return $layout;
	}

	// Get a list of all values from a property in a collection
	/** @return array<string> */
	public function propertyListForCollection(string $property, string $collection = ''): array
	{
		if (empty($collection)) {
			$collection = $this->collection;
		}

		$collection = $this->collectionReader->fetchIndex($collection);

		// array_filter removes any empty values
		return array_filter($collection->objects->pluck($property)->flatten()->unique()->toArray());
	}

	/**
	 * @param array<string> $properties
	 *
	 * @return array<mixed>
	 */
	public function propertiesForCollection(array $properties, string $collection = ''): array
	{
		if (empty($collection)) {
			$collection = $this->collection;
		}

		$collection = $this->collectionReader->fetchIndex($collection);

		$filteredArray = $collection->objects->map(function ($item) use ($properties) {
			return collect($item)->only($properties)->toArray();
		})->toArray();

		return $filteredArray;
	}

	private function saveButton(): string
	{
		if (empty($this->save)) {
			return '';
		}
		$button = new SaveButton($this->save);

		return $button->build();
	}

	private function deleteButton(): string
	{
		if (empty($this->delete)) {
			return '';
		}
		$button = new DeleteButton($this->delete);

		return $button->build();
	}

	protected function fieldContent(): string
	{
		if (!empty($this->fields) && !isset($this->fields['id'])) {
			// Add the ID field if it does not exist
			$this->addField('id', ['required' => true]);
		}

		$content = '';

		// If using formgrid, inject section headers and dividers
		if (!empty($this->schemaData->formgrid)) {
			$gridBuilder = new FormGridBuilder($this->schemaData->formgrid);
			$content .= $gridBuilder->buildSectionHtml();
		}

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
	public static function filterFieldProperties(array $properties): array
	{
		// Remove any keys that are not needed for the field
		// Since PHP will unknown named parameters
		return array_filter($properties, fn ($key) => in_array($key, self::PROPERTY_FIELDS), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * @param array<string,mixed> $attributes
	 *
	 * @return array<string,mixed>
	 */
	public static function filterFieldAttributes(array $attributes): array
	{
		// Remove any keys that are not needed for the field
		// Since PHP will unknown named parameters
		return array_filter($attributes, fn ($key) => in_array($key, self::ATTRIBUTE_SETTINGS), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * @param array<string,mixed> $properties
	 *
	 * @return array<string,mixed>
	 */
	public static function filterExtraFields(array $properties): array
	{
		// Remove any keys that are not needed for the field
		// Since PHP will unknown named parameters
		return array_filter($properties, fn ($key) => !in_array($key, TotalForm::PROPERTY_FIELDS), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	protected function buildFieldOptions(string $name, array $options = [])
	{
		return $options;
	}

	/** @param array<string,mixed> $options */
	public function addField(string $name, array $options = []): void
	{
		if (!isset($this->schemaData->properties[$name])) {
			// Field '{$name}' not found in schema
			return;
		}

		$this->fields[$name] = $this->createDynamicField($name, $options);
	}

	/** @param array<string,mixed> $options */
	public function field(string $name, array $options = []): string
	{
		$field = $this->createDynamicField($name, $options);

		return $field->build();
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

	private function addFieldsFromSchema(): void
	{
		$properties = array_keys($this->schemaData->properties);
		foreach ($properties as $property) {
			$this->addField($property);
		}
	}

	/** @return array<string,mixed> */
	public function propertiesForSchema(): array
	{
		// A new collection form will not have a schema yet
		if (!isset($this->collectionData)) {
			return [];
		}
		$schema     = $this->collectionData->schema;
		$schemaData = $this->schemaFetcher->fetchSchema($schema);
		$properties = $schemaData->properties;
		foreach ($properties as $property => $options) {
			$properties[$property] = self::filterFieldProperties($options);
		}

		return $properties;
	}

	public function __toString()
	{
		return $this->build();
	}
}
