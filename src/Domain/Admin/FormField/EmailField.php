<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

class EmailField extends FormField
{
	protected string $defaultInputType = 'email';
	protected string $defaultFieldType = 'email';

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes                   = parent::formFieldAttributes();
		$attributes['autocapitalize'] = 'off';

		return $attributes;
	}
}
