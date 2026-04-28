<?php

namespace TotalCMS\Domain\Admin;

use Odan\Session\PhpSession;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Admin\FormField\DeleteButton;
use TotalCMS\Domain\Admin\FormField\FormField;
use TotalCMS\Domain\Admin\FormField\SaveButton;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\DataView\Service\DataViewFilter;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Service\SchemaFactory;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

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
		private PhpSession $session,
		private ObjectFetcher $objectFetcher,
		private CollectionFetcher $collectionFetcher,
		private CollectionLister $collectionLister,
		private IndexReader $collectionReader,
		private IndexFilter $indexFilter,
		private SchemaFetcher $schemaFetcher,
		private SchemaLister $schemaLister,
		private AccessGroupLister $accessGroupLister,
		private CollectionEditionService $collectionEditionService,
		private EditionFeatureService $editionFeatures,
		private SchemaFactory $schemaFactory,
		private TemplateRepository $templateRepository,
		private CSRFTokenManager $csrfManager,
		private SettingsSchemaFetcher $settingsSchemaFetcher,
		private SettingsFetcher $settingsFetcher,
		private JobManager $jobManager,
		private DataViewLister $dataViewLister,
		private PropertyMetaResolver $metaResolver,
		private DataViewFilter $dataViewFilter,
		private TranslationService $translationService,
		private ExtensionDiscovery $extensionDiscovery,
		private ExtensionSettingsManager $extensionSettingsManager,
		private ExtensionManager $extensionManager,
		private TemplateLister $templateLister,
	) {
		$this->api = $this->config->api . '/api';
	}

	/**
	 * Create a report export form.
	 *
	 * @param array<string,mixed> $options Options: include, exclude, includeOptions, excludeOptions, includeSelect, excludeSelect
	 */
	public function report(string $collection = '', array $options = []): string
	{
		$includeOptions = $options['includeOptions'] ?? [];
		$excludeOptions = $options['excludeOptions'] ?? [];

		$form = new ReportForm(
			api              : $this->api,
			collectionLister : $this->collectionLister,
			translator       : $this->translationService->trans(...),
			collection       : $collection,
			include          : (string)($options['include'] ?? ''),
			exclude          : (string)($options['exclude'] ?? ''),
			includeOptions   : is_array($includeOptions) ? $includeOptions : [],
			excludeOptions   : is_array($excludeOptions) ? $excludeOptions : [],
			includeSelect    : (bool)($options['includeSelect'] ?? false),
			excludeSelect    : (bool)($options['excludeSelect'] ?? false),
		);

		return $form->build();
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
	public function totalform(string $route, string $content = '', array $options = []): string
	{
		$options = array_merge($options, [
			'route'                    => $route,
			'api'                      => $this->api,
			'objectFetcher'            => $this->objectFetcher,
			'collectionFetcher'        => $this->collectionFetcher,
			'collectionLister'         => $this->collectionLister,
			'collectionReader'         => $this->collectionReader,
			'indexFilter'              => $this->indexFilter,
			'schemaFetcher'            => $this->schemaFetcher,
			'schemaLister'             => $this->schemaLister,
			'accessGroupLister'        => $this->accessGroupLister,
			'collectionEditionService' => $this->collectionEditionService,
			'editionFeatures'          => $this->editionFeatures,
			'dataViewFilter'           => $this->dataViewFilter,
			'csrfManager'              => $this->csrfManager,
			'config'                   => $this->config,
			'metaResolver'             => $this->metaResolver,
		]);

		$form = new TotalForm(...$options);

		return $form->build($content);
	}

	/** @param array<string,mixed> $options */
	public function factory(string $collection, array $options = []): string
	{
		$options['api']         = $this->api;
		$options['collection']  = $collection;
		$options['csrfManager'] = $this->csrfManager;

		$form = new FactoryForm(...$options);

		return $form->build();
	}

	/**
	 * Create a login form.
	 *
	 * @param array<string,mixed> $options Options: collection, redirect, showForgotPassword, submitLabel, class, flashMessages, emailLabel, passwordLabel, rememberLabel, forgotPasswordLabel
	 */
	public function loginForm(array $options = []): string
	{
		$options['api']          = $this->api;
		$options['session']      = $this->session;
		$options['csrfManager']  = $this->csrfManager;
		$options['showPasskeys'] ??= $this->editionFeatures->can(EditionFeature::PASSKEYS)
			&& ($this->config->auth['usePasskeys'] ?? true);

		$form = new LoginForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function importCollection(string $collection, array $options = []): string
	{
		$options['api']         = $this->api;
		$options['collection']  = $collection;
		$options['csrfManager'] = $this->csrfManager;

		$form = new ImportCollectionForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function importDeck(string $collection, array $options = []): string
	{
		[$objects, $deckProperties] = $this->getDeckFormData($collection);

		$options['api']            = $this->api;
		$options['collection']     = $collection;
		$options['objects']        = $objects;
		$options['deckProperties'] = $deckProperties;
		$options['csrfManager']    = $this->csrfManager;

		$form = new ImportDeckForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function exportDeck(string $collection, array $options = []): string
	{
		[$objects, $deckProperties] = $this->getDeckFormData($collection);

		$options['api']            = $this->api;
		$options['collection']     = $collection;
		$options['objects']        = $objects;
		$options['deckProperties'] = $deckProperties;

		$form = new ExportDeckForm(...$options);

		return $form->build();
	}

	/**
	 * Build the object list and deck property list for a collection.
	 *
	 * @return array{0: array<array{value:string,label:string}>, 1: array<array{value:string,label:string}>}
	 */
	private function getDeckFormData(string $collection): array
	{
		$index   = $this->collectionReader->fetchIndex($collection);
		$objects = [];
		foreach ($index->objects->all() as $object) {
			$id        = (string)($object['id'] ?? '');
			$title     = (string)($object['title'] ?? $object['name'] ?? $id);
			$objects[] = ['value' => $id, 'label' => $title];
		}

		$deckProperties = [];
		try {
			$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);
			foreach ($schema->properties as $propName => $propConfig) {
				$deckref = $propConfig['deckref'] ?? $propConfig['settings']['deckref'] ?? null;
				if (!empty($deckref)) {
					$deckProperties[] = ['value' => $propName, 'label' => $propName];
				}
			}
		} catch (\Exception) {
			// Schema lookup failed, leave empty
		}

		return [$objects, $deckProperties];
	}

	/** @param array<string,mixed> $options */
	public function importSchema(array $options = []): string
	{
		$options['api']         = $this->api;
		$options['csrfManager'] = $this->csrfManager;

		$form = new ImportSchemaForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function importJumpStart(array $options = []): string
	{
		$options['api']         = $this->api;
		$options['csrfManager'] = $this->csrfManager;

		$form = new ImportJumpStartForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function jobqueueStats(array $options = []): string
	{
		$options['api']        = $this->api;
		$options['jobManager'] = $this->jobManager;

		$stats = new JobQueueStats(...$options);

		return $stats->allStats();
	}

	/** @param array<string,mixed> $options */
	public function jobqueueByStatus(array $options = []): string
	{
		$options['api']        = $this->api;
		$options['jobManager'] = $this->jobManager;

		$header = $options['header'] ?? null;
		unset($options['header']);

		$stats = new JobQueueStats(...$options);

		return $stats->tableByStatus($header);
	}

	/** @param array<string,mixed> $options */
	public function jobqueueByType(array $options = []): string
	{
		$options['api']        = $this->api;
		$options['jobManager'] = $this->jobManager;

		$header = $options['header'] ?? null;
		unset($options['header']);

		$stats = new JobQueueStats(...$options);

		return $stats->tableByType($header);
	}

	/** @param array<string,mixed> $options */
	public function clearqueue(array $options = []): string
	{
		$options['api']         = $this->api;
		$options['csrfManager'] = $this->csrfManager;

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
			'api'                      => $this->api,
			'objectFetcher'            => $this->objectFetcher,
			'collectionFetcher'        => $this->collectionFetcher,
			'collectionLister'         => $this->collectionLister,
			'collectionReader'         => $this->collectionReader,
			'indexFilter'              => $this->indexFilter,
			'schemaFetcher'            => $this->schemaFetcher,
			'schemaLister'             => $this->schemaLister,
			'accessGroupLister'        => $this->accessGroupLister,
			'collectionEditionService' => $this->collectionEditionService,
			'editionFeatures'          => $this->editionFeatures,
			'schemaFactory'            => $this->schemaFactory,
			'dataViewFilter'           => $this->dataViewFilter,
			'csrfManager'              => $this->csrfManager,
			'config'                   => $this->config,
			'metaResolver'             => $this->metaResolver,
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
			'api'                      => $this->api,
			'objectFetcher'            => $this->objectFetcher,
			'collectionFetcher'        => $this->collectionFetcher,
			'collectionLister'         => $this->collectionLister,
			'collectionReader'         => $this->collectionReader,
			'indexFilter'              => $this->indexFilter,
			'schemaFetcher'            => $this->schemaFetcher,
			'schemaLister'             => $this->schemaLister,
			'accessGroupLister'        => $this->accessGroupLister,
			'collectionEditionService' => $this->collectionEditionService,
			'editionFeatures'          => $this->editionFeatures,
			'templateRepository'       => $this->templateRepository,
			'dataViewFilter'           => $this->dataViewFilter,
			'csrfManager'              => $this->csrfManager,
			'config'                   => $this->config,
			'metaResolver'             => $this->metaResolver,
		]);

		$form = new TemplateForm(...$options);
		$form->setTemplateLister($this->templateLister);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function playground(string $id = '', array $options = []): string
	{
		$options = array_merge([
			'save'        => 'Save',
			'delete'      => 'Delete',
			'class'       => 'playground-form no-unsaved-warning',
		], $options);
		$options['id'] = $id;

		$form = $this->builder('playground', $options);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function dataviews(string $id = '', array $options = []): string
	{
		$this->dataViewLister->ensureCollection();

		$options = array_merge([
			'save'        => 'Save',
			'delete'      => 'Delete',
			'class'       => 'dataview-form no-unsaved-warning',
		], $options);
		$options['id'] = $id;

		$form = $this->builder('dataviews', $options);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function mailer(string $id = '', array $options = []): string
	{
		$options = array_merge([
			'save'        => 'Save',
			'delete'      => 'Delete',
			'class'       => 'help-on-hover help-box mailer-form formgrid',
			'useFormGrid' => false,
		], $options);
		$options['id'] = $id;

		$form = $this->builder('mailer', $options);

		// Row: Active toggle + ID
		$content  = $form->field('active');
		$content .= $form->field('id');
		$content .= $form->field('name');
		$content .= $form->field('category');
		$content .= $form->field('description');

		$content .= HTMLUtils::inlineElement('hr', ['class' => 'form-grid-section-divider']);

		$content .= $form->field('to');
		$content .= $form->field('from');
		$content .= $form->field('toName');
		$content .= $form->field('fromName');
		$content .= $form->field('replyTo');
		$content .= $form->field('cc');
		$content .= $form->field('bcc');

		$content .= HTMLUtils::inlineElement('hr', ['class' => 'form-grid-section-divider']);

		// Subject, Body HTML, Body Text (full width)
		$content .= $form->field('subject');
		$content .= $form->field('bodyHtml');
		$content .= $form->field('bodyText');

		$bulkSection = '';

		if ($id !== '') {
			$hiddenMailerId = HTMLUtils::inlineElement('input', [
				'type' => 'hidden', 'name' => 'mailerId', 'value' => $id,
			]);

			$objectPickerScript = <<<SCRIPT
			<script>
			(function() {
				let debounceTimer = null;
				let cachedData = [];
				const pickerInput = document.querySelector('select[name="bulkObjectIds[]"]');
				const picker = pickerInput ? pickerInput.closest('.form-field') : null;
				const previewInput = document.querySelector('select[name="bulkPreviewObjectId"]');
				const previewField = previewInput ? previewInput.closest('.form-field') : null;
				if (!picker) return;

				function updateChoices(field, data) {
					if (!field || !field.totalfield || !field.totalfield.choices) return;
					const choices = field.totalfield.choices;
					choices.clearStore();
					if (data.length > 0) {
						choices.setChoices(data, 'value', 'label', true);
					}
				}

				function fetchObjects() {
					const collection = document.querySelector('[name="bulkCollection"]');
					const include = document.querySelector('[name="bulkInclude"]');
					const exclude = document.querySelector('[name="bulkExclude"]');
					if (!collection || !collection.value) return;

					const params = new URLSearchParams({ bulkCollection: collection.value });
					if (include && include.value) params.set('bulkInclude', include.value);
					if (exclude && exclude.value) params.set('bulkExclude', exclude.value);

					fetch('{$this->api}/action/mailer/bulk/objects?' + params.toString())
						.then(r => r.json())
						.then(data => {
							cachedData = data;
							updateChoices(picker, data);
							updateChoices(previewField, data);
						})
						.catch(() => {});
				}

				function debouncedFetch() {
					clearTimeout(debounceTimer);
					debounceTimer = setTimeout(fetchObjects, 500);
				}

				const collectionEl = document.querySelector('[name="bulkCollection"]');
				const includeEl = document.querySelector('[name="bulkInclude"]');
				const excludeEl = document.querySelector('[name="bulkExclude"]');

				if (collectionEl) collectionEl.addEventListener('change', fetchObjects);
				if (includeEl) includeEl.addEventListener('input', debouncedFetch);
				if (excludeEl) excludeEl.addEventListener('input', debouncedFetch);

				// When the preview accordion opens, apply cached data
				if (previewField) {
					const details = previewField.closest('details');
					if (details) {
						details.addEventListener('toggle', () => {
							if (details.open && cachedData.length > 0) {
								updateChoices(previewField, cachedData);
							}
						});
					}
				}

				if (collectionEl && collectionEl.value) fetchObjects();
			})();
			</script>
			SCRIPT;

			// Audience + Send combined accordion
			$sendAttrs = array_merge(
				HTMLUtils::htmxAttributes($this->api . '/action/mailer/bulk', 'post', [
					'target'  => '#bulk-send-output',
					'swap'    => 'innerHTML',
					'confirm' => 'Are you sure you want to queue a bulk email send? This will send one email per matching object.',
				]),
				['class' => 'dash-button accent', 'id' => 'bulk-send-btn']
			);
			$sendAttrs['hx-include'] = '[name="mailerId"],[name="bulkCollection"],[name="bulkInclude"],[name="bulkExclude"],[name="bulkOverrideTo"],[name="bulkscheduledAt"],[name="bulkObjectIds[]"]';

			$bulkSendFields = $form->field('bulkCollection') .
				$form->field('bulkInclude') .
				$form->field('bulkExclude') .
				$form->field('bulkObjectIds[]', [
					'field'       => 'list',
					'label'       => 'Specific Objects',
					'help'        => 'Select specific objects to override filters. Leave empty to use filters above.',
					'placeholder' => 'Select objects...',
					'settings'    => [
						'addChoices'       => false,
						'removeItemButton' => true,
					],
				]) .
				HTMLUtils::inlineElement('hr', ['class' => 'bulk-divider']) .
				$form->field('bulkOverrideTo', [
					'field'       => 'email',
					'label'       => 'Override To Email (for testing)',
					'placeholder' => 'test@example.com',
					'help'        => 'Override recipient email for testing. All emails will be sent to this address instead.',
				]) .
				$form->field('bulkscheduledAt', [
					'field'       => 'datetime',
					'label'       => 'Schedule',
					'placeholder' => 'Enter a date and time to schedule this email',
				]) .
				HTMLUtils::element('button', 'Queue Bulk Send', $sendAttrs) .
				'<div id="bulk-send-output" class="bulk-send-output"></div>';
			$bulkSendDetails = HTMLUtils::details('Audience & Send', $bulkSendFields);

			// Preview accordion
			$previewAttrs = array_merge(
				HTMLUtils::htmxAttributes($this->api . '/action/mailer/bulk/preview', 'post', [
					'target' => '#bulk-preview-output',
					'swap'   => 'innerHTML',
				]),
				['class' => 'dash-button', 'id' => 'bulk-preview-btn']
			);
			$previewAttrs['hx-include'] = '[name="mailerId"],[name="bulkPreviewObjectId"],[name="bulkCollection"]';

			$bulkPreviewForm = $form->field('bulkPreviewObjectId', [
				'field'       => 'list',
				'label'       => 'Preview Object',
				'placeholder' => 'Select an object to preview...',
				'settings'    => [
					'addChoices'       => false,
					'removeItemButton' => true,
					'maxItemCount'     => 1,
				],
			]) . HTMLUtils::element('button', 'Preview', $previewAttrs);
			$bulkPreviewForm    = HTMLUtils::element('div', $bulkPreviewForm, ['class' => 'bulk-preview-form']);
			$bulkPreviewOutput  = HTMLUtils::element('div', '', [
				'id'    => 'bulk-preview-output',
				'class' => 'bulk-preview-output',
			]);
			$bulkPreviewDetails = HTMLUtils::details('Preview', $bulkPreviewForm . $bulkPreviewOutput);

			$bulkSection  = $hiddenMailerId;
			$bulkSection .= HTMLUtils::element('h2', 'Bulk Send <span class="bulk-pro-badge">Pro</span>');
			$bulkSection .= HTMLUtils::element('p', 'Send this email to every matching object in a collection.');
			$bulkSection .= $bulkSendDetails . $bulkPreviewDetails . $objectPickerScript;
			$bulkSection  = HTMLUtils::element('form', $bulkSection, ['class' => 'bulk-send-section totalform custom-layout help-on-hover help-box no-save no-unsaved-warning']);
		}

		return $form->build($content, $bulkSection);
	}

	/** @param array<string,mixed> $options */
	public function collection(array $options = []): string
	{
		$options = array_merge([
			'id'         => '',
			'collection' => '',
		], $options, [
			// These options cannot be overridden
			'api'                      => $this->api,
			'objectFetcher'            => $this->objectFetcher,
			'collectionFetcher'        => $this->collectionFetcher,
			'collectionLister'         => $this->collectionLister,
			'collectionReader'         => $this->collectionReader,
			'indexFilter'              => $this->indexFilter,
			'schemaFetcher'            => $this->schemaFetcher,
			'schemaLister'             => $this->schemaLister,
			'accessGroupLister'        => $this->accessGroupLister,
			'collectionEditionService' => $this->collectionEditionService,
			'editionFeatures'          => $this->editionFeatures,
			'dataViewFilter'           => $this->dataViewFilter,
			'csrfManager'              => $this->csrfManager,
			'config'                   => $this->config,
			'metaResolver'             => $this->metaResolver,
		]);

		$form = new CollectionForm(...$options);

		return $form->autoBuild();
	}

	/** @param array<string,mixed> $options */
	public function builder(string $collection, array $options = []): ObjectForm
	{
		$options = array_merge($options, [
			// These options cannot be overridden
			'collection'               => $collection,
			'api'                      => $this->api,
			'collectionFetcher'        => $this->collectionFetcher,
			'collectionLister'         => $this->collectionLister,
			'collectionReader'         => $this->collectionReader,
			'indexFilter'              => $this->indexFilter,
			'objectFetcher'            => $this->objectFetcher,
			'schemaFetcher'            => $this->schemaFetcher,
			'schemaLister'             => $this->schemaLister,
			'accessGroupLister'        => $this->accessGroupLister,
			'collectionEditionService' => $this->collectionEditionService,
			'editionFeatures'          => $this->editionFeatures,
			'dataViewFilter'           => $this->dataViewFilter,
			'csrfManager'              => $this->csrfManager,
			'config'                   => $this->config,
			'metaResolver'             => $this->metaResolver,
		]);

		$form = new ObjectForm(...$options);
		$form->setTemplateLister($this->templateLister);

		return $form;
	}

	/**
	 * Create a deck item form builder.
	 *
	 * @param array<string,mixed> $options options including id, itemId, save, delete, class, etc
	 */
	public function deckBuilder(string $collection, string $property, array $options = []): DeckItemForm
	{
		$options = array_merge($options, [
			// These options cannot be overridden
			'collection'               => $collection,
			'property'                 => $property,
			'id'                       => $options['id'] ?? '',
			'api'                      => $this->api,
			'collectionFetcher'        => $this->collectionFetcher,
			'collectionLister'         => $this->collectionLister,
			'collectionReader'         => $this->collectionReader,
			'indexFilter'              => $this->indexFilter,
			'objectFetcher'            => $this->objectFetcher,
			'schemaFetcher'            => $this->schemaFetcher,
			'schemaLister'             => $this->schemaLister,
			'accessGroupLister'        => $this->accessGroupLister,
			'collectionEditionService' => $this->collectionEditionService,
			'editionFeatures'          => $this->editionFeatures,
			'dataViewFilter'           => $this->dataViewFilter,
			'csrfManager'              => $this->csrfManager,
			'config'                   => $this->config,
			'metaResolver'             => $this->metaResolver,
		]);

		return new DeckItemForm(...$options);
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
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	private function singleFieldFormBuilder(
		string $id,
		string $defaultCollection,
		string $property,
		string $field,
		array $formSettings = [],
		array $fieldSettings = [],
	): string {
		$formSettings = array_merge([
			'collection' => $defaultCollection,
			'hideID'     => true,
			'id'         => $id,
		], $formSettings);

		$class                 = $formSettings['class'] ?? ' custom-layout';
		$formSettings['class'] = $class;

		$collection = $formSettings['collection'];
		unset($formSettings['collection']);

		$fieldSettings['field'] = $field;

		$form = $this->builder($collection, $formSettings);

		$form->addField('id');
		$form->addField($property, $fieldSettings);

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
		$defaults    = require PathResolver::packageRoot() . '/config/defaults.php';
		$timezones   = $options['timezones'] ?? timezone_identifiers_list();

		if ($schema === null || !isset($schema['properties']) || !is_array($schema['properties'])) {
			return '<p class="error">Schema not found for this settings section.</p>';
		}

		$formfields = '';

		foreach ($schema['properties'] as $fieldName => $fieldSchema) {
			// Resolve field type: "field" takes precedence over "type"
			$fieldType = $fieldSchema['field'] ?? $fieldSchema['type'] ?? 'text';

			// Get current value with priority: sectionData > defaults > schema default > empty string
			$currentValue = '';
			if (isset($fieldSchema['default'])) {
				$currentValue = $fieldSchema['default'];
			}
			// Installation and General settings are stored at top level in defaults, not under section keys
			if (($section === 'installation' || $section === 'general') && isset($defaults[$fieldName])) {
				$currentValue = $defaults[$fieldName];
			} elseif (isset($defaults[$section][$fieldName])) {
				$currentValue = $defaults[$section][$fieldName];
			}
			if (isset($sectionData[$fieldName])) {
				$currentValue = $sectionData[$fieldName];
			}

			// Special handling for JSON fields - convert arrays to JSON strings for display
			if ($fieldType === 'json' && is_array($currentValue)) {
				$currentValue = json_encode($currentValue, JSON_PRETTY_PRINT);
			}

			// Build field options
			$fieldSettings = [
				'field'       => $fieldType,
				'label'       => $fieldSchema['label'] ?? '',
				'help'        => $fieldSchema['help'] ?? '',
				'placeholder' => $fieldSchema['placeholder'] ?? '',
				'value'       => $currentValue,
				'required'    => $fieldSchema['required'] ?? false,
				'min'         => $fieldSchema['min'] ?? null,
				'max'         => $fieldSchema['max'] ?? null,
				'settings'    => $fieldSchema['settings'] ?? [],
			];

			// Merge deck-specific schema keys into settings
			if ($fieldType === 'deck' || $fieldType === 'deckTable') {
				if (isset($fieldSchema['deckref'])) {
					$fieldSettings['settings']['deckref'] = $fieldSchema['deckref'];
				}
				if (isset($fieldSchema['deckItemLabel'])) {
					$fieldSettings['settings']['deckItemLabel'] = $fieldSchema['deckItemLabel'];
				}
			}

			// Special handling for select fields with options
			if (isset($fieldSchema['options'])) {
				$fieldSettings['options'] = $fieldSchema['options'];
			}

			// Special handling for timezone field
			if (isset($fieldSchema['settings']['timezoneOptions']) && $fieldSchema['settings']['timezoneOptions']) {
				$timezoneOptions = [];
				foreach ($timezones as $tz) {
					$timezoneOptions[] = ['value' => $tz, 'label' => $tz];
				}
				$fieldSettings['options'] = $timezoneOptions;
			}

			$formfields .= $this->field($fieldType, $fieldName, $fieldSettings);
		}

		return $this->totalform('/admin/settings/' . $section, $formfields, [
			'method'      => 'POST',
			'save'        => 'Save Settings',
			'class'       => 'help-on-hover help-box',
		]);
	}

	/**
	 * Generate a settings form for an extension.
	 *
	 * Includes auto-generated permission toggles for each detected capability,
	 * followed by the extension's custom settings (if a settings schema exists).
	 */
	public function extensionSettings(string $extensionId): string
	{
		$formfields  = $this->buildPermissionToggles($extensionId);
		$formfields .= $this->buildExtensionSettingsFields($extensionId, $formfields !== '');

		if ($formfields === '') {
			return '<p>This extension has no configurable settings.</p>';
		}

		return $this->totalform('/admin/extensions/' . $extensionId . '/settings', $formfields, [
			'method' => 'POST',
			'save'   => 'Save Settings',
			'class'  => 'help-on-hover help-box',
		]);
	}

	private function buildPermissionToggles(string $extensionId): string
	{
		$permissions      = $this->extensionManager->getPermissions($extensionId);
		$capabilityLabels = ExtensionContext::capabilityLabels();

		if ($permissions === []) {
			return '';
		}

		$toggles = '';
		foreach ($permissions as $capability => $enabled) {
			$label    = $capabilityLabels[$capability] ?? $capability;
			$toggles .= $this->field('toggle', 'perm_' . str_replace(':', '_', $capability), [
				'field' => 'toggle',
				'label' => $label,
				'value' => $enabled,
			]);
		}

		return '<fieldset class="ext-permissions">'
			. '<legend>Permissions</legend>'
			. '<div class="ext-permissions-grid">' . $toggles . '</div>'
			. '</fieldset>';
	}

	private function buildExtensionSettingsFields(string $extensionId, bool $hasPermissions): string
	{
		$schema = $this->loadExtensionSettingsSchema($extensionId);
		if ($schema === null) {
			return '';
		}

		$settings   = $this->extensionSettingsManager->getSettings($extensionId);
		$formfields = '';

		if ($hasPermissions) {
			$formfields .= '<h3 style="margin:2rem 0 0.5rem;">Settings</h3>';
		}

		foreach ($schema as $fieldName => $fieldSchema) {
			$fieldType    = $fieldSchema['field'] ?? $fieldSchema['type'] ?? 'text';
			$currentValue = $settings[$fieldName] ?? $fieldSchema['default'] ?? '';

			if ($fieldType === 'json' && is_array($currentValue)) {
				$currentValue = json_encode($currentValue, JSON_PRETTY_PRINT);
			}

			$fieldSettings = [
				'field'       => $fieldType,
				'label'       => $fieldSchema['label'] ?? '',
				'help'        => $fieldSchema['help'] ?? '',
				'placeholder' => $fieldSchema['placeholder'] ?? '',
				'value'       => $currentValue,
				'required'    => $fieldSchema['required'] ?? false,
				'min'         => $fieldSchema['min'] ?? null,
				'max'         => $fieldSchema['max'] ?? null,
				'settings'    => $fieldSchema['settings'] ?? [],
			];

			if (isset($fieldSchema['options'])) {
				$fieldSettings['options'] = $fieldSchema['options'];
			}

			$formfields .= $this->field($fieldType, $fieldName, $fieldSettings);
		}

		return $formfields;
	}

	/**
	 * Load and validate the settings schema properties for an extension.
	 *
	 * @return array<string,array<string,mixed>>|null
	 */
	private function loadExtensionSettingsSchema(string $extensionId): ?array
	{
		$manifests = $this->extensionDiscovery->discover();
		$manifest  = $manifests[$extensionId] ?? null;

		if ($manifest === null || $manifest->settingsSchema === null) {
			return null;
		}

		$extPath = $this->extensionDiscovery->getExtensionPath($extensionId);
		if ($extPath === null) {
			return null;
		}

		$schemaFile = $extPath . '/' . $manifest->settingsSchema;
		if (!is_file($schemaFile)) {
			return null;
		}

		$schemaJson = file_get_contents($schemaFile);
		if ($schemaJson === false) {
			return null;
		}

		$schema = json_decode($schemaJson, true);
		if (!is_array($schema) || !isset($schema['properties']) || !is_array($schema['properties'])) {
			return null;
		}

		return $schema['properties'];
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

		$class            = trim('custom-layout ' . ($options['class'] ?? ''));
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
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function checkbox(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		$formSettings['autosave'] = true;

		return $this->singleFieldFormBuilder($id, 'toggle', 'status', 'checkbox', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function color(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'color', 'color', 'color', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function date(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'date', 'date', 'date', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function datetime(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'date', 'date', 'datetime', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function email(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'email', 'email', 'email', $formSettings, $fieldSettings);
	}

	/** @param array<string,mixed> $options */
	public function feed(array $options = []): string
	{
		$options = array_merge([
			'collection' => 'feed',
			'save'       => 'Save',
			'delete'     => 'Delete',
		], $options);

		$class            = trim('custom-layout ' . ($options['class'] ?? ''));
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
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function gallery(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'gallery', 'gallery', 'gallery', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function image(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'image', 'image', 'image', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function file(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'file', 'file', 'file', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function depot(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'depot', 'depot', 'depot', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function depotDrop(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		$formSettings = array_merge([
			'collection' => 'depot',
			'property'   => 'depot',
		], $formSettings);

		$property = $formSettings['property'];
		unset($formSettings['property']);

		return $this->singleFieldFormBuilder($id, $formSettings['collection'], $property, 'depotDrop', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function number(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'number', 'number', 'number', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function price(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'number', 'number', 'price', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function range(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'number', 'number', 'range', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function select(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'text', 'text', 'select', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function styledtext(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'styledtext', 'styledtext', 'styledtext', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function svg(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'svg', 'svg', 'svg', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function text(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'text', 'text', 'text', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function code(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'code', 'code', 'code', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function textarea(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'text', 'text', 'textarea', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function toggle(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		$formSettings['autosave'] = true;

		return $this->singleFieldFormBuilder($id, 'toggle', 'status', 'toggle', $formSettings, $fieldSettings);
	}

	/**
	 * @param array<string,mixed> $formSettings
	 * @param array<string,mixed> $fieldSettings
	 */
	public function url(string $id, array $formSettings = [], array $fieldSettings = []): string
	{
		return $this->singleFieldFormBuilder($id, 'url', 'url', 'url', $formSettings, $fieldSettings);
	}

	private function dummyForm(): TotalForm
	{
		// This is a dummy form to satisfy the type hinting in the field method.
		// It will not be used, but it is required to create a FormField instance.
		// Use empty collection string to prevent fetching/creating any collection
		return new ObjectForm(
			objectFetcher            : $this->objectFetcher,
			collectionFetcher        : $this->collectionFetcher,
			collectionLister         : $this->collectionLister,
			collectionReader         : $this->collectionReader,
			indexFilter              : $this->indexFilter,
			schemaFetcher            : $this->schemaFetcher,
			schemaLister             : $this->schemaLister,
			accessGroupLister        : $this->accessGroupLister,
			collectionEditionService : $this->collectionEditionService,
			editionFeatures          : $this->editionFeatures,
			dataViewFilter           : $this->dataViewFilter,
			csrfManager              : $this->csrfManager,
			config                   : $this->config,
			metaResolver             : $this->metaResolver,
			api                      : $this->api,
			collection               : '',
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
}
