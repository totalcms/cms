<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class PasswordField extends FormField
{
	protected string $defaultInputType = 'password';
	protected string $defaultFieldType = 'password';

	public function build(): string
	{
		// Main Password Input
		$attributes = $this->formFieldAttributes();
		$mainInput  = HTMLUtils::inlineElement('input', $attributes);

		// Confirm Password Input
		$confirmAttributes = $attributes;
		$confirmAttributes['name'] .= '-confirm';
		$confirmAttributes['id'] .= '-confirm';

		// Use confirmPlaceholder setting if provided, otherwise use same placeholder
		if (isset($this->settings['confirmPlaceholder']) && $this->settings['confirmPlaceholder'] !== '') {
			$confirmAttributes['placeholder'] = $this->settings['confirmPlaceholder'];
		}

		$confirmInput = HTMLUtils::inlineElement('input', $confirmAttributes);

		$icon = $this->icon ? HTMLUtils::element('div', '', ['class' => 'form-group-icon']) : '';

		$password = $this->createFormGroup($mainInput . $icon);
		$confirm  = $this->createFormGroup($confirmInput . $icon);

		return $this->createFormField($password . $confirm);
	}
}
