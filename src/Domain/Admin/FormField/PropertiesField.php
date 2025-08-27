<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\CollectionForm;
use TotalCMS\Domain\Admin\PropertyField\CustomPropertyField;
use TotalCMS\Domain\Admin\PropertyField\PropertyField;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

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

		$content .= $this->createAddPropertyField();

		return $content . $this->createNewPropertyTemplate();
	}

	protected function createAddPropertyField(): string
	{
		if (!$this->form instanceof CollectionForm) {
			return '';
		}

		$schema = $this->form->getCollectionSchema();

		if (is_null($schema)) {
			return '';
		}

		$schemaProperties = array_keys($schema->properties);
		$localProperties  = array_keys($this->properties);
		$propertiesToAdd  = array_diff($schemaProperties, $localProperties);

		if ($propertiesToAdd === []) {
			return '';
		}

		$options = HTMLUtils::option('Override New Property', '', [
			'class'    => 'placeholder',
			'disabled' => 'disabled',
			'selected' => 'selected',
		]);

		foreach ($propertiesToAdd as $property) {
			$schemaProp = $this->form->filterFieldProperties($schema->properties[$property]);
			$options .= HTMLUtils::option($property, '', [
				'value' => (string)json_encode($schemaProp),
			]);
		}

		return HTMLUtils::element('select', $options, ['name' => 'addProperty']);
	}

	protected function createNewPropertyTemplate(): string
	{
		$templateProperty = new PropertyField(
			form     : $this->form,
			property : ''
		);

		return $templateProperty->template();
	}

	/** @param array<string,mixed> $options */
	protected function createPropertyField(string $property, array $options): PropertyField|CustomPropertyField
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
