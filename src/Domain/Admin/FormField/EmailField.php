<?php

namespace TotalCMS\Domain\Admin\FormField;

final class EmailField extends FormField
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
