<?php

namespace TotalCMS\Domain\Admin\PropertyField;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Utils\HTMLUtils;

class SchemaField extends PropertyField
{
	/**
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
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
		protected string $factory     = '',
		protected string $default     = '',
		protected array $options      = [],
		protected array $settings     = [],
	) {
	}

	protected function buildSchemaOptions(): string
	{
		$content = $this->form->field('factory', [
			'field'       => 'text',
			'label'       => 'Factory',
			'placeholder' => 'text(300)',
			'help'        => 'The factory that will be used to generate the field form. See docs for more info.',
			'value'       => $this->factory ?? '',
		]);
		$content .= $this->form->field('default', [
			'field'       => 'text',
			'label'       => 'Default Value',
			'placeholder' => '',
			'help'        => 'The default value for this property when an object is saved without a value',
			'value'       => $this->default ?? '',
		]);
		return HTMLUtils::details('Default &amp; Factory', $content);
	}

	protected function buildDialog(string $content = ''): string
	{
		$content = $this->buildSchemaOptions();
		return parent::buildDialog($content);
	}

	public function build(): string
	{
		$inputAttributes = [
			'autocomplete' => 'off',
			'type'         => 'text',
			'name'         => 'property',
			'placeholder'  => 'name',
			'required'     => '',
			'disabled'     => '',
			'value'        => $this->property,
		];

		$dialog = $this->buildDialog();
		$button = HTMLUtils::element('button', '', ['type' => 'button']);
		$input  = HTMLUtils::inlineElement('input', $inputAttributes);
		$field  = HTMLUtils::element('div', $input . $button . $dialog, [
			'class' => "schema-field {$this->field}-field"
		]);

		return $field;
	}
}
