<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

class UrlField extends FormField
{
	protected string $defaultFieldType = 'url';
	protected string $defaultInputType = 'url';

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes                   = parent::formFieldAttributes();
		$attributes['autocapitalize'] = 'off';

		return $attributes;
	}
}
