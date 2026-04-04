<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

/**
 * Form for importing data into a deck property of an object.
 * Renders object select, deck property select, update checkbox, and file input.
 */
readonly class ImportDeckForm implements \Stringable
{
	private SimpleForm $simpleform;

	/**
	 * @param array<array{value:string,label:string}> $objects       Object options for select
	 * @param array<array{value:string,label:string}> $deckProperties Deck property options for select
	 */
	public function __construct(
		private string $api,
		private string $collection,
		private array $objects = [],
		private array $deckProperties = [],
		private string $input = 'csv',
		private string $label = 'Import into Deck',
		private bool $update = false,
		private ?CSRFTokenManager $csrfManager = null,
	) {
		$this->simpleform = new SimpleForm(
			api         : $this->api,
			route       : "/import/collections/{$this->collection}/deck/{$this->input}",
			method      : 'POST',
			label       : $this->label,
			class       : 'import-form',
			refresh     : true,
			csrfManager : $this->csrfManager,
		);
	}

	private function objectField(): string
	{
		$label = HTMLUtils::element('label', 'Object', ['for' => 'object']);

		$options = HTMLUtils::element('option', 'Select an object...', ['value' => '']);
		foreach ($this->objects as $object) {
			$options .= HTMLUtils::element('option', htmlspecialchars($object['label']), ['value' => $object['value']]);
		}

		$select = HTMLUtils::element('select', $options, [
			'name'     => 'object',
			'id'       => 'object',
			'required' => true,
		]);

		return HTMLUtils::element('div', $label . $select);
	}

	private function propertyField(): string
	{
		$label = HTMLUtils::element('label', 'Deck Property', ['for' => 'property']);

		$options = HTMLUtils::element('option', 'Select a deck property...', ['value' => '']);
		foreach ($this->deckProperties as $prop) {
			$options .= HTMLUtils::element('option', htmlspecialchars($prop['label']), ['value' => $prop['value']]);
		}

		$select = HTMLUtils::element('select', $options, [
			'name'     => 'property',
			'id'       => 'property',
			'required' => true,
		]);

		return HTMLUtils::element('div', $label . $select);
	}

	private function fileField(): string
	{
		$label = HTMLUtils::element('label', strtoupper($this->input) . ' File', ['for' => $this->input]);
		$file  = HTMLUtils::inlineElement('input', [
			'type' => 'file',
			'name' => $this->input,
			'id'   => $this->input,
		]);

		return HTMLUtils::element('div', $label . $file);
	}

	private function updateField(): string
	{
		$label = HTMLUtils::element('label', 'Update Existing Items', ['for' => 'deck-update']);

		$checkAttrs = [
			'type' => 'checkbox',
			'name' => 'update',
			'id'   => 'deck-update',
		];
		if ($this->update) {
			$checkAttrs['checked'] = 'checked';
		}
		$check = HTMLUtils::inlineElement('input', $checkAttrs);

		return HTMLUtils::element('div', $check . $label, ['class' => 'checkbox-field']);
	}

	public function build(): string
	{
		$content = $this->objectField()
			. $this->propertyField()
			. $this->fileField()
			. $this->updateField();

		return $this->simpleform->build($content);
	}

	public function __toString(): string
	{
		return $this->build();
	}
}
