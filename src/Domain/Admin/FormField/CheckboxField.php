<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

class CheckboxField extends FormField
{
	protected string $defaultInputType = 'checkbox';
	protected string $defaultFieldType = 'checkbox';

	public function build(): string
	{
		$input = $this->inputTemplate();
		$label = HTMLUtils::createHTMLElement('label', $this->label, ['for' => "field-{$this->uuid}"]);
		$group = HTMLUtils::createHTMLElement('div', $input . $label, ['class' => 'form-group']);
		$help  = empty($this->help) ? '' : HTMLUtils::createHTMLElement('p', $this->help, [
			'class' => 'help',
			'id'    => "help-{$this->uuid}",
		]);

		$formFieldAtrributes = [
			'class'     => "form-field {$this->field}-field {$this->class}",
			'data-type' => $this->field,
		];
		if (!empty($this->settings)) {
			$json = json_encode($this->settings);
			if ($json) {
				$formFieldAtrributes['data-options'] = $json;
			}
		}

		$formField = HTMLUtils::createHTMLElement('div', $group . $help, $formFieldAtrributes);

		return $formField;
	}

	/** @return array<string,?string> */
	protected function inputDefaultAttributes(): array
	{
		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'type'             => $this->inputType,
			'required'         => $this->required ? '' : null,
			'aria-describedby' => empty($this->help) ? null : "help-{$this->uuid}",
			'checked'          => boolval($this->value) ? '' : null,
		];

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn ($x) => !is_null($x));

		return $attributes;
	}
}
