<?php

namespace TotalCMS\Action\Setup;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Setup\Service\DataPathInstaller;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\RedirectRenderer;

/**
 * HTTP plumbing for the data-path step submission. Form parsing,
 * flash-on-error, and step transition only — actual directory
 * provisioning lives in DataPathInstaller.
 */
readonly class DataPathSetupSubmitAction
{
	public function __construct(
		private DataPathInstaller $installer,
		private SetupStateManager $setupState,
		private PhpSession $session,
		private RedirectRenderer $redirectRenderer,
		private TranslationService $translator,
	) {
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data  = (array)$request->getParsedBody();
		$flash = $this->session->getFlash();

		$location   = (string)($data['location'] ?? '');
		$customPath = (string)($data['customPath'] ?? '');
		$docroot    = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', DIRECTORY_SEPARATOR);
		$locale     = (string)$this->session->get('setup_locale', 'en_US');

		// Form-level guard kept here so we can surface a translated message
		// rather than the installer's generic "no location selected" text.
		if ($location === '') {
			$flash->add('error', $this->translator->trans('flash.datapath_select_location'));

			return $this->redirectRenderer->redirectFor($response, 'setup-data-path');
		}

		try {
			$this->installer->install($location, $customPath, $docroot, $locale);
		} catch (\Throwable $e) {
			$flash->add('error', $e->getMessage());

			return $this->redirectRenderer->redirectFor($response, 'setup-data-path');
		}

		$this->setupState->completeStep('data-path');

		return $this->redirectRenderer->redirectFor($response, 'setup-account');
	}
}
