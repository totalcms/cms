<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\PropertyField\SchemaField;
use TotalCMS\Domain\Admin\SchemaForm;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Utils\HTMLUtils;

class SchemaPropertiesField extends PropertiesField
{
	protected string $defaultInputType = 'schemaProperties';
	protected string $defaultFieldType = 'schemaProperties';

	public function buildFormField(): string
	{

		$content = parent::buildFormField();

		// if (!empty($this->properties)) {
		// 	$templateProperty = $this->properties[array_key_first($this->properties)];
		// 	$content .= $templateProperty->template();
		// }

		// Don't add the add property button if the form is reserved
		if ($this->form instanceof SchemaForm && !$this->form->reserved) {
			$content .= HTMLUtils::add('Add Property');
		}

		return $content;
	}

	/** @param array<string,mixed> $options */
	protected function createPropertyField(string $property, array $options): SchemaField
	{
		if (isset($options['$ref'])) {
			$options['type'] = basename($options['$ref'], '.json');
			unset($options['$ref']);
		}

		$extra   = SchemaField::filterExtraProperties($options);
		$options = SchemaField::filterSchemaProperties($options);

		$options['property'] = $property;
		$options['form'] = $this->form;

		if (!empty($extra)) {
			$options['extra'] = $extra;
		}

		return new SchemaField(...$options);
	}
}
