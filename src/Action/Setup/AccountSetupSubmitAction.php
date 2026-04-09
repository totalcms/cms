<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\FirstLoginChecker;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\RedirectRenderer;

/**
 * Step 3 submit: create the first admin user.
 */
readonly class AccountSetupSubmitAction
{
	public function __construct(
		private FirstLoginChecker $firstLoginChecker,
		private SetupStateManager $setupState,
		private PhpSession $session,
		private RedirectRenderer $redirectRenderer,
		private TranslationService $translator,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data  = (array)$request->getParsedBody();
		$flash = $this->session->getFlash();

		$email           = trim((string)($data['email'] ?? ''));
		$password        = (string)($data['password'] ?? '');
		$confirmPassword = (string)($data['password-confirm'] ?? '');

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

		// Store email in session for display on the complete page
		$this->session->set('setup_admin_email', $email);

		return $this->redirectRenderer->redirectFor($response, 'setup-license');
	}
}
