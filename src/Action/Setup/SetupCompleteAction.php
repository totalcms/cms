<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

/**
 * Step 5 of the setup wizard: setup complete.
 */
readonly class SetupCompleteAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private Config $config,
		private SessionInterface $session,
		private LicenseValidator $licenseValidator,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$edition = 'unknown';
		try {
			$license = $this->licenseValidator->validateLicense();
			$edition = $license->edition;
		} catch (\Throwable) {
			// License info is optional on the complete page
		}

		$adminEmail = $this->session->get('setup_admin_email', '');

		return $this->twigRenderer->template($response, 'setup/complete.twig', [
			'url' => [
				'path' => $request->getUri()->getPath(),
				'page' => 'setup',
			],
			'version'    => Version::number(),
			'edition'    => $edition,
			'datadir'    => $this->config->datadir,
			'adminEmail' => $adminEmail,
		]);
	}
}
