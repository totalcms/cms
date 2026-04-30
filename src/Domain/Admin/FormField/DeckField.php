<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\DeckItem\DeckItem;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Data\PropertyDefinition;

/**
 * Deck Form Field - Manages a collection of deck items.
 */
class DeckField extends FormField
{
	protected string $defaultInputType = 'deck';
	protected string $defaultFieldType = 'deck';

	/** @var array<string,DeckItem> */
	protected array $deckItems = [];

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

		// Initialize deck items from value
		if (is_array($this->value)) {
			foreach ($this->value as $itemId => $itemData) {
				if (is_array($itemData)) {
					$this->deckItems[(string)$itemId] = $this->createDeckItem($itemId, $itemData);
				}
			}
		}

		// Add cms-hide class if hide setting is true (check both property-level and settings)
		if ($this->hide || (isset($this->settings['hide']) && $this->settings['hide'] === true)) {
			$this->class = trim($this->class . ' cms-hide');
		}
	}

	public function buildFormField(): string
	{
		$content = HTMLUtils::inlineElement('input', [
			'id'       => 'field-' . $this->uuid,
			'type'     => 'text',
			'name'     => $this->name,
			'required' => $this->required ? true : null,
			'value'    => $this->name,
		]);

		// Build each deck item
		foreach ($this->deckItems as $deckItem) {
			$content .= $deckItem->build();
		}

		// Add template for new items
		if ($this->schemaref !== '') {
			$template = $this->createDeckItem('', []);
			$content .= HTMLUtils::element('template', $template->build(), ['class' => 'deck-template']);
		}

		// Add button to create new deck items
		$content .= HTMLUtils::add('Add Item');

		return $content;
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

	/**
	 * Create a new DeckItem instance.
	 *
	 * @param array<string,mixed> $itemData
	 */
	protected function createDeckItem(string $itemId, array $itemData): DeckItem
	{
		return new DeckItem(
			form            : $this->form,
			itemId          : $itemId,
			itemData        : $itemData,
			schemaref       : $this->schemaref,
			deckItemLabel   : $this->deckItemLabel,
		);
	}
}
