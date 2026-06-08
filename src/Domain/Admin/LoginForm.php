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
		private \Closure $translator,
		private ?CSRFTokenManager $csrfManager = null,
		private ?string $collection            = null,
		private ?string $redirect              = null,
		private bool $showForgotPassword       = true,
		private string $class                  = '',
		private ?array $flashMessages          = null,
		private string $loginWith              = 'both',
		private bool $showPasskeys             = true,
		// Label overrides — empty means "use the localized default". Whitelabel
		// login-options may set any of these to a literal string to override.
		private string $submitLabel            = '',
		private string $emailLabel             = '',
		private string $passwordLabel          = '',
		private string $rememberLabel          = '',
		private string $forgotPasswordLabel    = '',
		private string $passkeyLabel           = '',
		private string $orLabel                = '',
	) {
	}

	/** Translate an admin-domain key. */
	private function t(string $key): string
	{
		return ($this->translator)($key, [], 'admin');
	}

	/** A label override if provided, otherwise the localized default for $key. */
	private function label(string $override, string $key): string
	{
		return $override !== '' ? $override : $this->t($key);
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
				HTMLUtils::element('a', $this->label($this->forgotPasswordLabel, 'login.forgot_password'), [
					'href'  => $forgotPasswordUrl,
					'class' => 'login-forgot-password',
				]),
				['class' => 'login-forgot-password-wrapper']
			);
		}

		// Submit button
		$submitButton = HTMLUtils::button($this->label($this->submitLabel, 'login.submit'), [
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

		[$inputType, $placeholder, $helpKey, $fieldClass, $labelKey] = match ($this->loginWith) {
			'email' => ['email', 'email@company.com', 'login.email_help', 'email-field', 'login.email_label'],
			'id'    => ['text', 'username', 'login.username_help', 'text-field', 'login.username_label'],
			default => ['text', 'email@company.com', 'login.email_or_username_help', 'text-field', 'login.email_or_username_label'],
		};

		$label    = $this->label($this->emailLabel, $labelKey);
		$helpText = $this->t($helpKey);

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

		$label = HTMLUtils::element('label', $this->label($this->passwordLabel, 'login.password_label'), ['for' => "field-{$uuid}"]);
		$help  = HTMLUtils::element('p', $this->t('login.password_help'), [
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

		$label = HTMLUtils::element('label', $this->label($this->rememberLabel, 'login.remember_me'), ['for' => "field-{$uuid}"]);
		$group = HTMLUtils::element('div', $input . $label, ['class' => 'form-group']);

		$help = HTMLUtils::element('p', $this->t('login.remember_help'), [
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
		if (!$this->showPasskeys) {
			return '';
		}

		$divider = HTMLUtils::element('div', HTMLUtils::element('span', $this->label($this->orLabel, 'login.or')), [
			'class' => 'login-divider',
		]);

		$button = HTMLUtils::button($this->label($this->passkeyLabel, 'login.passkey'), [
			'type'     => 'button',
			'class'    => 'dash-button cms-passkey-login no-icon',
			// Passkey routes live under the /api group, but $this->api is the
			// base URL used for the (non-/api) /admin/login form action — so
			// the passkey JS needs /api appended (matches the registration
			// button in AuthTwigAdapter).
			'data-api' => $this->api . '/api',
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
