<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Admin\FormField\DeleteButton;
use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Admin\FormField\SaveButton;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\DataView\Service\DataViewFilter;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Support\Config;

/**
 * Total Form Builder.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class TotalForm implements \Stringable
{
	/** @var array<string,FormField> */
	protected array $fields                = [];
	public ?CollectionData $collectionData = null;
	public ?ObjectData $objectData         = null;
	public ?SchemaData $schemaData         = null;
	public bool $isDuplicate               = false;

	public const FIELDS_BY_TYPE = [
		'Text (String) Fields' => [
			'code',
			'email',
			'hidden',
			'json',
			'phone',
			'radio',
			'secret',
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
			'price',
			'range',
		],
		'Date Fields' => [
			'date',
			'datetime',
		],
		'List (Array) Fields' => [
			'list',
			'multicheckbox',
			'multiselect',
		],
		'Special Fields' => [
			'color',
			'deck',
			'deckTable',
			'depot',
			'file',
			'gallery',
			'id',
			'image',
			'password',
		],
	];

	/** @var array<string,class-string> Extension-registered field types: name => FQCN */
	private static array $extensionFieldTypes = [];

	/**
	 * Register extension field types for both the form builder and schema property editor.
	 *
	 * @param array<string,class-string> $types Field name => fully qualified class name
	 */
	public static function registerExtensionFieldTypes(array $types): void
	{
		self::$extensionFieldTypes = array_merge(self::$extensionFieldTypes, $types);
	}

	/**
	 * Get the extension field type class map.
	 *
	 * @return array<string,class-string>
	 */
	public static function getExtensionFieldTypes(): array
	{
		return self::$extensionFieldTypes;
	}

	/**
	 * Get all field types grouped by category, including extension types.
	 *
	 * @return array<string,list<string>>
	 */
	public static function getFieldsByType(): array
	{
		$fields = self::FIELDS_BY_TYPE;

		if (self::$extensionFieldTypes !== []) {
			$fields['Extension Fields'] = array_values(array_unique(array_keys(self::$extensionFieldTypes)));
		}

		return $fields;
	}

	public const FIELDS = [
		'checkbox',
		'code',
		'color',
		'date',
		'datetime',
		'deck',
		'deckTable',
		'depot',
		'email',
		'file',
		'gallery',
		'hidden',
		'id',
		'image',
		'json',
		'list',
		'multicheckbox',
		'multiselect',
		'number',
		'password',
		'phone',
		'price',
		'radio',
		'range',
		'secret',
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
		'hide',
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
	 * @param array<int,array<string,mixed>> $newActions Array of action objects
	 * @param array<int,array<string,mixed>> $editActions Array of action objects
	 * @param array<int,array<string,mixed>> $deleteActions Array of action objects
	 * @param array<string,mixed> $data Duplicate data for prefilling form
	 */
	public function __construct(
		protected ObjectFetcher $objectFetcher,
		protected CollectionFetcher $collectionFetcher,
		protected CollectionLister $collectionLister,
		protected IndexReader $collectionReader,
		protected IndexFilter $indexFilter,
		protected SchemaFetcher $schemaFetcher,
		protected SchemaLister $schemaLister,
		protected AccessGroupLister $accessGroupLister,
		protected CollectionEditionService $collectionEditionService,
		protected EditionFeatureService $editionFeatures,
		protected DataViewFilter $dataViewFilter,
		protected CSRFTokenManager $csrfManager,
		protected Config $config,
		protected PropertyMetaResolver $metaResolver,
		public string $api,
		public string $collection             = '',
		public string $id                     = '',
		protected string $method                 = 'POST',
		protected string $class                  = '',
		protected string $buildError             = '',
		protected string $helpStyle              = '',
		protected string $save                   = '',
		protected string $delete                 = '',
		protected string $formType               = '',
		protected string $schema                 = '',
		protected string $route                  = '',
		protected array $newActions              = [],
		protected array $editActions             = [],
		protected array $deleteActions           = [],
		protected array $data                    = [],
		protected bool $autosave                 = false,
		protected bool $helpOnHover              = false,
		protected bool $helpOnFocus              = false,
		protected bool $hideID                   = false,
		protected bool $useFormGrid              = true,
		protected bool $addOnly                  = false,
	) {
		$this->init();
		$this->initClass();
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	protected function init(): void
	{
		// For addOnly forms, never allow editing existing objects - ignore any ID parameter
		if ($this->addOnly) {
			$this->id = '';
		} elseif ($this->id === '' && isset($_GET['id'])) {
			$this->id = $_GET['id'];
		}
	}

	public function getSchemaFetcher(): SchemaFetcher
	{
		return $this->schemaFetcher;
	}

	public function getMetaResolver(): PropertyMetaResolver
	{
		return $this->metaResolver;
	}

	protected function initClass(): void
	{
		if ($this->autosave) {
			$this->class .= ' autosave';
		}
		if ($this->helpOnHover) {
			$this->class .= ' help-on-hover';
		}
		if ($this->helpOnFocus) {
			$this->class .= ' help-on-focus';
		}
		if ($this->helpStyle !== '') {
			$this->class .= " help-{$this->helpStyle}";
		}
		if ($this->isEditMode()) {
			$this->class .= ' edit-mode';
		}
	}

	/**
	 * Filter form actions based on edition.
	 * - mailer actions require Standard edition
	 * - webhook actions require Pro edition.
	 *
	 * @param array<int,array<string,mixed>> $actions
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function filterActionsByEdition(array $actions): array
	{
		if ($actions === []) {
			return [];
		}

		return array_values(array_filter($actions, function (array $action): bool {
			$actionType = $action['action'] ?? '';

			return match ($actionType) {
				'mailer'   => $this->editionFeatures->can(EditionFeature::MAILER_ACTIONS),
				'webhook'  => $this->editionFeatures->can(EditionFeature::WEBHOOK_ACTIONS),
				'pushover' => $this->editionFeatures->can(EditionFeature::PUSHOVER_ACTIONS),
				default    => true, // Allow unknown actions through
			};
		}));
	}

	public function isEditMode(): bool
	{
		return strtoupper($this->method) !== 'POST' && $this->id !== '';
	}

	public function getFormType(): string
	{
		return $this->formType;
	}

	public function autoBuild(string $content = ''): string
	{
		$this->addFieldsFromSchema();

		return $this->build($content);
	}

	protected function buildError(): string
	{
		if ($this->buildError === '') {
			return '';
		}

		return HTMLUtils::element('p', $this->buildError, ['class' => 'cms-twig-error']);
	}

	public function build(string $content = '', string $contentAfter = ''): string
	{
		$formId       = null;
		$formStyleTag = '';

		if ($this->schemaData instanceof SchemaData && $this->useFormGrid) {
			$formgrid = $this->schemaData->formgrid;

			// Generate a default formgrid from field names if none defined
			if ($formgrid === '') {
				$formgrid = implode("\n", array_keys($this->fields));
			}

			$this->class .= ' formgrid';
			$formId       = 'form-' . bin2hex(random_bytes(8));
			$gridBuilder  = new FormGridBuilder($formgrid);
			$gridBuilder->ensureFieldsIncluded(array_keys($this->fields));
			$formStyleTag = $gridBuilder->toStyleTag($formId);
		}

		$attributes = array_filter([
			'id'                    => $formId,
			'class'                 => "totalform {$this->class}",
			'data-form'             => $this->formType,
			'data-schema'           => $this->schema,
			'data-collection'       => $this->collection === '' ? null : $this->collection,
			'data-collection-count' => $this->collectionData instanceof CollectionData ? $this->collectionData->count : null,
			'data-method'           => $this->method,
			'data-api'              => $this->api,
			'data-route'            => $this->route,
			'data-id'               => $this->id === '' ? null : $this->id,
		]);

		$actions = [
			'newActions'    => 'data-new-actions',
			'editActions'   => 'data-edit-actions',
			'deleteActions' => 'data-delete-actions',
		];
		foreach ($actions as $action => $attribute) {
			$filteredActions = $this->filterActionsByEdition($this->$action);
			if ($filteredActions !== []) {
				$json = json_encode($filteredActions);
				if ($json) {
					$attributes[$attribute] = $json;
				}
			}
		}

		// Add CSRF token if manager is available and method requires protection
		$csrfField = '';
		if (in_array(strtoupper($this->method), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
			$csrfField = $this->csrfManager->getTokenField();
		}

		$content  = $this->buildError() . $csrfField . $content;
		$content .= $this->fieldContent();

		$save     = $this->saveButton();
		$delete   = $this->deleteButton();
		$content .= HTMLUtils::element('div', $save . $delete, [
			'class' => 'form-inline-fields',
		]);

		$form = HTMLUtils::element('form', $content, $attributes);

		// Wrap in container div for container queries (formgrid responsive layout)
		if ($formId !== null) {
			$form = HTMLUtils::element('div', $form, ['id' => $formId . '-container']);
		}

		return $formStyleTag . $form . $contentAfter;
	}

	public function layout2Columns(string $col1, string $col2): string
	{
		$col1   = HTMLUtils::element('section', $col1);
		$col2   = HTMLUtils::element('section', $col2);

		return HTMLUtils::element('div', $col1 . $col2, ['class' => 'form-columns col-2']);
	}

	public function layoutInline(string $content): string
	{
		return HTMLUtils::element('div', $content, ['class' => 'form-inline-fields']);
	}

	// Get a list of all values from a property in a collection
	/** @return array<string> */
	public function propertyListForCollection(string $property, string $collection = ''): array
	{
		if ($collection === '') {
			$collection = $this->collection;
		}

		$collection = $this->collectionReader->fetchIndex($collection);

		// array_filter removes any empty values
		return array_filter($collection->objects->pluck($property)->flatten()->unique()->toArray());
	}

	/**
	 * Get a list of unique category values from all collections.
	 * Used for propertyOptions: "collections" in schema settings.
	 *
	 * @return array<string>
	 */
	public function categoryListForCollections(): array
	{
		return $this->collectionLister->listCategories();
	}

	/**
	 * Get a list of unique category values from all schemas.
	 * Used for propertyOptions: "schemas" in schema settings.
	 *
	 * @return array<string>
	 */
	public function categoryListForSchemas(): array
	{
		return $this->schemaLister->listCategories();
	}

	/**
	 * Get a list of all collection IDs.
	 * Used for propertyOptions: "collectionIds" in schema settings.
	 *
	 * @return array<string>
	 */
	public function collectionIdList(): array
	{
		$collections = $this->collectionLister->listAllCollections();

		return array_map(fn (CollectionData $c): string => $c->id, $collections);
	}

	protected ?TemplateLister $templateLister = null;

	public function setTemplateLister(TemplateLister $lister): void
	{
		$this->templateLister = $lister;
	}

	/**
	 * Get a list of available layout templates from the builder/layouts/ directory.
	 * Used for propertyOptions: "layouts" in schema settings.
	 *
	 * @return array<string>
	 */
	public function layoutListForBuilder(): array
	{
		if (!$this->templateLister instanceof TemplateLister) {
			return [];
		}

		return $this->templateLister->listBuilderTemplates('layouts', true);
	}

	/**
	 * Get a list of available page templates from the builder/pages/ directory.
	 * Used for propertyOptions: "pages" in schema settings.
	 *
	 * @return array<string>
	 */
	public function pageListForBuilder(): array
	{
		if (!$this->templateLister instanceof TemplateLister) {
			return [];
		}

		return $this->templateLister->listBuilderTemplates('pages', true);
	}

	/**
	 * Get properties from collection objects with optional filtering.
	 *
	 * @param array<string>        $properties Properties to fetch
	 * @param string               $collection Collection name (defaults to current collection)
	 * @param array<string,string> $filters    Optional include/exclude filters
	 *
	 * @return array<mixed>
	 */
	public function propertiesForCollection(array $properties, string $collection = '', array $filters = []): array
	{
		if ($collection === '') {
			$collection = $this->collection;
		}

		// If filters are provided, use IndexFilter to get filtered objects
		if ($filters !== []) {
			$objects = $this->indexFilter->fetchFilteredIndex($collection, $filters);
		} else {
			// No filters, fetch all objects
			$index   = $this->collectionReader->fetchIndex($collection);
			$objects = $index->objects->toArray();
		}

		// Extract only the requested properties from each object
		/** @phpstan-ignore-next-line argument.templateType */
		return array_map(fn ($item) => collect($item)->only($properties)->toArray(), $objects);
	}

	/**
	 * Get properties from DataView data with optional filtering.
	 *
	 * @param array<string>        $properties Properties to fetch
	 * @param string               $viewId     DataView ID
	 * @param array<string,string> $filters    Optional include/exclude filters
	 *
	 * @return array<mixed>
	 */
	public function propertiesForView(array $properties, string $viewId, array $filters = []): array
	{
		$data = $this->dataViewFilter->fetchFilteredViewData($viewId, $filters);

		/** @phpstan-ignore-next-line argument.templateType */
		return array_map(fn ($item) => collect($item)->only($properties)->toArray(), $data);
	}

	/**
	 * Get access group options for form fields.
	 *
	 * @return array<array<string,string>>
	 */
	public function accessGroupOptionsForField(): array
	{
		$groups = $this->accessGroupLister->listAll();

		return array_map(fn (AccessGroupData $group): array => [
			'value' => $group->id,
			'label' => $group->id,
		], $groups);
	}

	/**
	 * Get a field value from the form's object data or field defaults.
	 * Used for visibility condition evaluation.
	 */
	public function getFieldValue(string $fieldName): mixed
	{
		// First check if we have object data (edit mode)
		if ($this->objectData instanceof ObjectData) {
			return $this->objectData->$fieldName ?? null;
		}

		// For new forms, check if the field has been built with a default value
		if (isset($this->fields[$fieldName])) {
			return $this->fields[$fieldName]->getValue();
		}

		// Field not found or not built yet
		return null;
	}

	private function saveButton(): string
	{
		if ($this->save === '') {
			return '';
		}
		$button = new SaveButton($this->save);

		return $button->build();
	}

	private function deleteButton(): string
	{
		if ($this->delete === '') {
			return '';
		}
		$button = new DeleteButton($this->delete);

		return $button->build();
	}

	protected function fieldContent(): string
	{
		if ($this->fields !== [] && !isset($this->fields['id'])) {
			// Check if we should skip ID field for AddOnly forms with autogen
			$skipIdField = false;
			if ($this->addOnly && isset($this->schemaData->properties['id'])) {
				$settings = $this->schemaData->properties['id']['settings'] ?? [];
				if (isset($settings['autogen']) && !empty($settings['autogen'])) {
					$skipIdField = true;
				}
			}

			// Add the ID field if it does not exist (unless it should be skipped)
			if (!$skipIdField) {
				$this->addField('id', ['required' => true]);
			}
		}

		$content = '';

		// If using formgrid, inject section headers and dividers
		if ($this->schemaData instanceof SchemaData && $this->schemaData->formgrid !== '' && $this->useFormGrid) {
			$gridBuilder = new FormGridBuilder($this->schemaData->formgrid);
			$content .= $gridBuilder->buildGridSectionHtml();
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
		return array_filter($properties, fn ($key): bool => in_array($key, self::PROPERTY_FIELDS), ARRAY_FILTER_USE_KEY);
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
		return array_filter($attributes, fn ($key): bool => in_array($key, self::ATTRIBUTE_SETTINGS), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * Extract field attributes from schema settings.
	 *
	 * @return array<string,mixed>
	 */
	protected function fieldAttributeSettings(string $property): array
	{
		// Get the schema settings for a property
		$schema = $this->schemaData->properties[$property]['settings'] ?? [];

		return self::filterFieldAttributes($schema);
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
		return array_filter($properties, fn ($key): bool => !in_array($key, TotalForm::PROPERTY_FIELDS), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	protected function buildFieldOptions(string $name, array $options = []): array
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

	/**
	 * Render a field as a sub-field of a composite property (file, image, depot,
	 * gallery, deck-table, etc.). Skips parent-object schema inheritance so that
	 * a sub-field with a name matching a top-level property (e.g. `name`, `alt`,
	 * `tags`) does not pick up that property's settings/options.
	 *
	 * @param array<string,mixed> $options
	 */
	public function subField(string $name, array $options = []): string
	{
		$options['subfield'] = true;

		return $this->field($name, $options);
	}

	/** @param array<string,mixed> $options */
	private function createDynamicField(string $name, array $options = []): FormField
	{
		$options = $this->buildFieldOptions($name, $options);

		// Remove context flags that aren't constructor parameters
		// Deck uses deck_context to determine if it is a deck field for
		// buildFieldOptions in an ObjectForm
		unset($options['deck_context']);
		unset($options['subfield']);

		$fieldType    = $options['field'] ?? '';
		$builtInClass = 'TotalCMS\\Domain\\Admin\\FormField\\' . ucfirst($fieldType) . 'Field';
		$typeClass    = (class_exists($builtInClass) && is_subclass_of($builtInClass, FormField::class))
			? $builtInClass
			: (self::getExtensionFieldTypes()[$fieldType] ?? $builtInClass);

		if (!class_exists($typeClass) || !is_subclass_of($typeClass, FormField::class)) {
			$typeClass = FormField::class;
		}

		// Strip keys that aren't valid constructor parameters to avoid errors
		// from schema-only keys (e.g. $ref, factory, type) leaking through
		$options = $this->filterConstructorParams($typeClass, $options);

		return new $typeClass(...$options);
	}

	/**
	 * Filter an options array to only keys that match constructor parameter names.
	 *
	 * @param class-string $class
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	private function filterConstructorParams(string $class, array $options): array
	{
		/** @var array<string, array<string, true>> */
		static $cache = [];

		if (!isset($cache[$class])) {
			$ref           = new \ReflectionClass($class);
			$constructor   = $ref->getConstructor();
			$cache[$class] = [];
			if ($constructor !== null) {
				foreach ($constructor->getParameters() as $param) {
					$cache[$class][$param->getName()] = true;
				}
			}
		}

		return array_intersect_key($options, $cache[$class]);
	}

	private function addFieldsFromSchema(): void
	{
		if (!$this->schemaData instanceof SchemaData) {
			return;
		}

		$properties = array_keys($this->schemaData->properties);
		foreach ($properties as $property) {
			// Skip ID field in AddOnly forms if it has autogen configured
			if ($property === 'id' && $this->addOnly) {
				$settings = $this->schemaData->properties[$property]['settings'] ?? [];
				if (isset($settings['autogen']) && !empty($settings['autogen'])) {
					continue; // Skip this field - ID will be auto-generated
				}
			}

			$this->addField($property);
		}
	}

	/** @return array<string,mixed> */
	public function propertiesForSchema(): array
	{
		// A new collection form will not have a schema yet
		if (!$this->collectionData instanceof CollectionData) {
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

	public function __toString(): string
	{
		return $this->build();
	}
}
