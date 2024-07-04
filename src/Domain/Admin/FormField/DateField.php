<?php

namespace TotalCMS\Domain\Admin\FormField;

class DateField extends FormField
{
	protected string $defaultInputType = 'date';
	protected string $defaultFieldType = 'date';
	protected string $dateFormat = 'Y-m-d';

	public function init(): void
	{
		parent::init();

		if (!empty($this->value)) {
			$this->value = date($this->dateFormat, strtotime($this->value));
		}
		if ($this->default === 'onCreate' || $this->default === 'onUpdate') {
			$this->readonly = true;
		}
	}
}
