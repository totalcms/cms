<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class SelectField extends FormField
{
	protected string $defaultFieldType = 'select';
	protected string $defaultInputType = 'select';

	public function init(): void
	{
		parent::init();
	}

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'required'         => $this->required ? '' : null,
			'disabled'         => $this->disabled ? '' : null,
			'readonly'         => $this->readonly ? '' : null,
			'multiple'         => $this->multiple ? '' : null,
			'size'             => $this->rows ? (string)$this->rows : null,
			'aria-describedby' => empty($this->help) ? null : "help-{$this->uuid}",
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x) => !is_null($x));

		return $attributes;
	}

	protected function placeholderOption(): string
	{
		if (empty($this->placeholder)) {
			return '';
		}

		return HTMLUtils::option($this->placeholder, $this->value, [
			'class'    => 'placeholder',
			'value'    => '',
			'disabled' => '',
		]);
	}

	protected function buildOptions(string $options = ''): string
	{
		$options .= $this->placeholderOption();

		return parent::buildOptions($options);
	}

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();

		return HTMLUtils::element('select', $this->buildOptions(), $attributes);
	}
}
