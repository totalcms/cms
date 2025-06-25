<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

final class PasswordField extends FormField
{
	protected string $defaultInputType = 'password';
	protected string $defaultFieldType = 'password';

	public function build(): string
	{
		// Main Password Input
		$attributes = $this->formFieldAttributes();
		$mainInput  = HTMLUtils::inlineElement('input', $attributes);

		// Confirm Password Input
		$attributes['name'] .= '-confirm';
		$attributes['id'] .= '-confirm';
		$confirmInput = HTMLUtils::inlineElement('input', $attributes);

		$icon = $this->icon ? HTMLUtils::element('div', '', ['class' => 'form-group-icon']) : '';

		$password = $this->createFormGroup($mainInput . $icon);
		$confirm  = $this->createFormGroup($confirmInput . $icon);

		return $this->createFormField($password . $confirm);
	}
}
