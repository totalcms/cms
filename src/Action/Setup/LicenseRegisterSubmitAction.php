<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\RedirectRenderer;

/**
 * Step 4 register submit: starts (or resumes) a free-trial registration
 * against the license server. An empty re-POST falls back to the pending
 * session values, so the code screen's "Resend code" is just an empty
 * re-submission of this same route.
 */
readonly class LicenseRegisterSubmitAction
{
	public function __construct(
		private LicenseValidator $licenseValidator,
		private SessionInterface $session,
		private RedirectRenderer $redirectRenderer,
		private TranslationService $translator,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data    = (array)$request->getParsedBody();
		$flash   = $this->session->getFlash();
		$pending = $this->session->get('setup_license_pending');
		$pending = is_array($pending) ? $pending : [];

		$name  = trim((string)($data['name'] ?? $pending['name'] ?? ''));
		$email = trim(strtolower((string)($data['email'] ?? $pending['email'] ?? '')));

		if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$flash->add('error', $this->translator->trans('wizard.license_form_invalid'));

			return $this->redirectRenderer->redirectFor($response, 'setup-license');
		}

		try {
			$result = $this->licenseValidator->registerTrial($name, $email);
		} catch (\Throwable $e) {
			$flash->add('error', $this->translator->trans('wizard.license_register_fail', ['{error}' => $e->getMessage()]));

			return $this->redirectRenderer->redirectFor($response, 'setup-license');
		}

		if (($result['status'] ?? '') === 'verification_required') {
			$this->session->set('setup_license_pending', ['name' => $name, 'email' => $result['email'] ?? $email]);
		} else {
			// trial_created: verified email skipped the code step entirely.
			$this->session->delete('setup_license_pending');
		}

		return $this->redirectRenderer->redirectFor($response, 'setup-license');
	}
}
