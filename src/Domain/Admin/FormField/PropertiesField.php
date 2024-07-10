<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;
use TotalCMS\Domain\Admin\PropertyField\PropertyField;
use TotalCMS\Domain\Admin\PropertyField\CustomPropertyField;

class PropertiesField extends FormField
{
	protected string $defaultInputType = 'properties';
	protected string $defaultFieldType = 'properties';

	/** @var array<string,mixed> */
	protected array $properties = [];

	public function init(): void
	{
		$this->uuid       = uniqid();
		$this->field      = $this->defaultFieldType;
		$this->inputType  = $this->defaultInputType;
		$this->icon       = false;

		if (is_array($this->value)) {
			foreach ($this->value as $property => $options) {
				$this->properties[(string)$property] = $this->createPropertyField($property, $options);
			}
		}
	}

	public function buildFormField(): string
	{
		$content = '';

		$content .= HTMLUtils::inlineElement('input', [
			'type'  => 'hidden',
			'name'  => $this->name,
		]);

		foreach ($this->properties as $field) {
			$content .= $field->build();
		}

		return $content;
	}

	/** @param array<string,mixed> $options */
	protected function createPropertyField(string $property, array $options): PropertyField|CustomPropertyField
	{
		$options['property'] = $property;
		$options['form'] = $this->form;

		$typeClass = 'TotalCMS\\Domain\\Admin\\PropertyField\\' . ucfirst($options['field'] ?? '') . 'Field';
		if (class_exists($typeClass) && is_subclass_of($typeClass, PropertyField::class)) {
			return new $typeClass(...$options);
		}
		return new PropertyField(...$options);
	}
}
