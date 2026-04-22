<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class ToggleField extends CheckboxField
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

		return HTMLUtils::element('div', $label . $group . $this->createHelpText(), $this->buildFieldAttributes());
	}
}
