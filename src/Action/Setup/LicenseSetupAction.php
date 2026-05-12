<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		// Validate license (auto-trial for new domains)
		$license = null;
		$error   = null;

		try {
			$license = $this->licenseValidator->validateLicense(forceRefresh: true);
		} catch (\Throwable $e) {
			$error = $e->getMessage();
		}

		$this->setupState->completeStep('license');

		return $this->twigRenderer->template($response, 'setup/license.twig', [
			'url' => [
				'path' => $request->getUri()->getPath(),
				'page' => 'setup',
			],
			'license' => $license,
			'error'   => $error,
		]);
	}
}
