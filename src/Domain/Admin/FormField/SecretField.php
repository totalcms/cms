<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

/**
 * A text field that masks its value by default with a show/hide toggle.
 * Use for API keys, tokens, secrets, and other sensitive data stored as plain text.
 */
class SecretField extends FormField
{
	protected string $defaultInputType = 'password';
	protected string $defaultFieldType = 'secret';

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes = parent::formFieldAttributes();

		$attributes['data-1p-ignore'] = '';
		$attributes['autocomplete']   = 'off';

		return $attributes;
	}
}
