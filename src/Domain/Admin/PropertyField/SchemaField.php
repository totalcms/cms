<?php

namespace TotalCMS\Domain\Admin\PropertyField;

use TotalCMS\Domain\Admin\SchemaForm;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Utils\HTMLUtils;

class SchemaField extends PropertyField
{
	public const SCHEMA_PROPERTY_FIELDS = [
		'default',
		'factory',
		'field',
		'help',
		'label',
		'placeholder',
		'options',
		'settings',
		'type',
	];

	/**
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @param array<string,mixed> $settings - JSON settings for the field added to data-options attribute
	 * @param array<string,mixed> $extra - extra attributes for the field schema such as minItems, items, patternProperties, etc
	 * @param array<mixed> $options - Options for select fields and datalists
	 */
	public function __construct(
		protected TotalForm $form,
		protected string $property,
		protected string $field       = 'text',
		protected string $type        = 'string',
		protected string $label       = '',
		protected string $help        = '',
		protected string $placeholder = '',
		protected string $factory     = '',
		protected string $default     = '',
		protected array $options      = [],
		protected array $settings     = [],
		protected array $extra        = [],
	) {
	}

	protected function buildPropertyInfo(): string
	{
		$content = $this->form->field('type', [
			'field'       => 'select',
			'label'       => 'Type',
			'placeholder' => 'Select a data type',
			'help'        => 'The data type of the property',
			'value'       => $this->type,
			'options'     => SchemaData::PROPERTY_TYPES,
		]);
		$content .= $this->form->field('factory', [
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
		$content .= $this->form->field('extra', [
			'field'       => 'json',
			'label'       => 'Extra Schema Definitions',
			'placeholder' => '{ "key": "value" }',
			'help'        => 'Extra schema definitions for this property in valid JSON format',
			'value'       => empty($this->extra) ? '' : json_encode($this->extra, JSON_PRETTY_PRINT),
		]);

		return HTMLUtils::details('Property Info', $content);
	}

	protected function buildDialog(string $content = ''): string
	{
		$content = $this->buildPropertyInfo();

		return parent::buildDialog($content);
	}

	public function build(): string
	{
		$inputAttributes = [
			'autocomplete' => 'off',
			'type'         => 'text',
			'name'         => 'property',
			'placeholder'  => 'property',
			'required'     => '',
			'value'        => $this->property,
		];

		if ($this->property === 'id') {
			$inputAttributes['disabled'] = '';
			$inputAttributes['readonly'] = '';
		}

		$dialog  = $this->buildDialog();
		$input   = HTMLUtils::inlineElement('input', $inputAttributes);
		$buttons = HTMLUtils::button('', ['class' => 'edit', 'title' => 'Edit property']);

		if ($this->form instanceof SchemaForm && !$this->form->reserved) {
			$buttons .= HTMLUtils::button('', ['class' => 'duplicate', 'title' => 'Duplicate property']);
			$buttons .= HTMLUtils::button('', ['class' => 'trash', 'title' => 'Delete property']);
		}

		$field  = HTMLUtils::element('div', $input . $buttons . $dialog, [
			'class' => "schema-field {$this->field}-field",
		]);

		return $field;
	}

	/**
	 * @param array<string,mixed> $properties
	 *
	 * @return array<string,mixed>
	 */
	public static function filterSchemaProperties(array $properties): array
	{
		// Remove any keys that are not needed for the field
		// Since PHP will unknown named parameters
		return array_filter($properties, fn ($key) => in_array($key, self::SCHEMA_PROPERTY_FIELDS), ARRAY_FILTER_USE_KEY);
	}

	/**
	 * @param array<string,mixed> $properties
	 *
	 * @return array<string,mixed>
	 */
	public static function filterExtraProperties(array $properties): array
	{
		// Remove any keys that are not needed for the field
		// Since PHP will unknown named parameters
		return array_filter($properties, fn ($key) => !in_array($key, self::SCHEMA_PROPERTY_FIELDS), ARRAY_FILTER_USE_KEY);
	}
}
