<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

final class ToggleField extends CheckboxField
{
	protected string $defaultInputType = 'checkbox';
	protected string $defaultFieldType = 'toggle';

	public function build(): string
	{
		$input = $this->buildFormField();

		$switchLabel = HTMLUtils::createHTMLElement('label', $this->label, [
			'for'         => "field-{$this->uuid}",
			'aria-hidden' => 'true',
		]);
		$switch = HTMLUtils::createHTMLElement('div', $input . $switchLabel, ['class' => 'switch']);

		$group = HTMLUtils::createHTMLElement('div', $switch, ['class' => 'form-group']);
		$label = HTMLUtils::createHTMLElement('label', $this->label, ['for' => "field-{$this->uuid}"]);
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

		$formField = HTMLUtils::createHTMLElement('div', $label . $group . $help, $formFieldAtrributes);

		return $formField;
	}
}
