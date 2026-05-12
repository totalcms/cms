<?php

namespace TotalCMS\Domain\Admin;

use Odan\Session\PhpSession;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Login Form Builder.
 */
readonly class LoginForm implements \Stringable
{
	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param array<string>|null $flashMessages
	 */
	public function __construct(
		private string $api,
		private PhpSession $session,
		private ?CSRFTokenManager $csrfManager = null,
		private ?string $collection            = null,
		private ?string $redirect              = null,
		private bool $showForgotPassword       = true,
		private string $submitLabel            = 'Sign in',
		private string $class                  = '',
		private ?array $flashMessages          = null,
		private string $loginWith              = 'both',
		private string $emailLabel             = '',
		private string $passwordLabel          = 'Password',
		private string $rememberLabel          = 'Keep me signed in',
		private string $forgotPasswordLabel    = 'Forgot Password?',
		private bool $showPasskeys             = true,
		private string $passkeyLabel           = 'Sign in with Passkey',
	) {
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function build(): string
	{
		// Store current URL in session for redirect on login error
		$currentUrl = $_SERVER['REQUEST_URI'] ?? '';
		if ($currentUrl !== '') {
			$this->session->set(SessionKeys::LOGIN_ORIGIN, $currentUrl);
		}

		// Determine the action URL
		$action = $this->collection === null || $this->collection === ''
			? "{$this->api}/admin/login"
			: "{$this->api}/admin/login/{$this->collection}";

		// Build form fields
		$fields = [];

		// CSRF Token
		if ($this->csrfManager instanceof CSRFTokenManager) {
			$fields[] = $this->csrfManager->getTokenField();
		}

		// Redirect hidden field
		if ($this->redirect !== null && $this->redirect !== '') {
			$fields[] = $this->buildHiddenField('redirect', $this->redirect);
		}

		// Email field
		$fields[] = $this->buildEmailField();

		// Password field
		$fields[] = $this->buildPasswordField();

		// Remember me checkbox
		$fields[] = $this->buildCheckboxField();

		// Forgot password link
		$forgotPasswordLink = '';
		if ($this->showForgotPassword) {
			$forgotPasswordUrl = $this->collection
				? "{$this->api}/admin/forgot-password/{$this->collection}"
				: "{$this->api}/admin/forgot-password";

			$forgotPasswordLink = HTMLUtils::element(
				'p',
				HTMLUtils::element('a', $this->forgotPasswordLabel, [
					'href'  => $forgotPasswordUrl,
					'class' => 'login-forgot-password',
				]),
				['class' => 'login-forgot-password-wrapper']
			);
		}

		// Submit button
		$submitButton = HTMLUtils::button($this->submitLabel, [
			'type'  => 'submit',
			'class' => 'cms-button no-icon',
		]);

		// Build the form
		$formContent = implode('', $fields) . $forgotPasswordLink . $submitButton;
		$form        = HTMLUtils::element('form', $formContent, [
			'class'         => 'totalform ' . $this->class,
			'method'        => 'post',
			'action'        => $action,
			'data-disabled' => 'true',
			'style'         => 'position:relative;',
		]);

		// Add flash messages
		$flashHtml = '';
		if ($this->flashMessages !== null && count($this->flashMessages) > 0) {
			$messages = [];
			foreach ($this->flashMessages as $message) {
				$messages[] = HTMLUtils::element('p', $message, [
					'class' => 'cms-twig-error',
					'role'  => 'alert',
				]);
			}
			$flashHtml = implode('', $messages);
		}

		// Passkey login button
		$passkeyHtml = $this->buildPasskeyButton();

		// Wrap in section
		$sectionContent = $form . $passkeyHtml . $flashHtml;

		return HTMLUtils::element('section', $sectionContent, [
			'class' => 'login-form',
		]);
	}

	private function buildHiddenField(string $name, string $value): string
	{
		return HTMLUtils::inlineElement('input', [
			'type'  => 'hidden',
			'name'  => $name,
			'value' => $value,
		]);
	}

	private function buildEmailField(): string
	{
		$uuid = uniqid();

		[$inputType, $placeholder, $helpText, $fieldClass, $defaultLabel] = match ($this->loginWith) {
			'email' => ['email', 'email@company.com', 'Email address for login', 'email-field', 'Email'],
			'id'    => ['text', 'username', 'User ID for login', 'text-field', 'Username'],
			default => ['text', 'email@company.com', 'Email address or user ID', 'text-field', 'Email or Username'],
		};

		$label = $this->emailLabel !== '' ? $this->emailLabel : $defaultLabel;

		$input = HTMLUtils::inlineElement('input', [
			'type'             => $inputType,
			'id'               => "field-{$uuid}",
			'name'             => 'email',
			'placeholder'      => $placeholder,
			'aria-describedby' => "help-{$uuid}",
			'required'         => '',
			'autofocus'        => '',
			'autocomplete'     => 'username webauthn',
		]);

		$icon  = HTMLUtils::element('div', '', ['class' => 'form-group-icon']);
		$group = HTMLUtils::element('div', $input . $icon, ['class' => 'form-group']);

		$labelEl = HTMLUtils::element('label', $label, ['for' => "field-{$uuid}"]);
		$help    = HTMLUtils::element('p', $helpText, [
			'class' => 'help cms-hide',
			'id'    => "help-{$uuid}",
		]);

		return HTMLUtils::element('div', $labelEl . $group . $help, [
			'class'     => "form-field {$fieldClass}",
			'data-type' => $inputType === 'email' ? 'email' : 'text',
		]);
	}

	private function buildPasswordField(): string
	{
		$uuid = uniqid();

		$input = HTMLUtils::inlineElement('input', [
			'type'             => 'password',
			'id'               => "field-{$uuid}",
			'name'             => 'password',
			'placeholder'      => 'p@ssw0rd',
			'aria-describedby' => "help-{$uuid}",
			'required'         => '',
			'class'            => 'allow-enter',
		]);

		$icon  = HTMLUtils::element('div', '', ['class' => 'form-group-icon']);
		$group = HTMLUtils::element('div', $input . $icon, ['class' => 'form-group']);

		$label = HTMLUtils::element('label', $this->passwordLabel, ['for' => "field-{$uuid}"]);
		$help  = HTMLUtils::element('p', 'Password to login', [
			'class' => 'cms-hide help',
			'id'    => "help-{$uuid}",
		]);

		return HTMLUtils::element('div', $label . $group . $help, [
			'class'     => 'form-field password-field',
			'data-type' => 'password',
		]);
	}

	private function buildCheckboxField(): string
	{
		$uuid = uniqid();

		$input = HTMLUtils::inlineElement('input', [
			'id'               => "field-{$uuid}",
			'name'             => 'persistent_login',
			'type'             => 'checkbox',
			'value'            => '1',
			'aria-describedby' => "help-{$uuid}",
		]);

		$label = HTMLUtils::element('label', $this->rememberLabel, ['for' => "field-{$uuid}"]);
		$group = HTMLUtils::element('div', $input . $label, ['class' => 'form-group']);

		$help = HTMLUtils::element('p', 'Stay logged in until you explicitly logout or clear browser data', [
			'class' => 'help cms-hide',
			'id'    => "help-{$uuid}",
		]);

		return HTMLUtils::element('div', $group . $help, [
			'class'     => 'form-field checkbox-field',
			'data-type' => 'checkbox',
		]);
	}

	private function buildPasskeyButton(): string
	{
		if (!$this->showPasskeys || $this->passkeyLabel === '') {
			return '';
		}

		$divider = HTMLUtils::element('div', HTMLUtils::element('span', 'or'), [
			'class' => 'login-divider',
		]);

		$button = HTMLUtils::button($this->passkeyLabel, [
			'type'     => 'button',
			'class'    => 'dash-button cms-passkey-login no-icon',
			'data-api' => $this->api,
		]);

		$field = HTMLUtils::element('div', $button, [
			'class' => 'form-field passkey-field',
		]);

		return $divider . $field;
	}

	public function __toString(): string
	{
		return $this->build();
	}
}
