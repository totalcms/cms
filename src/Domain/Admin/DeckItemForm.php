<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

/**
 * Deck Item Form Builder.
 * Builds forms for creating/editing individual deck items.
 */
class DeckItemForm extends TotalForm
{
	protected string $deckref = '';

	/** @var array<string,mixed> */
	protected array $itemData = [];

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
	 *
	 * @param array<int,array<string,mixed>> $newActions Array of action objects
	 * @param array<int,array<string,mixed>> $editActions Array of action objects
	 * @param array<int,array<string,mixed>> $deleteActions Array of action objects
	 */
	public function __construct(
		ObjectFetcher $objectFetcher,
		CollectionFetcher $collectionFetcher,
		CollectionLister $collectionLister,
		IndexReader $collectionReader,
		IndexFilter $indexFilter,
		SchemaFetcher $schemaFetcher,
		SchemaLister $schemaLister,
		AccessGroupLister $accessGroupLister,
		CollectionEditionService $collectionEditionService,
		EditionFeatureService $editionFeatures,
		CSRFTokenManager $csrfManager,
		Config $config,
		PropertyMetaResolver $metaResolver,
		string $api,
		string $collection             = '',
		string $id                     = '',
		protected string $property     = '',
		protected string $itemId       = '',
		string $method                 = 'POST',
		string $class                  = '',
		string $buildError             = '',
		string $helpStyle              = '',
		string $save                   = '',
		string $delete                 = '',
		string $formType               = 'deck',
		string $schema                 = '',
		string $route                  = '',
		array $newActions              = [],
		array $editActions             = [],
		array $deleteActions           = [],
		bool $autosave                 = false,
		bool $helpOnHover              = false,
		bool $helpOnFocus              = false,
		bool $hideID                   = false,
		bool $useFormGrid              = true,
		bool $addOnly                  = false,
	) {
		parent::__construct(
			objectFetcher            : $objectFetcher,
			collectionFetcher        : $collectionFetcher,
			collectionLister         : $collectionLister,
			collectionReader         : $collectionReader,
			indexFilter              : $indexFilter,
			schemaFetcher            : $schemaFetcher,
			schemaLister             : $schemaLister,
			accessGroupLister        : $accessGroupLister,
			collectionEditionService : $collectionEditionService,
			editionFeatures          : $editionFeatures,
			api                      : $api,
			collection        : $collection,
			id                : $id,
			method            : $method,
			class             : $class,
			buildError        : $buildError,
			helpStyle         : $helpStyle,
			save              : $save,
			delete            : $delete,
			formType          : $formType,
			schema            : $schema,
			route             : $route,
			newActions        : $newActions,
			editActions       : $editActions,
			deleteActions     : $deleteActions,
			autosave          : $autosave,
			helpOnHover       : $helpOnHover,
			helpOnFocus       : $helpOnFocus,
			hideID            : $hideID,
			useFormGrid       : $useFormGrid,
			addOnly           : $addOnly,
			csrfManager       : $csrfManager,
			config            : $config,
			metaResolver      : $metaResolver,
		);
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	protected function init(): void
	{
		if ($this->property === '') {
			$this->buildError = 'Property name is required for DeckItemForm';

			return;
		}

		// Initialize collection data to get schema
		$this->initCollectionData();

		// Auto-detect deckref from schema
		$this->detectDeckref();

		// Replace schemaData with deck schema for all form operations
		if ($this->deckref !== '') {
			$this->schemaData = $this->schemaFetcher->fetchSchema(SchemaFetcher::extractSchemaId($this->deckref));
		}

		if ($this->id === '' && isset($_GET['id'])) {
			$this->id = $_GET['id'];
		}
		if ($this->id === '') {
			$this->buildError = 'No Object ID found. It is required for DeckItemForm';

			return;
		}

		if ($this->itemId === '' && isset($_GET['itemId'])) {
			$this->itemId = $_GET['itemId'];
		}
		if ($this->addOnly) {
			$this->itemId = '';
		}

		// Load existing deck item data if editing
		if ($this->itemId !== '') {
			$this->loadDeckItemData();
			$this->route = "/collections/{$this->collection}/{$this->id}/{$this->property}/deck/{$this->itemId}";
			if ($this->method === 'POST') {
				$this->method = 'PUT';
			}

			return;
		}

		// Creating new deck item
		$this->route = "/collections/{$this->collection}/{$this->id}/{$this->property}/deck";
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	protected function buildFieldOptions(string $name, array $options = []): array
	{
		$options['name'] = $name;
		$options['form'] = $this;

		// Get defaults from deck schema
		$defaults = $this->deckFieldDefaults($name);

		// Merge in attribute settings (min, max, pattern, etc.) - CRITICAL for deck fields
		$defaults = array_merge($defaults, $this->fieldAttributeSettings($name));

		// Set required from deck schema
		if (!isset($options['required'])) {
			$defaults['required'] = $this->isDeckFieldRequired($name);
		}

		// Set value from deck item data
		if ($this->itemId !== '' && isset($this->itemData[$name])) {
			$value = $this->itemData[$name];
			// Use strict checks to preserve zero values (0, 0.0, '0')
			if ($value !== '') {
				$options['value'] = $value;
			}
		}

		// Special handling for 'id' field - this is the deck item ID
		if ($name === 'id') {
			$options['value']    = $this->itemId;
			$options['required'] = true;
			// Make readonly when editing
			if ($this->itemId !== '') {
				$options['readonly'] = true;
			}
		}

		return array_merge($defaults, $options);
	}

	/**
	 * Override to extract field attributes from deck schema settings.
	 * This is critical for deck fields to get min, max, pattern, etc.
	 *
	 * @return array<string,mixed>
	 */
	protected function fieldAttributeSettings(string $property): array
	{
		if ($this->deckref === '' || !$this->schemaData instanceof SchemaData) {
			return [];
		}

		// Get the deck schema settings for this property
		$schema = $this->schemaData->properties[$property]['settings'] ?? [];

		return self::filterFieldAttributes($schema);
	}

	/**
	 * Get field defaults from deck schema.
	 * Note: $this->schemaData is already the deck schema (swapped in init()).
	 *
	 * @return array<string,mixed>
	 */
	private function deckFieldDefaults(string $property): array
	{
		if ($this->deckref === '' || !$this->schemaData instanceof SchemaData) {
			return [];
		}

		$propertySchema = $this->schemaData->properties[$property] ?? [];

		return TotalForm::filterFieldProperties($propertySchema);
	}

	/**
	 * Check if a deck field is required.
	 * Note: $this->schemaData is already the deck schema (swapped in init()).
	 */
	private function isDeckFieldRequired(string $property): bool
	{
		if ($this->deckref === '' || !$this->schemaData instanceof SchemaData) {
			return false;
		}

		return in_array($property, $this->schemaData->required);
	}

	/**
	 * Auto-detect deckref from the property schema.
	 */
	private function detectDeckref(): void
	{
		if (!$this->schemaData instanceof SchemaData) {
			return;
		}

		// Check schema properties for deckref
		$propertySchema = $this->schemaData->properties[$this->property] ?? [];

		// Check for deckref in top-level or settings
		$this->deckref = $propertySchema['deckref']
			?? $propertySchema['settings']['deckref']
			?? $this->collectionData->properties[$this->property]['deckref']
			?? $this->collectionData->properties[$this->property]['settings']['deckref']
			?? '';
	}

	/**
	 * Load existing deck item data from parent object.
	 */
	private function loadDeckItemData(): void
	{
		if ($this->id === '' || $this->itemId === '' || $this->property === '') {
			return;
		}

		$object      = $this->objectFetcher->fetchObject($this->collection, $this->id);
		$objectArray = $object->toArray();
		$deckData    = $objectArray[$this->property] ?? [];

		if (is_array($deckData) && isset($deckData[$this->itemId])) {
			$this->itemData = $deckData[$this->itemId];
		}
	}

	/**
	 * Initialize collection data to get schema.
	 */
	private function initCollectionData(): void
	{
		$collectionData = $this->collectionFetcher->fetchCollection($this->collection);

		if (!$collectionData instanceof CollectionData) {
			$this->buildError = "Collection {$this->collection} not found for DeckItemForm";

			return;
		}

		$this->collectionData = $collectionData;
		$this->schema         = $this->collectionData->schema;
		$this->schemaData     = $this->schemaFetcher->fetchSchema($this->schema);
	}

	/**
	 * Override addField to check deck schema instead of parent schema.
	 * Since we swap schemaData to deck schema in init(), this just adds validation.
	 *
	 * @param array<string,mixed> $options
	 */
	public function addField(string $name, array $options = []): void
	{
		// Check if field exists in deck schema (schemaData is already the deck schema)
		if (!isset($this->schemaData->properties[$name])) {
			return;
		}

		parent::addField($name, $options);
	}

	/**
	 * Auto-build form fields from deck schema.
	 * Note: $this->schemaData is already the deck schema (swapped in init()).
	 */
	protected function addFieldsFromSchema(): void
	{
		if ($this->deckref === '') {
			$this->buildError = "No deckref found for property '{$this->property}'";

			return;
		}

		$schemaData = $this->schemaData;
		if (!$schemaData instanceof SchemaData) {
			$this->buildError = "Deck schema not found: {$this->deckref}";

			return;
		}

		// Add all other fields from deck schema (schemaData is already the deck schema)
		foreach (array_keys($schemaData->properties) as $propertyName) {
			// Skip ID field in AddOnly forms if it has autogen configured
			if ($propertyName === 'id' && $this->addOnly) {
				$settings = $schemaData->properties[$propertyName]['settings'] ?? [];
				if (isset($settings['autogen']) && !empty($settings['autogen'])) {
					continue; // Skip this field - ID will be auto-generated
				}
			}

			$this->addField($propertyName);
		}
	}
}
