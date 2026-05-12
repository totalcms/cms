<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Setup\Service;

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Settings\Services\DataDirectoryManager;
use TotalCMS\Domain\Settings\Services\InstallationSettingsSaver;
use TotalCMS\Support\Config;

/**
 * Provisions the tcms-data directory based on the user's data-path
 * step submission.
 *
 * Single business operation:
 *   resolve path -> validate -> migrate-or-create -> validate ->
 *   sync in-memory Config -> sweep auto-default leftovers ->
 *   persist custom path to tcms.php -> stamp locale -> clear caches.
 *
 * Lives behind a thin HTTP action (DataPathSetupSubmitAction) that
 * does form parsing + flash messaging + redirects.
 *
 * Throws \InvalidArgumentException for input/path validation errors
 * and \RuntimeException for filesystem and persistence failures.
 */
readonly class DataPathInstaller
{
	public function __construct(
		private DataDirectoryManager $directoryManager,
		private InstallationSettingsSaver $settingsSaver,
		private CacheManager $cacheManager,
		private Config $config,
	) {
	}

	/**
	 * Install the data directory at the user's chosen location.
	 *
	 * @param string $location 'default' (above docroot) | 'docroot' | 'custom'
	 * @param string $customPath Used only when $location === 'custom'
	 * @param string $docroot Document root (typically $_SERVER['DOCUMENT_ROOT'])
	 * @param string $locale Wizard's selected locale, written to settings.json
	 *
	 * @throws \InvalidArgumentException when the location is empty or a
	 *                                   custom path fails validation
	 * @throws \RuntimeException         when directory creation, validation,
	 *                                   or settings persistence fails
	 *
	 * @return string the resolved absolute data path
	 */
	public function install(string $location, string $customPath, string $docroot, string $locale): string
	{
		$dataPath = $this->directoryManager->resolveDataPath($location, $docroot, $customPath);
		if ($dataPath === '') {
			throw new \InvalidArgumentException('No data location selected.');
		}

		if ($location === 'custom') {
			$this->directoryManager->validateAbsolutePath($dataPath);
			$this->directoryManager->validateParentDirectory($dataPath);
		}

		$this->provisionDirectory($docroot, $dataPath);
		$this->directoryManager->validateDirectory($dataPath);

		// Sync the in-memory Config so the rest of the request (notably
		// SetupStateManager::completeStep, which reads/writes the state file
		// under datadir) sees the user's chosen path. Otherwise Config stays
		// pinned to the auto-detected default resolved at bootstrap, and
		// downstream writes go to a path that may no longer exist.
		$this->config->datadir = $dataPath;

		if ($location === 'custom') {
			$this->settingsSaver->saveSettings(['datadir' => $dataPath]);
		}

		$this->writeLocale($dataPath, $locale);
		$this->cacheManager->clearAllCaches();

		return $dataPath;
	}

	/**
	 * Migrate any auto-bootstrap state from the auto-default candidates into
	 * the chosen path; otherwise create a fresh directory there. Bootstrap
	 * services (ExtensionManager, etc.) write to whichever default
	 * defaults.php resolved to during the wizard flow — moving it preserves
	 * that state instead of throwing it away.
	 */
	private function provisionDirectory(string $docroot, string $dataPath): void
	{
		$candidates = $this->autoDefaultCandidates($docroot);

		foreach ($candidates as $candidate) {
			if ($this->directoryManager->moveDataDirectory($candidate, $dataPath)) {
				return;
			}
		}

		if (!is_dir($dataPath)) {
			$this->directoryManager->createDirectory($dataPath);
		}
	}

	/**
	 * @return list<string>
	 */
	private function autoDefaultCandidates(string $docroot): array
	{
		return [
			dirname($docroot) . '/tcms-data',
			$docroot . '/tcms-data',
		];
	}

	/**
	 * Persist the wizard's selected locale into the data directory's
	 * settings.json. Called late so the directory is guaranteed to exist
	 * and Config has the new datadir.
	 */
	private function writeLocale(string $dataPath, string $locale): void
	{
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
		@file_put_contents($settingsFile, (string)json_encode($existing, JSON_PRETTY_PRINT));
	}
}
