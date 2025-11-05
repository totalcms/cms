<?php

namespace TotalCMS\Action\Setup;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Settings\Services\DataDirectoryManager;
use TotalCMS\Domain\Settings\Services\InstallationSettingsSaver;
use TotalCMS\Renderer\RedirectRenderer;

/**
 * Handle data path setup form submission.
 * Creates the data directory and saves custom paths to tcms.php if needed.
 */
readonly class DataPathSetupSubmitAction
{
	public function __construct(
		private DataDirectoryManager $directoryManager,
		private InstallationSettingsSaver $installationSettingsSaver,
		private PhpSession $session,
		private RedirectRenderer $redirectRenderer,
	) {
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data  = (array)$request->getParsedBody();
		$flash = $this->session->getFlash();

		$location   = $data['location'] ?? '';
		$customPath = (string)($data['customPath'] ?? '');

		// Get docroot from $_SERVER['DOCUMENT_ROOT']
		$docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', DIRECTORY_SEPARATOR);

		// Resolve the data path based on location choice
		$dataPath = $this->directoryManager->resolveDataPath($location, $docroot, $customPath);

		// Validation
		if ($dataPath === '') {
			$flash->add('error', 'Please select a location for the data folder.');

			return $this->redirectRenderer->redirectFor($response, 'setup-data-path');
		}

		// For custom paths, validate absolute path and parent directory
		if ($location === 'custom') {
			try {
				$this->directoryManager->validateAbsolutePath($dataPath);
				$this->directoryManager->validateParentDirectory($dataPath);
			} catch (\InvalidArgumentException | \RuntimeException $e) {
				$flash->add('error', $e->getMessage());

				return $this->redirectRenderer->redirectFor($response, 'setup-data-path');
			}
		}

		// Create directory if it doesn't exist
		if (!file_exists($dataPath)) {
			try {
				$this->directoryManager->createDirectory($dataPath);
			} catch (\RuntimeException $e) {
				$flash->add('error', $e->getMessage());

				return $this->redirectRenderer->redirectFor($response, 'setup-data-path');
			}
		}

		// Verify directory is valid and writable
		try {
			$this->directoryManager->validateDirectory($dataPath);
		} catch (\RuntimeException $e) {
			$flash->add('error', $e->getMessage());

			return $this->redirectRenderer->redirectFor($response, 'setup-data-path');
		}

		// Clean up empty default directory if user chose a different path
		$defaultPath = dirname($docroot) . '/tcms-data';
		if ($location !== 'default') {
			$this->directoryManager->cleanupEmptyDefaultDirectory($defaultPath, $dataPath);
		}

		// Save to tcms.php if custom path
		if ($location === 'custom') {
			try {
				$this->installationSettingsSaver->saveSettings([
					'datadir' => $dataPath,
				]);
			} catch (\Exception $e) {
				$flash->add('error', 'Failed to save configuration: ' . $e->getMessage());

				return $this->redirectRenderer->redirectFor($response, 'setup-data-path');
			}
		}

		// Success! Redirect to login page for first-time user creation
		// $flash->add('success', 'Data path configured successfully! Now create your first user.');

		return $this->redirectRenderer->redirectFor($response, 'login');
	}
}
