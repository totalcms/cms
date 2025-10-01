<?php

namespace TotalCMS\Domain\Admin\PropertyField;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

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
		protected string $default     = '',
		protected string $label       = '',
		protected string $help        = '',
		protected string $placeholder = '',
		protected array $options      = [],
		protected array $settings     = [],
	) {
	}

	protected function topFieldInfo(): string
	{
		return $this->form->field('field', [
			'field'       => 'select',
			'label'       => 'Type of Form Field',
			'placeholder' => 'Select a field type',
			'help'        => 'The type form field that this field will use',
			'value'       => $this->field,
			// 'disabled'    => ($this->property === 'id'), // Disable field type for id property
			'options'     => TotalForm::FIELDS_BY_TYPE,
			'settings'    => ['clearValue' => false],
		]);
	}

	protected function buildFormInfo(): string
	{
		$formInfo = $this->form->field('label', [
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
			'rows'        => 2,
			'placeholder' => 'Enter help text',
			'help'        => 'The help text that will be added to the field form',
			'value'       => $this->help,
		]);

		return HTMLUtils::details('Form Info', $formInfo, '', ['open' => '']);
	}

	protected function buildSettingsOptions(): string
	{
		$settings = $this->form->field('settings', [
			'field'       => 'json',
			'label'       => 'Settings',
			'placeholder' => '{ "key": "value" }',
			'help'        => 'The settings for this field in valid JSON format',
			'value'       => $this->settings === [] ? '' : json_encode($this->settings, JSON_PRETTY_PRINT),
			'rows'        => 10,
		]);
		$settings .= $this->form->field('options', [
			'field'       => 'json',
			'label'       => 'Options &amp; Datalist',
			'placeholder' => '[ "option1", "option2", "option3" ]',
			'help'        => 'The options for select fields and datalists in valid JSON format.',
			'value'       => $this->options === [] ? '' : json_encode($this->options, JSON_PRETTY_PRINT),
			'rows'        => 10,
		]);

		return HTMLUtils::details('Settings &amp; Options', $settings);
	}

	protected function buildDialog(string $content = ''): string
	{
		$content .= $this->topFieldInfo();
		$content .= $this->buildFormInfo();
		$content .= $this->buildSettingsOptions();

		$close = HTMLUtils::button('Close', ['class' => 'close']);

		$content  = HTMLUtils::scroller($content);
		$content .= HTMLUtils::element('section', $close);

		return HTMLUtils::dialog($content, 'small');
	}

	protected function buildPropertyField(string $property = '', string $field = ''): string
	{
		$inputAttributes = [
			'type'         => 'hidden',
			'name'         => 'property',
			'value'        => $property,
		];

		$dialog = $this->buildDialog();
		$input  = HTMLUtils::inlineElement('input', $inputAttributes);
		$label  = HTMLUtils::element('label', $property);

		$buttons  = HTMLUtils::button('', ['class' => 'edit', 'title' => "Edit {$property} property"]);
		$buttons .= HTMLUtils::button('', ['class' => 'trash', 'title' => "Delete {$property} property"]);

		return HTMLUtils::element('div', $input . $label . $buttons . $dialog, [
			'class' => "property-field {$field}-field",
		]);
	}

	public function template(): string
	{
		return HTMLUtils::element('template', $this->buildPropertyField(), ['class' => 'property-template']);
	}

	public function build(): string
	{
		return $this->buildPropertyField($this->property, $this->field);
	}
}
