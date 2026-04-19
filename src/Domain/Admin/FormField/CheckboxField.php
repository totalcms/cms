<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class CheckboxField extends FormField
{
	protected string $defaultInputType = 'checkbox';
	protected string $defaultFieldType = 'checkbox';

	public function build(): string
	{
		$input = $this->buildFormField();
		$label = HTMLUtils::element('label', $this->label, ['for' => "field-{$this->uuid}"]);
		$group = HTMLUtils::element('div', $input . $label, ['class' => 'form-group']);
		$help  = $this->help === '' ? '' : HTMLUtils::element('p', $this->help, [
			'class' => 'help',
			'id'    => "help-{$this->uuid}",
		]);

		return HTMLUtils::element('div', $group . $help, $this->buildFieldAttributes());
	}

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'type'             => $this->inputType,
			'required'         => $this->required ? '' : null, // Required checkboxes must be checked
			'aria-describedby' => $this->help === '' ? null : "help-{$this->uuid}",
			'checked'          => boolval($this->value) ? '' : null,
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn (?string $x): bool => !is_null($x));

		return $attributes;
	}
}
