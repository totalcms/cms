<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Data\LicenseData;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Step 4 of the setup wizard: license validation.
 */
readonly class LicenseSetupAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private LicenseValidator $licenseValidator,
		private SetupStateManager $setupState,
		private SessionInterface $session,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$license = null;
		$error   = null;

		try {
			$license = $this->licenseValidator->validateLicense(forceRefresh: true);
		} catch (\Throwable $e) {
			$error = $e->getMessage();
		}

		$this->setupState->completeStep('license');

		// Registration is needed when the server explicitly said "no license, no
		// trial, not registered". Server errors keep the old error+skip screen.
		$needsRegistration = $license instanceof LicenseData
			&& !$license->valid && !$license->trial && !$license->registered;

		$pending = $this->session->get('setup_license_pending');

		return $this->twigRenderer->template($response, 'setup/license.twig', [
			'url' => [
				'path' => $request->getUri()->getPath(),
				'page' => 'setup',
			],
			'license'           => $license,
			'error'             => $error,
			'needsRegistration' => $needsRegistration,
			'pending'           => is_array($pending) ? $pending : null,
			'prefillName'       => (string)$this->session->get('setup_admin_name', ''),
			'prefillEmail'      => (string)$this->session->get('setup_admin_email', ''),
		]);
	}
}
