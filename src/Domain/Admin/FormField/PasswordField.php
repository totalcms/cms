<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class PasswordField extends FormField
{
	protected string $defaultInputType = 'password';
	protected string $defaultFieldType = 'password';

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes = parent::formFieldAttributes();

		if (!empty($this->settings['numeric'])) {
			$attributes['inputmode'] = 'numeric';
		}

		// Browsers ignore unknown autocomplete tokens (the inherited 'nofill')
		// on type=password inputs and silently fill saved site credentials —
		// which set file/depot protection passwords behind users' backs.
		// 'new-password' is the standard "not the login password" signal.
		// The login form builds its own raw input, so saved-password fill on
		// login is unaffected.
		$attributes['autocomplete'] = 'new-password';

		if (!empty($this->settings['ignoreManagers'])) {
			// Full password-manager opt-out for fields that are never account
			// credentials (media protection passwords). Vendor attributes for
			// 1Password, LastPass, and Bitwarden.
			$attributes['data-1p-ignore'] = '';
			$attributes['data-lpignore']  = 'true';
			$attributes['data-bwignore']  = 'true';
		}

		return $attributes;
	}

	public function build(): string
	{
		// Main Password Input
		$attributes = $this->formFieldAttributes();
		$mainInput  = HTMLUtils::inlineElement('input', $attributes);

		// Confirm Password Input
		$confirmAttributes = $attributes;
		$confirmAttributes['name'] .= '-confirm';
		$confirmAttributes['id'] .= '-confirm';

		if (!empty($this->settings['numeric'])) {
			$confirmAttributes['inputmode'] = 'numeric';
		}

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
