<?php

namespace TotalCMS\Domain\Admin\FormField;

class IdField extends FormField
{
	protected string $defaultInputType = 'text';
	protected string $defaultFieldType = 'id';

	public function init(): void
	{
		parent::init();

		if ($this->name === 'id') {
			$this->required = true;
		}

		if (!empty($this->value)) {
			$this->readonly = true;
		}
	}

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes                   = parent::formFieldAttributes();
		$attributes['autocapitalize'] = 'off';

		return $attributes;
	}
}
