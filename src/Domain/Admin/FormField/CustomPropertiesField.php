<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\PropertyField\CustomPropertyField;

class CustomPropertiesField extends PropertiesField
{
	protected string $defaultInputType = 'customProperties';
	protected string $defaultFieldType = 'customProperties';

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
