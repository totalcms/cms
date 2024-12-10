<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\PropertyField\SchemaField;
use TotalCMS\Domain\Admin\SchemaForm;
use TotalCMS\Utils\HTMLUtils;

class SchemaPropertiesField extends PropertiesField
{
	protected string $defaultInputType = 'schemaProperties';
	protected string $defaultFieldType = 'schemaProperties';

	public const DEFAULT_ID_OPTIONS = [
		'field'       => 'id',
		'type'        => 'slug',
		'label'       => 'ID',
		'help'        => 'The unique identifier',
		'placeholder' => 'Enter a unique identifier',
	];

	public function init(): void
	{
		parent::init();

		if (!isset($this->properties['id'])) {
			// Add the default ID property if it doesn't exist
			$this->properties['id'] = $this->createPropertyField('id', self::DEFAULT_ID_OPTIONS);
		}
	}

	public function buildFormField(): string
	{
		$content = parent::buildFormField();

		if (!empty($this->properties)) {
			$template = $this->createPropertyField('', []);
			$content .= HTMLUtils::element('template', $template->build(), ['class' => 'schema-template']);
		}

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
		$options['form']     = $this->form;

		if (!empty($extra)) {
			$options['extra'] = $extra;
		}

		return new SchemaField(...$options);
	}
}
