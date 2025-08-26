<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class MultiselectField extends SelectField
{
	protected string $defaultFieldType = 'multiselect';
	protected string $defaultInputType = 'select';

	public function init(): void
	{
		parent::init();

		$this->multiple = true;

		if (empty($this->value)) {
			$this->value = [];
		} elseif (!is_array($this->value)) {
			$this->value = explode(',', (string) $this->value);
		}
	}

	/** @param array<string,string> $option */
	protected function buildOption(array $option): string
	{
		$attributes = ['value' => $option['value']];
		if (in_array($option['value'], $this->value)) {
			$attributes['selected'] = '';
		}

		return HTMLUtils::element('option', $option['label'], $attributes);
	}

	protected function placeholderOption(): string
	{
		if ($this->placeholder === '') {
			return '';
		}

		$attributes = ['value' => '', 'disabled' => ''];

		return HTMLUtils::element('option', $this->placeholder, $attributes);
	}
}
