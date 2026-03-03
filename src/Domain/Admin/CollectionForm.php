<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Admin\FormField\SelectField;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\DataView\Service\DataViewFilter;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

/**
 * Total Form Builder.
 */
class CollectionForm extends TotalForm
{
	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
	 *
	 * @param array<int,array<string,mixed>> $newActions
	 * @param array<int,array<string,mixed>> $editActions
	 * @param array<int,array<string,mixed>> $deleteActions
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
		public string $collection = '',
		public string $id          = '',
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
				'link'   => '?id=',
			],
		],
		protected array $editActions   = [],
		protected array $deleteActions = [],
		protected bool $autosave    = false,
		protected bool $helpOnHover = false,
		protected bool $helpOnFocus = false,
		protected bool $hideID      = false,
		protected bool $useFormGrid = true,
		protected bool $addOnly     = false,
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

		$this->route = '/collections';

		if ($this->id !== '') {
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
		if ($schema === '') {
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
			$customSchemas = $this->customSchemas();
			$options       = ['Reserved Schemas' => $this->reservedSchemas()];

			// Only include Custom Schemas group if there are any
			if (count($customSchemas) > 0) {
				$options = ['Custom Schemas' => $customSchemas] + $options;
			}

			$schemaField->setOptions($options);
			if ($this->method === 'PUT') {
				// disable schema for edit mode
				$schemaField->disable();
			}
		}
		$sortField = $this->fields['sortBy'];
		if ($this->collectionData instanceof CollectionData && $sortField instanceof SelectField) {
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
		$schemas = array_map(fn (SchemaData $schema): string => $schema->id, $schemas);
		$ignore  = ['collection', 'schema'];

		if ($this->id === '') {
			// Do not allow a new collection to be created with the blog-legacy schema
			$ignore[] = 'blog-legacy';
		}

		// Filter out schemas not accessible for current edition
		$schemas = array_filter($schemas, fn (string $schema): bool => !in_array($schema, $ignore));

		return array_filter($schemas, $this->collectionEditionService->isSchemaAccessible(...));
	}

	/** @return array<string> */
	private function customSchemas(): array
	{
		$schemas   = $this->schemaLister->listCustomSchemas();
		$schemaIds = array_map(fn (SchemaData $schema): string => $schema->id, $schemas);

		// Filter out custom schemas not accessible for current edition (Pro only)
		$schemaIds = array_filter($schemaIds, $this->collectionEditionService->isSchemaAccessible(...));

		// Sort alphabetically
		sort($schemaIds);

		return $schemaIds;
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

		if ($this->collectionData instanceof CollectionData) {
			$value = $this->collectionData->toArray()[$name] ?? '';
			if (!empty($value)) {
				$options['value'] = $value;
			}
		}

		return $options;
	}
}
