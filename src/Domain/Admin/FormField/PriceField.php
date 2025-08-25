<?php

namespace TotalCMS\Domain\Admin\FormField;

class PriceField extends FormField
{
	protected string $defaultInputType = 'number';
	protected string $defaultFieldType = 'price';

	public function init(): void
	{
		parent::init();

		// Hard-code the step setting for price fields
		$this->settings['step'] = 0.01;

		// Set default step if not already set
		$this->step = 0.01;
	}
}