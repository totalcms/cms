<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

class TextareaField extends FormField
{
	protected string $defaultFieldType = 'text';
	protected string $defaultInputType = 'textarea';

	/** @return array<string,string> */
	protected function formFieldAttributes(): array
	{
		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'required'         => $this->required ? '' : null,
			'disabled'         => $this->disabled ? '' : null,
			'readonly'         => $this->readonly ? '' : null,
			'rows'             => $this->rows ? (string)$this->rows : null,
			'placeholder'      => empty($this->placeholder) ? null : $this->placeholder,
			'aria-describedby' => empty($this->help) ? null : "help-{$this->uuid}",
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x) => !is_null($x));

		return $attributes;
	}

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();

		return HTMLUtils::element('textarea', (string)$this->value, $attributes);
	}
}
