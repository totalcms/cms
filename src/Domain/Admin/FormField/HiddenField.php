<?php

namespace TotalCMS\Domain\Admin\FormField;

final class HiddenField extends FormField
{
	protected string $defaultInputType = 'hidden';
	protected string $defaultFieldType = 'hidden';

	public function init(): void
	{
		parent::init();
		$this->icon  = false;
		$this->label = '';
		$this->help  = '';
	}
}
