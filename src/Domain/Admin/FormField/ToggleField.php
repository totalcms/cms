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

		$switchLabel = HTMLUtils::element('label', $this->label, [
			'for'         => "field-{$this->uuid}",
			'aria-hidden' => 'true',
		]);
		$switch = HTMLUtils::element('div', $input . $switchLabel, ['class' => 'switch']);

		$group = HTMLUtils::element('div', $switch, ['class' => 'form-group']);
		$label = HTMLUtils::element('label', $this->label, ['for' => "field-{$this->uuid}"]);
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

		$formField = HTMLUtils::element('div', $label . $group . $help, $formFieldAtrributes);

		return $formField;
	}
}
