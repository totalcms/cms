<?php

namespace TotalCMS\Domain\Admin\DeckItem;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * DeckItem - Represents a single item in a deck field.
 * Similar to SchemaField but for deck data items.
 */
class DeckItem
{
	protected SchemaFetcher $schemaFetcher;

	/**
	 * @param array<string,mixed> $itemData
	 */
	public function __construct(
		protected TotalForm $form,
		protected string $itemId,
		protected array $itemData = [],
		protected string $deckref = '',
	) {
		$this->schemaFetcher = $form->getSchemaFetcher();
	}

	public function build(): string
	{
		$inputAttributes = [
			'autocomplete' => 'off',
			'type'         => 'text',
			'name'         => 'deck-item-id',
			'placeholder'  => 'item-id',
			'required'     => '',
			'value'        => $this->itemId,
		];

		// For existing deck items, make the ID field readonly
		if ($this->itemId !== '') {
			$inputAttributes['readonly'] = '';
		}

		$dialog  = $this->buildDialog();
		$input   = HTMLUtils::inlineElement('input', $inputAttributes);
		$buttons = HTMLUtils::button('', ['class' => 'edit sort-handle', 'title' => "Edit {$this->itemId} item"]);

		// Add duplicate and delete buttons
		$buttons .= HTMLUtils::button('', ['class' => 'duplicate', 'title' => "Duplicate {$this->itemId} item"]);
		$buttons .= HTMLUtils::button('', ['class' => 'trash', 'title' => "Delete {$this->itemId} item"]);

		return HTMLUtils::element('div', $input . $buttons . $dialog, [
			'class'        => "deck-item deck-item-{$this->itemId}",
			'data-item-id' => $this->itemId,
		]);
	}

	protected function buildDialog(): string
	{
		$content = $this->buildDialogContent();

		$close = HTMLUtils::button('Close', ['class' => 'close']);

		$dialogContent  = HTMLUtils::scroller($content);
		$dialogContent .= HTMLUtils::element('section', $close);

		return HTMLUtils::dialog($dialogContent, 'small');
	}

	protected function buildDialogContent(): string
	{
		$content = '';

		// Generate form fields based on deckref schema
		if ($this->deckref !== '') {
			$content .= $this->buildSchemaBasedFields();
		}

		return $content;
	}

	protected function buildSchemaBasedFields(): string
	{
		$content = '';

		try {
			// Fetch the schema for this deck
			$schema = $this->schemaFetcher->fetchSchema(SchemaFetcher::extractSchemaId($this->deckref));

			// Generate form fields for each property in the schema
			foreach ($schema->properties as $propertyName => $propertySchema) {
				$fieldValue = $this->itemData[$propertyName] ?? '';
				$defaultValue = $propertySchema['default'] ?? '';

				// Apply default value if field value is empty (not set in itemData)
				// This handles new deck items where defaults should be used
				if ($fieldValue === '' && $defaultValue !== '') {
					$fieldValue = $defaultValue;
				}

				$fieldConfig = [
					'field'        => $propertySchema['field'] ?? 'text',
					'label'        => $propertySchema['label'] ?? ucfirst($propertyName),
					'help'         => $propertySchema['help'] ?? '',
					'default'      => $defaultValue,
					'placeholder'  => $propertySchema['placeholder'] ?? '',
					'options'      => $propertySchema['options'] ?? [],
					'settings'     => $propertySchema['settings'] ?? [],
					'value'        => $fieldValue,
					'deck_context' => true, // Indicate this field is within a deck item
				];

				// Extract attribute settings (min, max, pattern, etc.) from settings and merge at top level
				// This ensures they're available as constructor parameters for FormField
				$attributeSettings = $propertySchema['settings'] ?? [];
				$filteredAttributes = TotalForm::filterFieldAttributes($attributeSettings);
				$fieldConfig = array_merge($fieldConfig, $filteredAttributes);

				// For template items (empty itemId), keep the default value if present
				// The template is cloned by JavaScript to create new items, so defaults should be preserved
				// Only clear value if there's no default (to show placeholder instead)
				if ($this->itemId === '' && $defaultValue === '') {
					$fieldConfig['value'] = '';
				}

				$content .= $this->form->field($propertyName, $fieldConfig);
			}
		} catch (\Exception $e) {
			// If schema can't be loaded, show a simple text field
			$content .= HTMLUtils::element(
				'p',
				'Unable to load schema fields: ' . htmlspecialchars($e->getMessage()),
				['class' => 'error']
			);
		}

		return $content;
	}
}
