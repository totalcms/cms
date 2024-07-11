<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\PropertyField\CustomPropertyField;
use TotalCMS\Utils\HTMLUtils;

class CustomPropertiesField extends PropertiesField
{
	protected string $defaultInputType = 'customProperties';
	protected string $defaultFieldType = 'customProperties';

	public function buildFormField(): string
	{
		$templateProperty = $this->properties[array_key_first($this->properties)];

		$content  = parent::buildFormField();
		$content .= $templateProperty->template();
		$content .= HTMLUtils::add('Add Object Override');

		return $content;
	}

	/** @param array<string,mixed> $properties */
	protected function createPropertyField(string $objectID, array $properties): CustomPropertyField
	{
		return new CustomPropertyField(
			object: $objectID,
			form: $this->form,
			properties: $properties
		);
	}
}
