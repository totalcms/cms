<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\DeckItem\DeckItem;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Deck Form Field - Manages a collection of deck items.
 */
final class DeckField extends FormField
{
	protected string $defaultInputType = 'deck';
	protected string $defaultFieldType = 'deck';

	/** @var array<string,DeckItem> */
	protected array $deckItems = [];

	protected string $deckref = '';

	public function init(): void
	{
		$this->uuid      = uniqid();
		$this->field     = $this->defaultFieldType;
		$this->inputType = $this->defaultInputType;
		$this->icon      = false;

		// Extract deckref from settings if available
		$this->deckref = $this->settings['deckref'] ?? '';

		// Initialize deck items from value
		if (is_array($this->value)) {
			foreach ($this->value as $itemId => $itemData) {
				if (is_array($itemData)) {
					$this->deckItems[(string)$itemId] = $this->createDeckItem($itemId, $itemData);
				}
			}
		}
	}

	public function buildFormField(): string
	{
		$content = '';

		// Hidden input to store the field name
		$content .= HTMLUtils::inlineElement('input', [
			'type' => 'hidden',
			'name' => $this->name,
		]);

		// Build each deck item
		foreach ($this->deckItems as $deckItem) {
			$content .= $deckItem->build();
		}

		// Add template for new items
		if (!empty($this->deckref)) {
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

		// Add deckref as a data attribute
		if (!empty($this->deckref)) {
			$attributes['data-deckref'] = $this->deckref;
		}

		return $attributes;
	}

	/**
	 * Create a new DeckItem instance.
	 *
	 * @param string $itemId
	 * @param array<string,mixed> $itemData
	 *
	 * @return DeckItem
	 */
	protected function createDeckItem(string $itemId, array $itemData): DeckItem
	{
		return new DeckItem(
			form     : $this->form,
			itemId   : $itemId,
			itemData : $itemData,
			deckref  : $this->deckref,
		);
	}
}
