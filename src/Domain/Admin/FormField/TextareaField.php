<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

final class TextareaField extends FormField
{
	protected string $defaultInputType = 'textarea';
	protected string $defaultFieldType = 'text';

	public function inputTemplate(): string
	{
		$attributes = $this->inputDefaultAttributes();

		return HTMLUtils::createHTMLElement('textarea', $attributes['value'] ?? "", $attributes);
	}
}
