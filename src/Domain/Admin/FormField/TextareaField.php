<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class TextareaField extends FormField
{
	protected string $defaultFieldType = 'text';
	protected string $defaultInputType = 'textarea';

	/** @return array<string,string> */
	protected function formFieldAttributes(): array
	{
		if (isset($this->settings['rows'])) {
			$this->rows = $this->settings['rows'];
		}

		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'required'         => $this->required ? '' : null,
			'disabled'         => $this->disabled ? '' : null,
			'readonly'         => $this->readonly ? '' : null,
			'rows'             => $this->rows > 0 ? strval($this->rows) : '8',
			'placeholder'      => $this->placeholder === '' ? null : $this->placeholder,
			'autocomplete'     => 'off', // Stop 1Password Managers from filling in the field
			'aria-describedby' => $this->help === '' ? null : "help-{$this->uuid}",
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn (?string $x): bool => !is_null($x));

		return $attributes;
	}

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();

		return HTMLUtils::element('textarea', strval($this->value), $attributes);
	}
}
