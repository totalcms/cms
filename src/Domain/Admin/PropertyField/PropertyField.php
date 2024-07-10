<?php

namespace TotalCMS\Domain\Admin\PropertyField;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Utils\HTMLUtils;

class PropertyField
{
	/**
	 * @param array<string,mixed> $settings - JSON settings for the field added to data-options attribute
	 * @param array<mixed> $options - Options for select fields and datalists
	 */
	public function __construct(
		protected TotalForm $form,
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
		$formInfo = $this->form->field('field', [
			'field'       => 'select',
			'label'       => 'Field Type',
			'placeholder' => 'Select a field type',
			'help'        => 'The type form field that this field will use',
			'value'       => $this->field,
			'disabled'    => ($this->property === 'id'), // Disable field type for id property
			'options'     => TotalForm::FIELDS,
		]);
		$formInfo .= $this->form->field('label', [
			'field'       => 'text',
			'label'       => 'Label',
			'placeholder' => 'Enter a label',
			'help'        => 'The label that will be added to the field form',
			'value'       => $this->label,
		]);
		$formInfo .= $this->form->field('placeholder', [
			'field'       => 'text',
			'label'       => 'Placeholder',
			'placeholder' => 'Enter a placeholder',
			'help'        => 'The placeholder text that will be added to the field form',
			'value'       => $this->placeholder,
		]);
		$formInfo .= $this->form->field('help', [
			'field'       => 'textarea',
			'label'       => 'Help',
			'placeholder' => 'Enter help text',
			'help'        => 'The help text that will be added to the field form',
			'value'       => $this->help,
		]);
		$formInfo = HTMLUtils::details('Form Info', $formInfo);

		$settings = $this->form->field('settings', [
			'field'       => 'textarea',
			'label'       => 'Settings',
			'placeholder' => '{ "key": "value" }',
			'help'        => 'The settings for this field in valid JSON format',
			'value'       => empty($this->settings) ? '' : json_encode($this->settings, JSON_PRETTY_PRINT),
		]);
		$settings .= $this->form->field('options', [
			'field'       => 'textarea',
			'label'       => 'Options &amp; Datalist',
			'placeholder' => '[ "option1", "option2", "option3" ]',
			'help'        => 'The options for select fields and datalists in valid JSON format.',
			'value'       => empty($this->options) ? '' : json_encode($this->options, JSON_PRETTY_PRINT),
		]);
		$settings = HTMLUtils::details('Settings &amp; Options', $settings);

		$close = HTMLUtils::button('Close', ['class' => 'close']);

		$content  = HTMLUtils::scroller($formInfo . $settings);
		$content .= HTMLUtils::element('section', $close);

		return HTMLUtils::dialog($content, 'small');
	}

	public function build(): string
	{
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
		$button = HTMLUtils::element('button', '', ['type' => 'button']);
		$input  = HTMLUtils::inlineElement('input', $inputAttributes);
		$field  = HTMLUtils::element('div', $input . $button . $dialog, [
			'class' => "property-field {$this->field}-field"
		]);

		return $field;
	}
}
