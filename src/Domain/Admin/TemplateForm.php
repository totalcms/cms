<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\DataView\Service\DataViewFilter;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateFactory;
use TotalCMS\Support\Config;

/**
 * Total Form Builder for Templates.
 */
class TemplateForm extends TotalForm
{
	public TemplateData $templateData;

	// TODO: Refactor to only use services that it needs. May need to refactor TotalForm first.

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
	 *
	 * @param array<int,array<string,mixed>> $newActions
	 * @param array<int,array<string,mixed>> $deleteActions
	 * @param array<int,array<string,mixed>> $editActions
	 * @param array<string,mixed>  $data
	 */
	public function __construct(
		protected ObjectFetcher $objectFetcher,
		protected CollectionFetcher $collectionFetcher,
		protected CollectionLister $collectionLister,
		protected IndexReader $collectionReader,
		protected IndexFilter $indexFilter,
		protected SchemaFetcher $schemaFetcher,
		public SchemaLister $schemaLister,
		protected AccessGroupLister $accessGroupLister,
		protected CollectionEditionService $collectionEditionService,
		protected EditionFeatureService $editionFeatures,
		protected TemplateRepository $templateRepository,
		protected DataViewFilter $dataViewFilter,
		protected CSRFTokenManager $csrfManager,
		protected Config $config,
		protected PropertyMetaResolver $metaResolver,
		public string $api,
		public string $path         = '',
		public string $collection   = '',
		public string $id           = '',
		protected string $method      = 'POST',
		protected string $class       = '',
		protected string $buildError  = '',
		protected string $helpStyle   = '',
		protected string $save        = '',
		protected string $delete      = '',
		protected string $formType    = '',
		protected string $schema      = '',
		protected string $route       = '',
		protected array $newActions    = [
			[
				'action' => 'redirect-object',
				'link'   => 'templates/',
			],
		],
		protected array $editActions   = [],
		protected array $deleteActions = [],
		protected array $data         = [],
		protected bool $autosave      = false,
		protected bool $helpOnHover   = false,
		protected bool $helpOnFocus   = false,
		protected bool $hideID        = false,
		protected bool $useFormGrid   = true,
		protected bool $addOnly       = false,
	) {
		parent::__construct(
			$objectFetcher,
			$collectionFetcher,
			$collectionLister,
			$collectionReader,
			$indexFilter,
			$schemaFetcher,
			$schemaLister,
			$accessGroupLister,
			$collectionEditionService,
			$editionFeatures,
			$dataViewFilter,
			$csrfManager,
			$config,
			$metaResolver,
			$api,
			$collection,
			$id,
			$method,
			$class,
			$buildError,
			$helpStyle,
			$save,
			$delete,
			$formType,
			$schema,
			$route,
			$newActions,
			$editActions,
			$deleteActions,
			[], // data
			$autosave,
			$helpOnHover,
			$helpOnFocus,
			$hideID,
			$useFormGrid,
			$addOnly,
		);
	}

	protected function init(): void
	{
		parent::init();

		$this->route = '/templates';

		// Editing existing template
		if ($this->path !== '') {
			[$folder, $templateId] = TemplateRepository::parsePath($this->path);

			$this->route  = '/templates/' . $this->path;
			$this->method = 'PUT';
			$this->id     = $this->path; // Use full path as ID

			// Fetch the template
			$this->templateData = $this->templateRepository->fetchTemplate($templateId, $folder);
		}

		// Duplicate Template
		if ($this->path === '' && $this->data !== []) {
			$this->templateData = TemplateFactory::generateTemplate(
				$this->data['id'] ?? '',
				$this->data['template'] ?? ''
			);
		}

		$this->formType   = 'template';
		$this->schema     = 'template';
		$this->schemaData = $this->schemaFetcher->fetchSchema($this->schema);
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

		$options = array_merge($defaults, $options);

		// Set the name of the field
		$options['name'] = $name;

		// Setup communication between the field and the form
		$options['form'] = $this;

		// Set values from template data
		if (isset($this->templateData)) {
			if ($name === 'id') {
				// For editing, use the full path; for new/duplicate, use just the ID
				$options['value'] = $this->path !== '' ? $this->path : $this->templateData->id;
			} elseif ($name === 'template') {
				$options['value'] = $this->templateData->contents;
			}
		}

		return $options;
	}
}
