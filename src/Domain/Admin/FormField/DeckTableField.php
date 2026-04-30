<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Deck Table Form Field - Displays deck items in a spreadsheet-style inline layout.
 * Shares the same backend storage (DeckData), API endpoints, and schema system as DeckField.
 */
class DeckTableField extends FormField
{
	protected string $defaultInputType = 'deck';
	protected string $defaultFieldType = 'deckTable';

	protected string $schemaref     = '';
	protected string $deckItemLabel = '';

	public function init(): void
	{
		$this->uuid      = uniqid();
		$this->field     = $this->defaultFieldType;
		$this->inputType = $this->defaultInputType;
		$this->icon      = false;

		// Extract schema reference from settings (accepts schemaref or legacy deckref)
		$this->schemaref       = PropertyDefinition::extractSchemaRef($this->settings) ?? '';
		$this->deckItemLabel = $this->settings['deckItemLabel'] ?? '${id}';

		// Add cms-hide class if hide setting is true (check both property-level and settings)
		if ($this->hide || (isset($this->settings['hide']) && $this->settings['hide'] === true)) {
			$this->class = trim($this->class . ' cms-hide');
		}
	}

	public function buildFormField(): string
	{
		// Hidden validation input - must be first child of form-group, outside deck-table
		$input = HTMLUtils::inlineElement('input', [
			'id'       => 'field-' . $this->uuid,
			'type'     => 'text',
			'name'     => $this->name,
			'required' => $this->required ? true : null,
			'value'    => $this->name,
		]);

		// Build header row
		$table = $this->buildHeader();

		// Build body with existing items
		$body = '';
		if (is_array($this->value)) {
			foreach ($this->value as $itemId => $itemData) {
				if (is_array($itemData)) {
					$body .= $this->buildRow((string)$itemId, $itemData);
				}
			}
		}
		$table .= HTMLUtils::element('div', $body, ['class' => 'deck-table-body']);

		// Add template for new rows
		if ($this->schemaref !== '') {
			$templateRow = $this->buildRow('', []);
			$table .= HTMLUtils::element('template', $templateRow, ['class' => 'deck-table-template']);
		}

		// Add button to create new rows
		$table .= HTMLUtils::add('Add Item');

		return $input . HTMLUtils::element('div', $table, ['class' => 'deck-table']);
	}

	protected function buildHeader(): string
	{
		$headerCells = HTMLUtils::element('span', '', ['class' => 'deck-table-handle-spacer']);

		try {
			$schemaFetcher = $this->form->getSchemaFetcher();
			$schema        = $schemaFetcher->fetchSchema(SchemaFetcher::extractSchemaId($this->schemaref));

			foreach ($schema->properties as $propertyName => $propertySchema) {
				$settings = $propertySchema['settings'] ?? [];
				if (isset($settings['hide']) && $settings['hide'] === true) {
					continue;
				}

				$label     = $propertySchema['label'] ?? ucfirst($propertyName);
				$fieldType = $propertySchema['field'] ?? 'text';
				$headerCells .= HTMLUtils::element('span', $label, [
					'class'           => 'deck-table-header-cell',
					'data-field-type' => $fieldType,
				]);
			}
		} catch (\Exception) {
			$headerCells .= HTMLUtils::element('span', 'Error loading schema', ['class' => 'deck-table-header-cell']);
		}

		$headerCells .= HTMLUtils::element('span', '', ['class' => 'deck-table-actions-spacer']);

		return HTMLUtils::element('div', $headerCells, ['class' => 'deck-table-header']);
	}

	/**
	 * @param array<string,mixed> $itemData
	 */
	protected function buildRow(string $itemId, array $itemData): string
	{
		// Drag handle
		$handle = HTMLUtils::element('div', '', ['class' => 'sort-handle']);

		// Build cells from schema fields
		$cells = '';
		try {
			$schemaFetcher  = $this->form->getSchemaFetcher();
			$schema         = $schemaFetcher->fetchSchema(SchemaFetcher::extractSchemaId($this->schemaref));
			$requiredFields = $schema->required;

			$metaResolver = $this->form->getMetaResolver();

			foreach ($schema->properties as $propertyName => $propertySchema) {
				$propSettings = $propertySchema['settings'] ?? [];
				$isHidden     = isset($propSettings['hide']) && $propSettings['hide'] === true;

				$fieldValue   = $itemData[$propertyName] ?? '';
				$defaultValue = $propertySchema['default'] ?? '';

				if ($fieldValue === '' && $defaultValue !== '') {
					$fieldValue = $defaultValue;
				}

				// Run settings through the preset pipeline so named presets
				// and type-default presets are applied the same way as for
				// top-level fields and inline DeckItem sub-fields.
				$resolvedSettings = is_array($propertySchema['settings'] ?? null) ? $propertySchema['settings'] : [];
				$resolvedSettings = $metaResolver->resolvePreset($resolvedSettings);
				if ($resolvedSettings === [] && !empty($propertySchema['field'])) {
					$resolvedSettings = $metaResolver->resolveTypePreset((string)$propertySchema['field']);
				}

				$fieldConfig = [
					'field'        => $propertySchema['field'] ?? 'text',
					'label'        => '',
					'help'         => '',
					'default'      => $defaultValue,
					'placeholder'  => $propertySchema['placeholder'] ?? '',
					'options'      => $propertySchema['options'] ?? [],
					'settings'     => $resolvedSettings,
					'value'        => $fieldValue,
					'deck_context' => true,
					'required'     => in_array($propertyName, $requiredFields, true),
				];

				// Extract attribute settings from the resolved settings
				$filteredAttributes = \TotalCMS\Domain\Admin\TotalForm::filterFieldAttributes($resolvedSettings);
				$fieldConfig        = array_merge($fieldConfig, $filteredAttributes);

				// For template rows (empty itemId), keep the default value if present
				if ($itemId === '' && $defaultValue === '') {
					$fieldConfig['value'] = '';
				}

				$cellLabel = $propertySchema['label'] ?? ucfirst($propertyName);
				$fieldType = $propertySchema['field'] ?? 'text';
				$fieldHtml = $this->form->field($propertyName, $fieldConfig);
				$cellClass = 'deck-table-cell' . ($isHidden ? ' cms-hide' : '');
				$cells .= HTMLUtils::element('div', $fieldHtml, [
					'class'           => $cellClass,
					'data-label'      => $cellLabel,
					'data-field-type' => $fieldType,
				]);
			}
		} catch (\Exception) {
			$cells .= HTMLUtils::element('div', 'Error loading schema fields', ['class' => 'deck-table-cell']);
		}

		// Action buttons
		$actions    = HTMLUtils::button('', ['class' => 'trash', 'title' => 'Delete row', 'type' => 'button']);
		$actionsDiv = HTMLUtils::element('div', $actions, ['class' => 'deck-table-actions']);

		return HTMLUtils::element('div', $handle . $cells . $actionsDiv, ['class' => 'deck-table-row']);
	}

	/** @return array<string,string|null> */
	protected function formFieldAttributes(): array
	{
		$attributes = parent::formFieldAttributes();

		// Add schemaref as a data attribute
		if ($this->schemaref !== '') {
			$attributes['data-schemaref'] = $this->schemaref;
		}

		// Add deck item label pattern as a data attribute
		if ($this->deckItemLabel !== '') {
			$attributes['data-deck-label-pattern'] = $this->deckItemLabel;
		}

		return $attributes;
	}
}
