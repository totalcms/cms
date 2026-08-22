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
 * Step 4 verify submit: redeems the 6-digit code emailed by the license
 * server to turn a pending registration into a live trial.
 */
readonly class LicenseVerifySubmitAction
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

		if (!is_array($pending) || !isset($pending['email'])) {
			return $this->redirectRenderer->redirectFor($response, 'setup-license');
		}

		$code = trim((string)($data['code'] ?? ''));
		if (!preg_match('/^\d{6}$/', $code)) {
			$flash->add('error', $this->translator->trans('wizard.license_code_invalid'));

			return $this->redirectRenderer->redirectFor($response, 'setup-license');
		}

		try {
			$this->licenseValidator->verifyTrial((string)$pending['email'], $code);
			$this->session->delete('setup_license_pending');
		} catch (\Throwable $e) {
			$flash->add('error', $this->translator->trans('wizard.license_verify_fail', ['{error}' => $e->getMessage()]));
		}

		return $this->redirectRenderer->redirectFor($response, 'setup-license');
	}
}
