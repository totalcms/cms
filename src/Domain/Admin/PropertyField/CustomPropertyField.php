<?php

namespace TotalCMS\Domain\Admin\PropertyField;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Utils\HTMLUtils;

class CustomPropertyField
{
	/** @param array<string,mixed> $properties */
	public function __construct(
		protected TotalForm $form,
		protected string $object,
		protected array $properties = [],
	) {
		$properties = [];
		foreach ($this->properties as $property => $options) {
			$properties[(string)$property] = $this->createPropertyField($property, $options);
		}
		$this->properties = $properties;
	}

	public function build(): string
	{
		$content = '';

		foreach ($this->properties as $field) {
			$content .= $field->build();
		}
		$content = HTMLUtils::details($this->object, $content);

		// Add plus button + template to add new custom property

		return $content;
	}

	/** @param array<string,mixed> $options */
	private function createPropertyField(string $property, array $options): PropertyField
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
