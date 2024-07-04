<?php

namespace TotalCMS\Domain\Admin\FormField;

class ListField extends MultiselectField
{
	protected string $defaultInputType = 'select';
	protected string $defaultFieldType = 'list';

	public function init(): void
	{
		parent::init();

		$this->rows = 6;
	}
}
