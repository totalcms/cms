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
			$this->value = explode(',', (string)$this->value);
		}
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
