<?php

namespace TotalCMS\Domain\Admin\SchemaField;

use TotalCMS\Utils\HTMLUtils;

class SchemaField
{
	/**
	 * @param array<string,mixed> $settings - JSON settings for the field added to data-options attribute
	 * @param array<mixed> $options - Options for select fields and datalists
	 */
	public function __construct(
		protected string $property,
		protected string $field       = 'text',
		protected string $label       = '',
		protected string $help        = '',
		protected string $placeholder = '',
		protected array $options      = [],
		protected array $settings     = [],
	) {
	}

	private function buildDialog(): string
	{
		// <dialog class="cms-modal small" ></dialog>
		return HTMLUtils::createHTMLElement('dialog', '', ['class' => 'cms-modal small']);
	}

	public function build(): string
	{
		// <div class="schema-field id-field">
		// 	<input autocomplete="off" type="text" name="property" placeholder="name" required>
		// 	<button type="button"></button>
		// 	<dialog class="cms-modal small" ></dialog>
		// </div>

		$inputAttributes = [
			'autocomplete' => 'off',
			'type'         => 'text',
			'name'         => 'property',
			'placeholder'  => 'name',
			'required'     => '',
			// 'disabled'     => '',
			'value'        => $this->property,
		];

		$dialog = $this->buildDialog();
		$button = HTMLUtils::createHTMLElement('button', '', ['type' => 'button']);
		$input  = HTMLUtils::createInlineHTMLElement('input', $inputAttributes);
		$field  = HTMLUtils::createHTMLElement('div', $input . $button . $dialog, [
			'class' => "schema-field {$this->field}-field"
		]);

		return $field;
	}
}
