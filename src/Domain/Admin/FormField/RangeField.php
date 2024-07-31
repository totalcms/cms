<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

final class RangeField extends FormField
{
	protected string $defaultInputType = 'range';
	protected string $defaultFieldType = 'range';

	public function init(): void
	{
		parent::init();

		$this->icon = false;
	}

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();
		$input      = HTMLUtils::inlineElement('input', $attributes);

		$rangeValue = HTMLUtils::element('div', $this->value, [
			'class' => 'range-value',
			'id'    => 'value-' . $this->uuid,
		]);

		return $input . $rangeValue;
	}
}
