<?php

namespace TotalCMS\Action\Setup;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Settings\Services\DataDirectoryManager;
use TotalCMS\Domain\Settings\Services\InstallationSettingsSaver;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Domain\Translation\TranslationService;
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
		private CacheManager $cacheManager,
		private PhpSession $session,
		private RedirectRenderer $redirectRenderer,
		private TranslationService $translator,
		private SetupStateManager $setupState,
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
			$flash->add('error', $this->translator->trans('flash.datapath_select_location'));

			return $this->redirectRenderer->redirectFor($response, 'setup-data-path');
		}

		// For custom paths, validate absolute path and parent directory
		if ($location === 'custom') {
			try {
				$this->directoryManager->validateAbsolutePath($dataPath);
				$this->directoryManager->validateParentDirectory($dataPath);
			} catch (\InvalidArgumentException|\RuntimeException $e) {
				$flash->add('error', $e->getMessage());

				return $this->redirectRenderer->redirectFor($response, 'setup-data-path');
			}
		}

		// Create directory if it doesn't exist
		if (!is_dir($dataPath)) {
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

		// Clean up empty alternative directory if user chose a different path
		// If user chose 'default' (above docroot), clean up docroot/tcms-data
		// If user chose 'docroot', clean up above-docroot/tcms-data
		$parentPath  = dirname($docroot) . '/tcms-data';
		$docrootPath = $docroot . '/tcms-data';

		if ($location === 'default') {
			$this->directoryManager->cleanupEmptyDefaultDirectory($docrootPath, $dataPath);
		} elseif ($location === 'docroot') {
			$this->directoryManager->cleanupEmptyDefaultDirectory($parentPath, $dataPath);
		} else {
			// Custom path - clean up both potential default locations
			$this->directoryManager->cleanupEmptyDefaultDirectory($parentPath, $dataPath);
			$this->directoryManager->cleanupEmptyDefaultDirectory($docrootPath, $dataPath);
		}

		// Save to tcms.php if custom path
		if ($location === 'custom') {
			try {
				$this->installationSettingsSaver->saveSettings([
					'datadir' => $dataPath,
				]);
			} catch (\Exception $e) {
				$flash->add('error', $this->translator->trans('flash.config_save_failed', ['{error}' => $e->getMessage()]));

				return $this->redirectRenderer->redirectFor($response, 'setup-data-path');
			}
		}

		// Save the user's language preference
		$locale    = $this->session->get('setup_locale', 'en_US');
		$systemDir = $dataPath . '/.system';
		if (!is_dir($systemDir)) {
			@mkdir($systemDir, 0755, true);
		}
		$settingsFile = $systemDir . '/settings.json';
		$existing     = [];
		if (is_file($settingsFile)) {
			$contents = (string)file_get_contents($settingsFile);
			if ($contents !== '') {
				$decoded  = json_decode($contents, true);
				$existing = is_array($decoded) ? $decoded : [];
			}
			@copy($settingsFile, $settingsFile . '.bak');
		}
		$existing['locale'] = $locale;
		file_put_contents($settingsFile, (string)json_encode($existing, JSON_PRETTY_PRINT));

		// Clear any stale cache data from previous installations
		$this->cacheManager->clearAllCaches();

		// Mark data path step complete and continue wizard
		$this->setupState->completeStep('data-path');

		return $this->redirectRenderer->redirectFor($response, 'setup-account');
	}
}
