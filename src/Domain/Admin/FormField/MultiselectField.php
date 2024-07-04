<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

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
			$this->value = explode(',', $this->value);
		}
	}

	protected function buildOptions(): string
	{
		$options = '';

		$options .= $this->placeholderOption();

		$this->converOptionsList();

		foreach ($this->options as $option) {
			$attributes = ['value' => $option['value']];
			if (in_array($option['value'], $this->value)) {
				$attributes['selected'] = '';
			}
			$options .= HTMLUtils::createHTMLElement('option', $option['label'], $attributes);
		}

		return $options;
	}

	protected function placeholderOption(): string
	{
		if (empty($this->placeholder)) {
			return '';
		}

		$attributes = ['value' => '', 'disabled' => ''];

		return HTMLUtils::createHTMLElement('option', $this->placeholder, $attributes);
	}
}
