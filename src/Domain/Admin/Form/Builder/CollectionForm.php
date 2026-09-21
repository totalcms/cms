<?php

namespace TotalCMS\Domain\Admin\Form\Builder;

use TotalCMS\Domain\Admin\Form\FormOptions;
use TotalCMS\Domain\Admin\Form\FormServices;
use TotalCMS\Domain\Admin\FormField\SelectField;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Total Form Builder.
 */
class CollectionForm extends TotalForm
{
	public function __construct(FormServices $services, FormOptions $options)
	{
		// A new collection lands on its own edit page; nothing pre-fills the form.
		if ($options->newActions === []) {
			$options = $options->with(newActions: [['action' => 'redirect-object', 'link' => '?id=']]);
		}

		parent::__construct($services, $options->with(data: []));
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
		$this->schemaData = $this->services->schemaFetcher->fetchSchema($this->schema);
	}

	public function getCollectionSchema(): ?SchemaData
	{
		$schema = (string)$this->fields['schema']->getValue();
		if ($schema === '') {
			return null;
		}

		return $this->services->schemaFetcher->fetchSchema($schema);
	}

	private function initCollectionData(): void
	{
		$collectionData = $this->services->collectionFetcher->fetchCollection($this->id);

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
			$schema     = $this->services->schemaFetcher->fetchSchema($this->collectionData->schema);
			$properties = $schema->properties;
			$options    = count($properties) > 0 ? array_keys($properties) : ['id'];
			$sortField->setOptions($options);
		}

		return parent::fieldContent();
	}

	/** @return array<string> */
	private function reservedSchemas(): array
	{
		$schemas = $this->services->schemaLister->listReservedSchemas();
		$schemas = array_map(fn (SchemaData $schema): string => $schema->id, $schemas);
		$ignore  = ['collection', 'schema'];

		if ($this->id === '') {
			// Do not allow a new collection to be created with the blog-legacy schema
			$ignore[] = 'blog-legacy';
		}

		// Filter out schemas not accessible for current edition
		$schemas = array_filter($schemas, fn (string $schema): bool => !in_array($schema, $ignore));

		return array_filter($schemas, $this->services->collectionEditionService->isSchemaAccessible(...));
	}

	/** @return array<string> */
	private function customSchemas(): array
	{
		$schemas   = $this->services->schemaLister->listCustomSchemas();
		$schemaIds = array_map(fn (SchemaData $schema): string => $schema->id, $schemas);

		// Filter out custom schemas not accessible for current edition (Pro only)
		$schemaIds = array_filter($schemaIds, $this->services->collectionEditionService->isSchemaAccessible(...));

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
		$options['name'] = $name;
		$options['form'] = $this;

		// Sub-fields of card/deck composites bring their own complete config
		// from the card's sub-schema iteration — skip parent-schema lookup
		// and collectionData value pull, otherwise a sub-field named
		// `description` (inside the mcp card, say) would inherit the
		// collection's top-level `description` value. Matches the guard
		// pattern in ObjectForm::buildFieldOptions.
		//
		// Two guards because DeckItem and CardField pass different flags:
		//   - CardField goes through TotalForm::subField() which sets
		//     `subfield: true`
		//   - DeckItem calls form->field() directly with `deck_context: true`
		// Without the deck_context arm, a deck item's `id` field on a
		// collection edit form (e.g. tools inside the mcp card) inherits the
		// collection's id value and gets locked as readonly.
		if (isset($options['deck_context']) && $options['deck_context'] === true) {
			return $options;
		}

		if (isset($options['subfield']) && $options['subfield'] === true) {
			return $options;
		}

		// Get the schema settings for a property
		$defaults = $this->schemaData->properties[$name] ?? [];
		$defaults = TotalForm::filterFieldProperties($defaults);
		$defaults = array_merge($defaults, $this->fieldAttributeSettings($name));

		$options  = array_merge($defaults, $options);

		// Move schema-reference keys into settings so card/deck fields can read them.
		// Accept both the canonical `schemaref` and the legacy `deckref` alias.
		if (isset($options['schemaref'])) {
			$options['settings']['schemaref'] = $options['schemaref'];
			unset($options['schemaref']);
		}
		if (isset($options['deckref'])) {
			$options['settings']['schemaref'] ??= $options['deckref'];
			unset($options['deckref']);
		}
		if (isset($options['deckItemLabel'])) {
			$options['settings']['deckItemLabel'] = $options['deckItemLabel'];
			unset($options['deckItemLabel']);
		}

		if ($this->collectionData instanceof CollectionData) {
			$value = $this->collectionData->toArray()[$name] ?? '';
			if (!empty($value)) {
				$options['value'] = $value;
			}
		}

		// Storage format is creation-only. A disabled select is not submitted,
		// and CollectionSaver keeps the stored value when it is absent.
		if ($name === 'format' && $this->collectionData instanceof CollectionData) {
			$options['disabled'] = true;
			$options['readonly'] = true;
			$options['help']     = sprintf('Fixed after creation. Change it with `tcms collection:convert %s --to=%s`.', $this->collectionData->id, $this->collectionData->isMarkdown() ? 'json' : 'markdown');
		}

		return $options;
	}
}
