<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\PropertyField\CustomPropertyField;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class CustomPropertiesField extends PropertiesField
{
	protected string $defaultInputType = 'customProperties';
	protected string $defaultFieldType = 'customProperties';

	public function buildFormField(): string
	{
		$content = HTMLUtils::inlineElement('input', [
			'type'  => 'hidden',
			'name'  => $this->name,
		]);

		foreach ($this->properties as $field) {
			$content .= $field->build();
		}

		$templateProperty = new CustomPropertyField(
			form: $this->form,
            object: ''
		);
		$content .= $templateProperty->template();

		return $content . HTMLUtils::add('Add Object Override');
	}

	/** @param array<string,mixed> $properties */
	protected function createPropertyField(string $objectID, array $properties): CustomPropertyField
	{
		return new CustomPropertyField(
			form       : $this->form,
            object     : $objectID,
			properties : $properties
		);
	}
}
