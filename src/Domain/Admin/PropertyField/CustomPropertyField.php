<?php

namespace TotalCMS\Domain\Admin\PropertyField;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Utils\HTMLUtils;

class CustomPropertyField
{
	/** @var array<string,PropertyField> */
	private array $fields = [];

	/** @param array<string,mixed> $properties */
	public function __construct(
		protected TotalForm $form,
		protected string $object,
		protected array $properties = [],
	) {
		$this->initFields();
	}

	private function initFields(): void
	{
		foreach ($this->properties as $property => $options) {
			$this->fields[(string)$property] = $this->createPropertyField($property, $options);
		}
	}

	public function template(): string
	{
		// This is the template for the custom properties
		// It clears all values so that all inputs are blank
		$content = '';
		$fields = [];
		$blankOptions = [
			'label'       => '',
			'help'        => '',
			'placeholder' => '',
			'options'     => [],
			'settings'    => [],
		];
		$properties = $this->form->propertiesForSchema();
		foreach ($properties as $property => $options) {
			$options = array_merge($options, $blankOptions);
			$fields[$property] = $this->createPropertyField($property, $options);
		}
		foreach ($fields as $field) {
			$content .= $field->build();
		}
		$content = self::accordion('', $content);
		return HTMLUtils::element('template', $content);
	}

	private static function accordion(string $title = '', string $content = ''): string
	{
		$input = HTMLUtils::inlineElement('input', [
			'type'        => 'text',
			'name'        => 'object',
			'placeholder' => 'myobject',
			'required'    => 'required',
			'value'       => $title,
		]);

		$duplicate = HTMLUtils::element('button', '', ['class' => 'duplicate', 'title' => 'Duplicate object']);
		$trash     = HTMLUtils::element('button', '', ['class' => 'trash', 'title' => 'Delete object']);
		$actions   = HTMLUtils::element('div', $duplicate . $trash, ['class' => 'actions']);

		$actionbar = HTMLUtils::element('div', $input . $actions, ['class' => 'customProperties-actionbar']);

		return HTMLUtils::details($actionbar, $content, 'customProperties-object');
	}

	public function build(): string
	{
		$content = '';

		foreach ($this->fields as $field) {
			$content .= $field->build();
		}
		$content = self::accordion($this->object, $content);

		return $content;
	}

	/** @param array<string,mixed> $options */
	private function createPropertyField(string $property, array $options): PropertyField
	{
		$options['property'] = $property;
		$options['form']     = $this->form;

		$typeClass = 'TotalCMS\\Domain\\Admin\\PropertyField\\' . ucfirst($options['field'] ?? '') . 'Field';
		if (class_exists($typeClass) && is_subclass_of($typeClass, PropertyField::class)) {
			return new $typeClass(...$options);
		}

		return new PropertyField(...$options);
	}
}
