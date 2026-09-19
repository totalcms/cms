<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Form;

use Odan\Session\PhpSession;
use TotalCMS\Domain\Admin\ExportDeckForm;
use TotalCMS\Domain\Admin\FactoryForm;
use TotalCMS\Domain\Admin\ImportCollectionForm;
use TotalCMS\Domain\Admin\ImportDeckForm;
use TotalCMS\Domain\Admin\ImportJumpStartForm;
use TotalCMS\Domain\Admin\ImportSchemaForm;
use TotalCMS\Domain\Admin\JobQueueForm;
use TotalCMS\Domain\Admin\JobQueueStats;
use TotalCMS\Domain\Admin\LoginForm;
use TotalCMS\Domain\Admin\ReportForm;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Translation\TranslationService;

/**
 * The forms the admin's own pages render: login, reports, the factory,
 * imports and exports, the job queue, dev mode.
 *
 * Each is one page's form rather than a content form; they live here so
 * TotalFormFactory holds the form runtime and nothing else. Reached through
 * the factory (`cms.form.importDeck()` …), which delegates.
 */
final readonly class AdminForms
{
	private string $api;

	public function __construct(
		private TotalFormFactory $forms,
		private FormServices $services,
		private PhpSession $session,
		private JobManager $jobManager,
		private TranslationService $translationService,
		private DevModeManager $devModeManager,
	) {
		$this->api = $this->services->config->api . '/api';
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
			collectionLister : $this->services->collectionLister,
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
	public function factory(string $collection, array $options = []): string
	{
		$options['api']         = $this->api;
		$options['collection']  = $collection;
		$options['csrfManager'] = $this->services->csrfManager;

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
		$options['api']          = $this->services->config->api;
		$options['session']      = $this->session;
		$options['csrfManager']  = $this->services->csrfManager;
		// LoginForm resolves all labels/help from the admin translation domain
		// (with empty label overrides falling through to localized defaults).
		$options['translator'] = $this->translationService->trans(...);
		$options['loginWith'] ??= $this->services->config->auth['loginWith'] ?? 'both';
		$options['showPasskeys'] ??= $this->services->editionFeatures->can(EditionFeature::PASSKEYS)
			&& ($this->services->config->auth['usePasskeys'] ?? true);

		$form = new LoginForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function importCollection(string $collection, array $options = []): string
	{
		$options['api']         = $this->api;
		$options['collection']  = $collection;
		$options['csrfManager'] = $this->services->csrfManager;

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
		$options['csrfManager']    = $this->services->csrfManager;

		$form = new ImportDeckForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function exportDeck(string $collection, array $options = []): string
	{
		[$objects, $deckProperties] = $this->getDeckFormData($collection);

		// Nothing to export — return empty so the caller (export.twig) can
		// suppress the section entirely instead of rendering a useless form.
		if ($deckProperties === []) {
			return '';
		}

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
	 * Returns `[[], []]` when the schema has no deck properties — the deck
	 * export UI uses that as a signal to suppress the section entirely, and
	 * we skip the per-object index walk that would otherwise run pointlessly.
	 *
	 * @return array{0: array<array{value:string,label:string}>, 1: array<array{value:string,label:string}>}
	 */
	private function getDeckFormData(string $collection): array
	{
		$deckProperties = [];
		try {
			$schema = $this->services->schemaFetcher->fetchSchemaForCollection($collection);
			foreach ($schema->properties as $propName => $propConfig) {
				// Match only true deck properties — cards also carry a
				// `schemaref`, so checking `extractSchemaRef !== null` (the
				// old behaviour) leaked card properties into this dropdown.
				if (is_array($propConfig) && ($propConfig['$ref'] ?? null) === SchemaData::PROPERTY_TYPE_TO_REF['deck']) {
					$deckProperties[] = ['value' => $propName, 'label' => $propName];
				}
			}
		} catch (\Exception) {
			// Schema lookup failed, leave empty
		}

		if ($deckProperties === []) {
			return [[], []];
		}

		$index   = $this->services->collectionReader->fetchIndex($collection);
		$objects = [];
		foreach ($index->objects->all() as $object) {
			$id        = (string)($object['id'] ?? '');
			$title     = $this->indexLabelToString($object['title'] ?? $object['name'] ?? $id, $id);
			$objects[] = ['value' => $id, 'label' => $title];
		}

		return [$objects, $deckProperties];
	}

	/**
	 * Coerce an index-stored label value to a display string. Localized
	 * fields store a locale-keyed dict — pick the default-locale value (or
	 * the first non-empty locale, falling back to the object's ID) so
	 * dropdowns and listings show something sensible without `(string)` on
	 * an array triggering a warning.
	 */
	private function indexLabelToString(mixed $value, string $fallback): string
	{
		if (is_scalar($value)) {
			return (string)$value;
		}

		if (is_array($value) && $value !== []) {
			// Prefer the first non-empty entry — for localized values this
			// gives the most reasonable display. The dict isn't ordered by
			// locale preference at the storage layer; the data shape is
			// transparent here, the caller just wants *some* string.
			foreach ($value as $entry) {
				if (is_scalar($entry) && (string)$entry !== '') {
					return (string)$entry;
				}
			}
		}

		return $fallback;
	}

	/** @param array<string,mixed> $options */
	public function importSchema(array $options = []): string
	{
		$options['api']         = $this->api;
		$options['csrfManager'] = $this->services->csrfManager;

		$form = new ImportSchemaForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function importJumpStart(array $options = []): string
	{
		$options['api']         = $this->api;
		$options['csrfManager'] = $this->services->csrfManager;

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

		// No header option means the table's own default heading; passing the
		// null through was a TypeError.
		return is_string($header) ? $stats->tableByStatus($header) : $stats->tableByStatus();
	}

	/** @param array<string,mixed> $options */
	public function jobqueueByType(array $options = []): string
	{
		$options['api']        = $this->api;
		$options['jobManager'] = $this->jobManager;

		$header = $options['header'] ?? null;
		unset($options['header']);

		$stats = new JobQueueStats(...$options);

		// No header option means the table's own default heading; passing the
		// null through was a TypeError.
		return is_string($header) ? $stats->tableByType($header) : $stats->tableByType();
	}

	/** @param array<string,mixed> $options */
	public function clearqueue(array $options = []): string
	{
		$options['api']         = $this->api;
		$options['csrfManager'] = $this->services->csrfManager;

		$form = new JobQueueForm(...$options);

		return $form->build();
	}

	/** @param array<string,mixed> $options */
	public function devmode(array $options = []): string
	{
		$devModeStatus = $this->devModeManager->getDevModeStatus();

		$options = array_merge([
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
		$fieldHtml = $this->forms->field('toggle', 'devmode', $options);

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
}
