<?php

namespace TotalCMS\Domain\Admin\PropertyField;

use TotalCMS\Domain\Admin\CollectionForm;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

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
		$templateProperty = new PropertyField(form : $this->form, property : '');
		$content          = $templateProperty->template();

		$content .= $this->createAddPropertyField();
		$content  = $this->accordion('', $content);

		return HTMLUtils::element('template', $content, ['class' => 'custom-property-template']);
	}

	private function accordion(string $title = '', string $content = ''): string
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
		$content .= $this->createNewPropertyTemplate();
		$content .= $this->createAddPropertyField(array_keys($this->properties));

		return $this->accordion($this->object, $content);
	}

	protected function createNewPropertyTemplate(): string
	{
		$templateProperty = new PropertyField(
			form     : $this->form,
            property : ''
		);

		return $templateProperty->template();
	}

	/** @param array<string> $excludeProperties */
	protected function createAddPropertyField(array $excludeProperties = []): string
	{
		if (!$this->form instanceof CollectionForm) {
			return '';
		}

		$schema = $this->form->getCollectionSchema();

		if (is_null($schema)) {
			return '';
		}

		$schemaProperties = array_keys($schema->properties);
		$propertiesToAdd  = array_diff($schemaProperties, $excludeProperties);

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
