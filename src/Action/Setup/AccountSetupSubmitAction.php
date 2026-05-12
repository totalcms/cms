<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\FirstLoginChecker;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\RedirectRenderer;
use TotalCMS\Support\Config;

/**
 * Step 3 submit: create the first admin user, then log them in so the
 * remaining setup steps (and the eventual hand-off to the dashboard) don't
 * trip over an unauthenticated session.
 */
readonly class AccountSetupSubmitAction
{
	public function __construct(
		private FirstLoginChecker $firstLoginChecker,
		private LoginService $loginService,
		private SetupStateManager $setupState,
		private SessionInterface $session,
		private RedirectRenderer $redirectRenderer,
		private TranslationService $translator,
		private Config $config,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data  = (array)$request->getParsedBody();
		$flash = $this->session->getFlash();

		$email           = trim((string)($data['email'] ?? ''));
		$password        = (string)($data['password'] ?? '');
		$confirmPassword = (string)($data['password-confirm'] ?? '');

		// Stash the submitted email so the form can repopulate it on every
		// validation-failure redirect (passwords are NEVER stashed). Also reused
		// by the complete page to display "logged in as".
		$this->session->set('setup_admin_email', $email);

		// Validate
		if ($email === '') {
			$flash->add('error', $this->translator->trans('wizard.account_email_req'));

			return $this->redirectRenderer->redirectFor($response, 'setup-account');
		}

		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$flash->add('error', $this->translator->trans('wizard.account_email_inv'));

			return $this->redirectRenderer->redirectFor($response, 'setup-account');
		}

		if ($password === '') {
			$flash->add('error', $this->translator->trans('wizard.account_pass_req'));

			return $this->redirectRenderer->redirectFor($response, 'setup-account');
		}

		if (strlen($password) < 8) {
			$flash->add('error', $this->translator->trans('wizard.account_pass_min'));

			return $this->redirectRenderer->redirectFor($response, 'setup-account');
		}

		if ($password !== $confirmPassword) {
			$flash->add('error', $this->translator->trans('wizard.account_pass_match'));

			return $this->redirectRenderer->redirectFor($response, 'setup-account');
		}

		try {
			$this->firstLoginChecker->createFirstUser($email, $password);
		} catch (\Throwable $e) {
			$flash->add('error', $this->translator->trans('wizard.account_fail', ['{error}' => $e->getMessage()]));

			return $this->redirectRenderer->redirectFor($response, 'setup-account');
		}

		$this->setupState->completeStep('account');

		// Auto-login: the operator just typed these credentials, so signing them
		// in here removes the "create account → log in with the same password"
		// friction at the end of the wizard. Failures are non-fatal — the
		// account still exists and they can log in manually.
		//
		// No session-id regeneration: at this point the session was issued to
		// the same browser running the wizard and has never been authenticated,
		// so there's no pre-existing fixation/hijack vector to invalidate.
		try {
			$user = $this->loginService->authenticate($email, $password);
			$this->session->set(SessionKeys::AUTH_USER, $user['id']);
			$this->session->set(SessionKeys::AUTH_COLLECTION, $this->config->auth['collection']);
			$this->session->set(SessionKeys::AUTH_PERSISTENT_LOGIN, false);
			// License is validated lazily by LicenseValidationMiddleware on the
			// next admin request — same pattern as the regular login action.
			$this->session->set(SessionKeys::LICENSE_CHECK_DUE, true);
		} catch (\Throwable) {
			// Account exists; user can log in manually if auto-login can't establish a session.
		}

		return $this->redirectRenderer->redirectFor($response, 'setup-license');
	}
}
