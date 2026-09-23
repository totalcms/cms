<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\PropertyField\Concerns\ResolvesPropertyFields;
use TotalCMS\Domain\Admin\PropertyField\CustomPropertyField;
use TotalCMS\Domain\Admin\PropertyField\PropertyField;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class PropertiesField extends FormField
{
	use ResolvesPropertyFields;

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

		// Add cms-hide class if hide setting is true (check both property-level and settings)
		if ($this->hide || (isset($this->settings['hide']) && $this->settings['hide'] === true)) {
			$this->class = trim($this->class . ' cms-hide');
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

		$content .= $this->overridePropertySelect(array_keys($this->properties));

		return $content . $this->newPropertyTemplate();
	}

	/**
	 * Hook for the schema and custom-properties editors, which build a
	 * different field per property.
	 *
	 * @param array<string,mixed> $options
	 */
	protected function createPropertyField(string $property, array $options): PropertyField|CustomPropertyField
	{
		return $this->resolvePropertyField($property, $options);
	}
}
