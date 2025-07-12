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
		$help  = empty($this->help) ? '' : HTMLUtils::element('p', $this->help, [
			'class' => 'help',
			'id'    => "help-{$this->uuid}",
		]);

		$formFieldAtrributes = [
			'class'     => "form-field {$this->field}-field {$this->class}",
			'data-type' => $this->field,
			'style'     => "grid-area: {$this->name};",
		];
		if (!empty($this->settings)) {
			$json = json_encode($this->settings);
			if ($json) {
				$formFieldAtrributes['data-options'] = $json;
			}
		}

		$formField = HTMLUtils::element('div', $group . $help, $formFieldAtrributes);

		return $formField;
	}

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'type'             => $this->inputType,
			'required'         => null, // this has to be false or else you cannot save an unchecked box
			'aria-describedby' => empty($this->help) ? null : "help-{$this->uuid}",
			'checked'          => boolval($this->value) ? '' : null,
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x) => !is_null($x));

		return $attributes;
	}
}
