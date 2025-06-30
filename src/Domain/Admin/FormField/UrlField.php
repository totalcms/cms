<?php

namespace TotalCMS\Domain\Admin\FormField;

final class UrlField extends FormField
{
	protected string $defaultFieldType = 'url';
	protected string $defaultInputType = 'url';

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes = parent::formFieldAttributes();
		$attributes['autocapitalize'] = 'off';

		return $attributes;
	}
}
