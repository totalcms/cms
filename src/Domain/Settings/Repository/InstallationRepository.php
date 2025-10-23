<?php

namespace TotalCMS\Domain\Settings\Repository;

/**
 * Repository for managing tcms.php installation settings.
 *
 * Installation settings are bootstrap configuration that must be
 * available before the data directory is loaded (e.g., datadir path).
 *
 * @SuppressWarnings("PHPMD.Superglobals")
 */
readonly class InstallationRepository
{
	/**
	 * Load installation settings from tcms.php.
	 *
	 * @return array<string,mixed>
	 */
	public function load(): array
	{
		$configFile = $this->getConfigFilePath();
		if (!file_exists($configFile)) {
			return [];
		}

		$settings = require $configFile;

		return is_array($settings) ? $settings : [];
	}

	/**
	 * Save installation settings to tcms.php.
	 *
	 * Only saves datadir for now. Future: could include other
	 * bootstrap settings like custom vendor path, cache directory, etc.
	 *
	 * @param array<string,mixed> $settings
	 */
	public function save(array $settings): void
	{
		$configFile = $this->getConfigFilePath();

		// Only write if datadir is provided and not empty
		if (empty($settings['datadir'])) {
			// If tcms.php exists and datadir is empty, delete it to use default
			if (file_exists($configFile)) {
				unlink($configFile);
			}

			return;
		}

		// Build minimal PHP configuration file
		$configContent = "<?php\n\n";
		$configContent .= "// Total CMS Bootstrap Configuration\n";
		$configContent .= "// Only path settings that are needed before data directory is loaded\n\n";
		$configContent .= "return [\n";
		$configContent .= "\t'datadir' => '" . addslashes((string)$settings['datadir']) . "',\n";
		$configContent .= "];\n";

		file_put_contents($configFile, $configContent);
	}

	/**
	 * Check if tcms.php exists.
	 */
	public function exists(): bool
	{
		return file_exists($this->getConfigFilePath());
	}

	/**
	 * Get the full path to tcms.php.
	 */
	private function getConfigFilePath(): string
	{
		return $_SERVER['DOCUMENT_ROOT'] . '/tcms.php';
	}
}
