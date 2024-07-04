<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

class MultiselectField extends SelectField
{
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
}
