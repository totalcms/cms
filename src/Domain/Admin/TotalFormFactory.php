<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Admin\FormField\DeleteButton;
use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Admin\FormField\SaveButton;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Support\Config;

/**
 * Total Form Builder.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 *
 * This class is a factory for creating TotalForm objects.
 * I cannot use Dependency Injection in a non-constructor, so I need to create a factory class
 * This encapsulates the creation of the TotalForm object without depencency injection here.
 */
readonly class TotalFormFactory
{
	private string $api;

	public function __construct(
		private Config $config,
		private ObjectFetcher $objectFetcher,
		private CollectionFetcher $collectionFetcher,
		private CollectionLister $collectionLister,
		private IndexReader $collectionReader,
		private SchemaFetcher $schemaFetcher,
		private SchemaLister $schemaLister,
		private SchemaFactory $schemaFactory,
		private TemplateRepository $templateRepository,
		private CSRFTokenManager $csrfManager,
		private SettingsSchemaFetcher $settingsSchemaFetcher,
		private SettingsFetcher $settingsFetcher,
	) {
		$this->api = $this->config->api;
	}

	/** @param array<string,mixed> $options */
	public function simple(string $route, string $content = '', array $options = []): string
	{
		$options['api']         = $this->api;
		$options['route']       = $route;
		$options['csrfManager'] = $this->csrfManager;

		// options: method, label, refresh

		$form = new SimpleForm(...$options);

		return $form->build($content);
	}

	/** @param array<string,mixed> $options */
	public function factory(string $collection, array $options = []): string
	{
		$options['api']        = $this->api;
		$options['collection'] = $collection;

		$form = new FactoryForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function importCollection(string $collection, array $options = []): string
	{
		$options['api']        = $this->api;
		$options['collection'] = $collection;

		$form = new ImportCollectionForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function importSchema(array $options = []): string
	{
		$options['api'] = $this->api;

		$form = new ImportSchemaForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function importJumpStart(array $options = []): string
	{
		$options['api'] = $this->api;

		$form = new ImportJumpStartForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function jobqueueStats(array $options = []): string
	{
		$options['api'] = $this->api;

		$stats = new JobQueueStats(...$options);

		return $stats->allStats();
	}

	/** @param array<string,mixed> $options */
	public function jobqueueByStatus(array $options = []): string
	{
		$options['api'] = $this->api;

		$header = $options['header'] ?? null;
		unset($options['header']);

		$stats = new JobQueueStats(...$options);

		return $stats->tableByStatus($header);
	}

	/** @param array<string,mixed> $options */
	public function jobqueueByType(array $options = []): string
	{
		$options['api'] = $this->api;

		$header = $options['header'] ?? null;
		unset($options['header']);

		$stats = new JobQueueStats(...$options);

		return $stats->tableByType($header);
	}

	/** @param array<string,mixed> $options */
	public function clearqueue(array $options = []): string
	{
		$options['api'] = $this->api;

		$form = new JobQueueForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function devmode(array $options = []): string
	{
		$devModeManager = new DevModeManager();
		$devModeStatus  = $devModeManager->getDevModeStatus();

		$options = array_merge([
			'form'  => $this->dummyForm(),
			'field' => 'toggle',
			'label' => 'Development Mode',
			'help'  => $devModeStatus['enabled']
				? sprintf('<strong>Development mode is active.</strong> Remaining time: <span id="devmode-countdown">%s</span>', $devModeStatus['remaining_formatted'])
				: 'Development mode is disabled. Caching is active.',
		], $options);

		// Add JavaScript variable for remaining seconds
		$jsVariable = sprintf(
			'<script>globalThis.DEVMODE_REMAINING_SECONDS = %d;</script>',
			$devModeStatus['remaining_seconds']
		);

		// Generate the field using the existing field system
		$fieldHtml = $this->field('toggle', 'devmode', $options);

		// Add the API endpoint attribute and checked state
		$fieldHtml = str_replace(
			'type="checkbox"',
			sprintf(
				'type="checkbox" %s data-api="%s"',
				$devModeStatus['enabled'] ? 'checked' : '',
				$this->api
			),
			$fieldHtml
		);

		return $jsVariable . $fieldHtml;
	}

	/** @param array<string,mixed> $options */
	public function schema(array $options = []): string
	{
		$options = array_merge([
			'id'         => '',
			'collection' => '',
		], $options, [
			// These options cannot be overridden
			'api'               => $this->api,
			'objectFetcher'     => $this->objectFetcher,
			'collectionFetcher' => $this->collectionFetcher,
			'collectionReader'  => $this->collectionReader,
			'schemaFetcher'     => $this->schemaFetcher,
			'schemaLister'      => $this->schemaLister,
			'schemaFactory'     => $this->schemaFactory,
		]);

		$form = new SchemaForm(...$options);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function template(array $options = []): string
	{
		$options = array_merge([
			'path'       => '',
			'collection' => '',
			'id'         => '',
		], $options, [
			// These options cannot be overridden
			'api'                => $this->api,
			'objectFetcher'      => $this->objectFetcher,
			'collectionFetcher'  => $this->collectionFetcher,
			'collectionReader'   => $this->collectionReader,
			'schemaFetcher'      => $this->schemaFetcher,
			'schemaLister'       => $this->schemaLister,
			'templateRepository' => $this->templateRepository,
		]);

		$form = new TemplateForm(...$options);

		return $form->autoBuild();
	}

	public function collectionTable(string $collection): string
	{
		$options = [
			'config'            => $this->config,
			'collectionFetcher' => $this->collectionFetcher,
			'collectionLister'  => $this->collectionLister,
			'schemaFetcher'     => $this->schemaFetcher,
			'collectionReader'  => $this->collectionReader,
			'api'               => $this->api,
			'collection'        => $collection,
		];

		$table = new CollectionTable(...$options);

		return $table->build();
	}

	/** @param array<string,mixed> $options */
	public function playground(string $id = '', array $options = []): string
	{
		$options = array_merge([
			'save'   => 'Save',
			'delete' => 'Delete',
		], $options);
		$options['id'] = $id;

		$form = $this->builder('playground', $options);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function collection(array $options = []): string
	{
		$options = array_merge([
			'id'         => '',
			'collection' => '',
		], $options, [
			// These options cannot be overridden
			'api'               => $this->api,
			'objectFetcher'     => $this->objectFetcher,
			'collectionFetcher' => $this->collectionFetcher,
			'collectionReader'  => $this->collectionReader,
			'schemaFetcher'     => $this->schemaFetcher,
			'schemaLister'      => $this->schemaLister,
		]);

		$form = new CollectionForm(...$options);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function builder(string $collection, array $options = []): ObjectForm
	{
		$options = array_merge($options, [
			// These options cannot be overridden
			'collection'        => $collection,
			'api'               => $this->api,
			'collectionFetcher' => $this->collectionFetcher,
			'collectionReader'  => $this->collectionReader,
			'objectFetcher'     => $this->objectFetcher,
			'schemaFetcher'     => $this->schemaFetcher,
			'schemaLister'      => $this->schemaLister,
		]);

		return new ObjectForm(...$options);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	private function singleFieldFormBuilder(
		string $id,
		string $defaultCollection,
		string $property,
		string $field,
		array $formOptions = [],
		array $fieldOptions = [],
	): string {
		$formOptions = array_merge([
			'collection' => $defaultCollection,
			'hideID'     => true,
			'id'         => $id,
		], $formOptions);

		$class                = $formOptions['class'] ?? ' custom-layout';
		$formOptions['class'] = $class;

		$collection = $formOptions['collection'];
		unset($formOptions['collection']);

		$fieldOptions['field'] = $field;

		$form = $this->builder($collection, $formOptions);

		$form->addField('id');
		$form->addField($property, $fieldOptions);

		return $form->build();
	}

	public function save(string $label = 'Save'): string
	{
		$button = new SaveButton($label);

		return $button->build();
	}

	public function delete(string $label = 'Delete'): string
	{
		$button = new DeleteButton($label);

		return $button->build();
	}

	/**
	 * Generate a settings form for a specific section.
	 *
	 * @param array<string,mixed> $options
	 */
	public function settings(string $section, array $options = []): string
	{
		// Load schema and data using injected services
		$schema      = $this->settingsSchemaFetcher->getSchema($section);
		$sectionData = $this->settingsFetcher->loadSection($section);
		$defaults    = require __DIR__ . '/../../../config/defaults.php';
		$timezones   = $options['timezones'] ?? timezone_identifiers_list();

		if ($schema === null || !isset($schema['properties']) || !is_array($schema['properties'])) {
			return '<p class="error">Schema not found for this settings section.</p>';
		}

		$formfields = '';

		foreach ($schema['properties'] as $fieldName => $fieldSchema) {
			// Get current value with priority: sectionData > defaults > schema default > empty string
			$currentValue = '';
			if (isset($fieldSchema['default'])) {
				$currentValue = $fieldSchema['default'];
			}
			// General settings are stored at top level in defaults, not under 'general' key
			if ($section === 'general' && isset($defaults[$fieldName])) {
				$currentValue = $defaults[$fieldName];
			} elseif (isset($defaults[$section][$fieldName])) {
				$currentValue = $defaults[$section][$fieldName];
			}
			if (isset($sectionData[$fieldName])) {
				$currentValue = $sectionData[$fieldName];
			}

			// Special handling for JSON fields - convert arrays to JSON strings for display
			if ($fieldSchema['type'] === 'json' && is_array($currentValue)) {
				$currentValue = json_encode($currentValue, JSON_PRETTY_PRINT);
			}

			// Build field options
			$fieldOptions = [
				'field'       => $fieldSchema['type'],
				'label'       => $fieldSchema['label'] ?? '',
				'help'        => $fieldSchema['help'] ?? '',
				'placeholder' => $fieldSchema['placeholder'] ?? '',
				'value'       => $currentValue,
				'required'    => $fieldSchema['required'] ?? false,
				'min'         => $fieldSchema['min'] ?? null,
				'max'         => $fieldSchema['max'] ?? null,
				'settings'    => $fieldSchema['settings'] ?? [],
			];

			// Special handling for select fields with options
			if (isset($fieldSchema['options'])) {
				$fieldOptions['options'] = $fieldSchema['options'];
			}

			// Special handling for timezone field
			if (isset($fieldSchema['settings']['timezoneOptions']) && $fieldSchema['settings']['timezoneOptions']) {
				$timezoneOptions = [];
				foreach ($timezones as $tz) {
					$timezoneOptions[] = ['value' => $tz, 'label' => $tz];
				}
				$fieldOptions['options'] = $timezoneOptions;
			}

			$formfields .= $this->field($fieldSchema['type'], $fieldName, $fieldOptions);
		}

		return $this->simple('/admin/settings/' . $section, $formfields, [
			'method'      => 'POST',
			'label'       => 'Save Settings',
			'refresh'     => true,
			'class'       => 'help-on-hover help-box',
			'buttonClass' => 'cms-save',
		]);
	}

	/**
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 *
	 * @param array<string,mixed> $options
	 */
	public function blog(array $options = []): string
	{
		$options = array_merge([
			'collection' => 'blog',
			'save'       => 'Save',
			'delete'     => 'Delete',
			'fields'     => [],
		], $options);

		$class            = $options['class'] ?? ' custom-layout';
		$options['class'] = $class;

		$fields = array_merge([
			'date'       => true,
			'summary'    => true,
			'content'    => true,
			'author'     => true,
			'tags'       => true,
			'featured'   => true,
			'draft'      => true,
			'image'      => true,
			'categories' => false,
			'extra'      => false,
			'extra2'     => false,
			'media'      => false,
			'genre'      => false,
			'labels'     => false,
			'archived'   => false,
			'gallery'    => false,
		], $options['fields']);
		// remove fields from options since it's not a valid option for TotalForm
		unset($options['fields']);

		$form = $this->builder($options['collection'], $options);

		$col1  = $form->field('id');
		$col1 .= $form->field('created', ['field' => 'hidden']);
		$col1 .= $form->field('updated', ['field' => 'hidden']);
		$col1 .= $form->field('title');
		if ($fields['date']) {
			$col1 .= $form->field('date');
		}
		if ($fields['media']) {
			$col1 .= $form->field('media', ['field' => 'url']);
		}
		if ($fields['summary']) {
			$col1 .= $form->field('summary', ['field' => 'styledtext']);
		}
		if ($fields['content']) {
			$col1 .= $form->field('content', ['field' => 'styledtext']);
		}
		if ($fields['extra']) {
			$col1 .= $form->field('extra', ['field' => 'styledtext']);
		}
		if ($fields['extra2']) {
			$col1 .= $form->field('extra2', ['field' => 'styledtext']);
		}

		$col2 = '';
		if ($fields['author']) {
			$col2 .= $form->field('author');
		}
		if ($fields['genre']) {
			$col2 .= $form->field('genre');
		}
		if ($fields['tags']) {
			$col2 .= $form->field('tags', ['field' => 'list']);
		}
		if ($fields['categories']) {
			$col2 .= $form->field('categories', ['field' => 'list']);
		}
		if ($fields['labels']) {
			$col2 .= $form->field('labels', ['field' => 'list']);
		}

		$inline = '';
		if ($fields['featured']) {
			$inline .= $form->field('featured', ['field' => 'toggle', 'help' => false]);
		}
		if ($fields['draft']) {
			$inline .= $form->field('draft', ['field' => 'toggle', 'help' => false]);
		}
		if ($fields['archived']) {
			$inline .= $form->field('archived', ['field' => 'toggle', 'help' => false]);
		}
		$col2 .= $form->layoutInline($inline);

		if ($fields['image']) {
			$col2 .= $form->field('image');
		}
		if ($fields['gallery']) {
			$col2 .= $form->field('gallery');
		}

		$layout = $form->layout2Columns($col1, $col2);

		return $form->build($layout);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function checkbox(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		$formOptions['autosave'] = true;

		return $this->singleFieldFormBuilder($id, 'toggle', 'status', 'checkbox', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function color(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'color', 'color', 'color', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function date(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'date', 'date', 'date', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function datetime(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'date', 'date', 'datetime', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function email(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'email', 'email', 'email', $formOptions, $fieldOptions);
	}

	/** @param array<string,mixed> $options */
	public function feed(array $options = []): string
	{
		$options = array_merge([
			'collection' => 'feed',
			'save'       => 'Save',
			'delete'     => 'Delete',
		], $options);

		$class            = $options['class'] ?? ' custom-layout';
		$options['class'] = $class;

		$form = $this->builder($options['collection'], $options);

		$top = $form->field('id', ['class' => 'hidden-field']);
		$top .= $form->field('created', ['field' => 'hidden']);
		$top .= $form->field('updated', ['field' => 'hidden']);

		$col1  = $form->field('title');
		$col1 .= $form->field('content', ['field' => 'styledtext']);

		$col2  = $form->field('image');
		$col2 .= $form->field('featured', ['help' => false, 'field' => 'toggle']);

		$layout = $form->layout2Columns($col1, $col2);

		return $form->build($top . $layout);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function gallery(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'gallery', 'gallery', 'gallery', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function image(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'image', 'image', 'image', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function file(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'file', 'file', 'file', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function depot(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'depot', 'depot', 'depot', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function number(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'number', 'number', 'number', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function price(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'number', 'number', 'price', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function range(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'number', 'number', 'range', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function select(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'text', 'text', 'select', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function styledtext(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'styledtext', 'styledtext', 'styledtext', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function svg(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'svg', 'svg', 'svg', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function text(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'text', 'text', 'text', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function textarea(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'text', 'text', 'textarea', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function toggle(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		$formOptions['autosave'] = true;

		return $this->singleFieldFormBuilder($id, 'toggle', 'status', 'toggle', $formOptions, $fieldOptions);
	}

	/**
	 * @param array<string,mixed> $formOptions
	 * @param array<string,mixed> $fieldOptions
	 */
	public function url(string $id, array $formOptions = [], array $fieldOptions = []): string
	{
		return $this->singleFieldFormBuilder($id, 'url', 'url', 'url', $formOptions, $fieldOptions);
	}

	private function dummyForm(): TotalForm
	{
		// This is a dummy form to satisfy the type hinting in the field method.
		// It will not be used, but it is required to create a FormField instance.
		return new ObjectForm(
			objectFetcher     : $this->objectFetcher,
			collectionFetcher : $this->collectionFetcher,
			collectionReader  : $this->collectionReader,
			schemaFetcher     : $this->schemaFetcher,
			schemaLister      : $this->schemaLister,
			api               : $this->api,
			collection        : 'text',
		);
	}

	/**
	 * Generate a single field HTML for a given collection, object, and property.
	 * This allows you to create individual form fields without building a full form.
	 *
	 * @param array<string,mixed> $options Field options to override defaults
	 *
	 * @return string The rendered field HTML
	 */
	public function field(string $type, string $name, array $options = []): string
	{
		$options = array_merge([
			'form' => $this->dummyForm(),
			'name' => $name,
		], $options);
		$field = new FormField(...$options);

		$typeClass = 'TotalCMS\\Domain\\Admin\\FormField\\' . ucfirst($type) . 'Field';
		if (class_exists($typeClass) && is_subclass_of($typeClass, FormField::class)) {
			$field = new $typeClass(...$options);
		}

		return $field->build();
	}
}
