<?php

namespace TotalCMS\Domain\Admin;

use Odan\Session\PhpSession;
use TotalCMS\Domain\Admin\FormField\DeleteButton;
use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Admin\FormField\SaveButton;
use TotalCMS\Domain\Admin\Form\AdminForms;
use TotalCMS\Domain\Admin\Form\Builder\CollectionForm;
use TotalCMS\Domain\Admin\Form\Builder\DeckItemForm;
use TotalCMS\Domain\Admin\Form\Builder\ObjectForm;
use TotalCMS\Domain\Admin\Form\Builder\SchemaForm;
use TotalCMS\Domain\Admin\Form\Builder\SimpleForm;
use TotalCMS\Domain\Admin\Form\Builder\TemplateForm;
use TotalCMS\Domain\Admin\Form\FormOptions;
use TotalCMS\Domain\Admin\Form\FormServices;
use TotalCMS\Domain\Admin\Form\Layout\AccordionRenderer;
use TotalCMS\Domain\Admin\Form\Layout\FieldsetRenderer;
use TotalCMS\Domain\Admin\Form\PresetForms;
use TotalCMS\Domain\Admin\Form\SettingsForms;
use TotalCMS\Domain\Admin\Form\SingleFieldForms;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Extension\Service\FormActionRegistry;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Settings\Repository\SettingsRepository;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Translation\TranslationService;

/**
 * Total Form Builder.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 *
 * The form runtime's entry point, and the `cms.form` surface in Twig.
 *
 * Holds the builders the runtime is about — object, collection, schema,
 * template and deck item forms, a form around pre-rendered markup, one
 * field — and the option handling they share. Everything else `cms.form`
 * offers is a one-line delegation to a helper that owns it: AdminForms
 * (the admin's own pages), SettingsForms, PresetForms (blog, feed, mailer …)
 * and SingleFieldForms (the one-field forms, as a table).
 */
readonly class TotalFormFactory
{
	private string $api;
	private AdminForms $adminForms;
	private SettingsForms $settingsForms;
	private PresetForms $presetForms;
	private SingleFieldForms $singleFields;

	public function __construct(
		private FormServices $services,
		PhpSession $session,
		private SchemaFactory $schemaFactory,
		private TemplateRepository $templateRepository,
		SettingsSchemaFetcher $settingsSchemaFetcher,
		SettingsFetcher $settingsFetcher,
		JobManager $jobManager,
		private TranslationService $translationService,
		ExtensionDiscovery $extensionDiscovery,
		ExtensionSettingsManager $extensionSettingsManager,
		ExtensionManager $extensionManager,
		DevModeManager $devModeManager,
		private FormActionRegistry $formActionRegistry,
		SettingsRepository $settingsRepository,
	) {
		$this->api           = $this->services->config->api . '/api';
		$this->adminForms    = new AdminForms($this, $services, $session, $jobManager, $translationService, $devModeManager);
		$this->settingsForms = new SettingsForms($this, $settingsSchemaFetcher, $settingsFetcher, $translationService, $extensionDiscovery, $extensionSettingsManager, $extensionManager, $services->config, $settingsRepository);
		$this->presetForms   = new PresetForms($this, $services);
		$this->singleFields  = new SingleFieldForms($this);
	}


	/** @param array<string,mixed> $options */
	public function simple(string $route, string $content = '', array $options = []): string
	{
		$options['api']         = $this->api;
		$options['route']       = $route;
		$options['csrfManager'] = $this->services->csrfManager;

		// options: method, label, refresh

		$form = new SimpleForm(...$options);

		return $form->build($content);
	}

	/** @param array<string,mixed> $options */
	public function totalform(string $route, string $content = '', array $options = []): string
	{
		// Admin routes (/admin/...) don't have the /api prefix
		$api     = str_starts_with($route, '/admin') ? $this->services->config->api : $this->api;
		$options = array_merge($this->buttonLabels($options), [
			'route'                    => $route,
			'api'                      => $api,
			'formActionRegistry'       => $this->formActionRegistry,
			'translator'               => $this->translationService->trans(...),
		]);

		$form = new TotalForm($this->services, FormOptions::fromArray($options));

		return $form->build($content);
	}

	/**
	 * Wrap pre-rendered field HTML in a styled fieldset. Twig:
	 *   {% set inner %}{{ cms.form.field(...) }}{% endset %}
	 *   {{ cms.form.fieldset('Legend', inner, { formgrid: 'a b\nc d' }) }}
	 *
	 * @param array<string,mixed> $options
	 */
	public function fieldset(string $legend = '', string $content = '', array $options = []): string
	{
		return (new FieldsetRenderer())->wrap(
			$legend === '' ? null : $legend,
			$content,
			(string)($options['formgrid'] ?? ''),
			(string)($options['class'] ?? ''),
		);
	}

	/**
	 * Wrap pre-rendered field HTML in a collapsible accordion group. Twig:
	 *   {% set body %}{{ cms.form.field(...) }}{% endset %}
	 *   {{ cms.form.accordion([
	 *       { title: 'Content', content: body, formgrid: 'body body' },
	 *       { title: 'SEO',     content: seo }
	 *   ]) }}
	 *
	 * One panel renders closed; two or more open the first and link them, which
	 * is the same rule the schema `>> <<` syntax follows.
	 *
	 * @param list<array{title?:string,content?:string,formgrid?:string}> $panels
	 * @param array<string,mixed>                                         $options
	 */
	public function accordion(array $panels = [], array $options = []): string
	{
		return (new AccordionRenderer())->wrap(
			$panels,
			(string)($options['class'] ?? ''),
		);
	}

	/** @param array<string,mixed> $options */
	public function schema(array $options = []): string
	{
		$options = array_merge([
			'id'         => '',
			'collection' => '',
		], $this->buttonLabels($options), [
			// These options cannot be overridden
			'api'                      => $this->api,
		]);

		$form = new SchemaForm($this->services, FormOptions::fromArray($options), $this->schemaFactory);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function template(array $options = []): string
	{
		$options = array_merge([
			'path'       => '',
			'collection' => '',
			'id'         => '',
		], $this->buttonLabels($options), [
			// These options cannot be overridden
			'api'                      => $this->api,
		]);

		$path = (string)$options['path'];
		unset($options['path']);

		$form = new TemplateForm($this->services, FormOptions::fromArray($options), $this->templateRepository, $path);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function collection(array $options = []): string
	{
		$options = array_merge([
			'id'         => '',
			'collection' => '',
		], $this->buttonLabels($options), [
			// These options cannot be overridden
			'api'                      => $this->api,
		]);

		$form = new CollectionForm($this->services, FormOptions::fromArray($options));

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function builder(string $collection, array $options = []): ObjectForm
	{
		$options = array_merge($this->buttonLabels($options), [
			// These options cannot be overridden
			'collection'               => $collection,
			'api'                      => $this->api,
		]);

		$form = new ObjectForm($this->services, FormOptions::fromArray($options));

		return $form;
	}

	/**
	 * Create a deck item form builder.
	 *
	 * @param array<string,mixed> $options options including id, itemId, save, delete, class, etc
	 */
	public function deckBuilder(string $collection, string $property, array $options = []): DeckItemForm
	{
		$options = array_merge($this->buttonLabels($options), [
			// These options cannot be overridden
			'collection'               => $collection,
			'id'                       => $options['id'] ?? '',
			'api'                      => $this->api,
		]);

		$itemId = (string)($options['itemId'] ?? '');
		unset($options['itemId'], $options['property']);

		$form = new DeckItemForm($this->services, FormOptions::fromArray($options), $property, $itemId);

		return $form;
	}

	/**
	 * Create a deck item form with auto-generated fields.
	 *
	 * @param array<string,mixed> $options options including id, itemId, save, delete, class, etc
	 */
	public function deck(string $collection, string $property, array $options = []): string
	{
		$form = $this->deckBuilder($collection, $property, $options);

		return $form->autoBuild();
	}

	/**
	 * Resolve the `save` / `delete` button labels in a form options array.
	 *
	 * Pass `true` to get the button with its DEFAULT label — resolved from the
	 * admin translation catalog (`btn.save` / `btn.delete`), so the buttons
	 * follow the operator's locale instead of being hardcoded English. Pass
	 * `false` (or null) to hide the button, or an explicit string to override
	 * the label with your own wording.
	 *
	 * Every form-building entry point runs its options through here, which is
	 * why templates can simply write `save: canSave` instead of repeating a
	 * translated literal at each call site.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	private function buttonLabels(array $options): array
	{
		foreach (['save' => 'btn.save', 'delete' => 'btn.delete'] as $option => $key) {
			if (!isset($options[$option])) {
				continue;
			}

			$value = $options[$option];

			if (is_string($value)) {
				// Explicit label — the caller's own wording wins.
				continue;
			}

			$options[$option] = $value ? $this->translationService->trans($key) : '';
		}

		return $options;
	}

	public function save(?string $label = null): string
	{
		$button = new SaveButton($label ?? $this->translationService->trans('btn.save'));

		return $button->build();
	}

	public function delete(?string $label = null): string
	{
		$button = new DeleteButton($label ?? $this->translationService->trans('btn.delete'));

		return $button->build();
	}

	private function dummyForm(): TotalForm
	{
		// This is a dummy form to satisfy the type hinting in the field method.
		// It will not be used, but it is required to create a FormField instance.
		// Use empty collection string to prevent fetching/creating any collection
		$form = new ObjectForm($this->services, new FormOptions(api: $this->api));

		return $form;
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

		// Check built-in field types first, then extension-registered types
		$builtInClass = 'TotalCMS\\Domain\\Admin\\FormField\\' . ucfirst($type) . 'Field';
		$typeClass    = (class_exists($builtInClass) && is_subclass_of($builtInClass, FormField::class))
			? $builtInClass
			: (TotalForm::getExtensionFieldTypes()[$type] ?? $builtInClass);

		if (class_exists($typeClass) && is_subclass_of($typeClass, FormField::class)) {
			$field = new $typeClass(...$options);
		} else {
			$field = new FormField(...$options);
		}

		return $field->build();
	}

	/**
	 * Create a report export form.
	 *
	 * @param array<string,mixed> $options Options: include, exclude, includeOptions, excludeOptions, includeSelect, excludeSelect
	 */
	public function report(string $collection = '', array $options = []): string
	{
		return $this->adminForms->report($collection, $options);
	}

	/** @param array<string,mixed> $options */
	public function factory(string $collection, array $options = []): string
	{
		return $this->adminForms->factory($collection, $options);
	}

	/**
	 * Create a login form.
	 *
	 * @param array<string,mixed> $options Options: collection, redirect, showForgotPassword, submitLabel, class, flashMessages, emailLabel, passwordLabel, rememberLabel, forgotPasswordLabel
	 */
	public function loginForm(array $options = []): string
	{
		return $this->adminForms->loginForm($options);
	}

	/** @param array<string,mixed> $options */
	public function importCollection(string $collection, array $options = []): string
	{
		return $this->adminForms->importCollection($collection, $options);
	}

	/** @param array<string,mixed> $options */
	public function importDeck(string $collection, array $options = []): string
	{
		return $this->adminForms->importDeck($collection, $options);
	}

	/** @param array<string,mixed> $options */
	public function exportDeck(string $collection, array $options = []): string
	{
		return $this->adminForms->exportDeck($collection, $options);
	}

	/** @param array<string,mixed> $options */
	public function importSchema(array $options = []): string
	{
		return $this->adminForms->importSchema($options);
	}

	/** @param array<string,mixed> $options */
	public function importJumpStart(array $options = []): string
	{
		return $this->adminForms->importJumpStart($options);
	}

	/** @param array<string,mixed> $options */
	public function jobqueueStats(array $options = []): string
	{
		return $this->adminForms->jobqueueStats($options);
	}

	/** @param array<string,mixed> $options */
	public function jobqueueByStatus(array $options = []): string
	{
		return $this->adminForms->jobqueueByStatus($options);
	}

	/** @param array<string,mixed> $options */
	public function jobqueueByType(array $options = []): string
	{
		return $this->adminForms->jobqueueByType($options);
	}

	/** @param array<string,mixed> $options */
	public function clearqueue(array $options = []): string
	{
		return $this->adminForms->clearqueue($options);
	}

	/** @param array<string,mixed> $options */
	public function devmode(array $options = []): string
	{
		return $this->adminForms->devmode($options);
	}

	/**
	 * Generate a settings form for a specific section.
	 *
	 * @param array<string,mixed> $options
	 */
	public function settings(string $section, array $options = []): string
	{
		return $this->settingsForms->settings($section, $options);
	}

	/**
	 * Generate a settings form for an extension.
	 *
	 * Includes auto-generated permission toggles for each detected capability,
	 * followed by the extension's custom settings (if a settings schema exists).
	 */
	public function extensionSettings(string $extensionId): string
	{
		return $this->settingsForms->extensionSettings($extensionId);
	}

	/** @param array<string,mixed> $options */
	public function playground(string $id = '', array $options = []): string
	{
		return $this->presetForms->playground($id, $options);
	}

	/** @param array<string,mixed> $options */
	public function dataviews(string $id = '', array $options = []): string
	{
		return $this->presetForms->dataviews($id, $options);
	}

	/** @param array<string,mixed> $options */
	public function mailer(string $id = '', array $options = []): string
	{
		return $this->presetForms->mailer($id, $options);
	}

	/**
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 *
	 * @param array<string,mixed> $options
	 */
	public function blog(array $options = []): string
	{
		return $this->presetForms->blog($options);
	}

	/** @param array<string,mixed> $options */
	public function feed(array $options = []): string
	{
		return $this->presetForms->feed($options);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function checkbox(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('checkbox', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function color(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('color', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function date(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('date', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function datetime(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('datetime', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function email(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('email', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function gallery(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('gallery', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function image(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('image', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function file(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('file', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function depot(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('depot', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function depotDrop(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('depotDrop', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function number(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('number', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function price(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('price', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function range(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('range', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function select(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('select', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function styledtext(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('styledtext', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function svg(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('svg', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function text(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('text', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function code(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('code', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function textarea(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('textarea', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function toggle(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('toggle', $id, $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function url(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFields->form('url', $id, $formSettings, $fieldSettings);
	}
}
