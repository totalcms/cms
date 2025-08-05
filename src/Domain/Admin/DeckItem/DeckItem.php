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
		if (!empty($this->itemId)) {
			$inputAttributes['readonly'] = '';
		}


		$dialog  = $this->buildDialog();
		$input   = HTMLUtils::inlineElement('input', $inputAttributes);
		$buttons = HTMLUtils::button('', ['class' => 'edit', 'title' => "Edit {$this->itemId} item"]);

		// Add duplicate and delete buttons
		$buttons .= HTMLUtils::button('', ['class' => 'duplicate', 'title' => "Duplicate {$this->itemId} item"]);
		$buttons .= HTMLUtils::button('', ['class' => 'trash', 'title' => "Delete {$this->itemId} item"]);

		$field = HTMLUtils::element('div', $input . $buttons . $dialog, [
			'class' => "deck-item deck-item-{$this->itemId}",
			'data-item-id' => $this->itemId,
		]);

		return $field;
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
		if (!empty($this->deckref)) {
			$content .= $this->buildSchemaBasedFields();
		}

		return $content;
	}

	protected function buildSchemaBasedFields(): string
	{
		$content = '';

		try {
			// Fetch the schema for this deck
			$schema = $this->schemaFetcher->fetchSchema($this->extractSchemaId($this->deckref));

			// Generate form fields for each property in the schema
			foreach ($schema->properties as $propertyName => $propertySchema) {
				$fieldValue = $this->itemData[$propertyName] ?? '';

				$fieldConfig = [
					'field' => $propertySchema['field'] ?? 'text',
					'label' => $propertySchema['label'] ?? ucfirst($propertyName),
					'help'  => $propertySchema['help'] ?? '',
					'value' => $fieldValue,
					'deck_context' => true, // Indicate this field is within a deck item
				];

				// For template items (empty itemId), ensure fields start empty
				if (empty($this->itemId)) {
					$fieldConfig['value'] = '';
				}

				// Add placeholder if available
				if (isset($propertySchema['placeholder'])) {
					$fieldConfig['placeholder'] = $propertySchema['placeholder'];
				}

				// Add options for select fields
				if (isset($propertySchema['options'])) {
					$fieldConfig['options'] = $propertySchema['options'];
				}

				// Add settings
				if (isset($propertySchema['settings'])) {
					$fieldConfig['settings'] = $propertySchema['settings'];
				}

				$content .= $this->form->field($propertyName, $fieldConfig);
			}
		} catch (\Exception $e) {
			// If schema can't be loaded, show a simple text field
			$content .= HTMLUtils::element('p',
				'Unable to load schema fields: ' . htmlspecialchars($e->getMessage()),
				['class' => 'error']
			);
		}

		return $content;
	}

	/**
	 * Extract schema ID from deckref URL.
	 *
	 * @param string $deckref
	 * @return string
	 */
	protected function extractSchemaId(string $deckref): string
	{
		// Extract schema ID from URL like "https://www.totalcms.co/schemas/custom/features.json"
		$path = parse_url($deckref, PHP_URL_PATH);
		if ($path) {
			return basename($path, '.json');
		}

		return $deckref;
	}
}