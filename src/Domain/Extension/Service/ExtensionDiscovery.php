<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Support\Config;

/**
 * Discovers extensions by scanning the extensions directory for valid manifests.
 */
final class ExtensionDiscovery
{
	/** @var array<string,string> Extension ID => absolute directory path */
	private array $discoveredPaths = [];

	public function __construct(
		private readonly Config $config,
		private readonly ManifestValidator $validator,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Scan the extensions directory and return all valid manifests.
	 *
	 * @return array<string,ExtensionManifest> Keyed by extension ID
	 */
	public function discover(): array
	{
		$extensionsDir = $this->getExtensionsDirectory();
		if (!is_dir($extensionsDir)) {
			return [];
		}

		$manifests = [];

		// Scan vendor directories
		$vendors = $this->scanDirectory($extensionsDir);
		foreach ($vendors as $vendor) {
			$vendorPath = $extensionsDir . '/' . $vendor;
			if (!is_dir($vendorPath)) {
				continue;
			}

			// Scan extension directories within each vendor
			$extensions = $this->scanDirectory($vendorPath);
			foreach ($extensions as $extension) {
				$extPath      = $vendorPath . '/' . $extension;
				$manifestFile = $extPath . '/extension.json';

				if (!is_file($manifestFile)) {
					continue;
				}

				$manifest = $this->loadManifest($manifestFile);
				if ($manifest instanceof ExtensionManifest) {
					$manifests[$manifest->id]             = $manifest;
					$this->discoveredPaths[$manifest->id] = $extPath;
				}
			}
		}

		return $manifests;
	}

	/**
	 * Get the absolute path to a specific extension's directory.
	 */
	public function getExtensionPath(string $extensionId): ?string
	{
		// Use discovered path if available (handles ID ≠ directory name)
		if (isset($this->discoveredPaths[$extensionId])) {
			return $this->discoveredPaths[$extensionId];
		}

		// Fallback: reconstruct from ID
		$parts = explode('/', $extensionId, 2);
		if (count($parts) !== 2) {
			return null;
		}

		$path = $this->getExtensionsDirectory() . '/' . $parts[0] . '/' . $parts[1];

		return is_dir($path) ? $path : null;
	}

	public function getExtensionsDirectory(): string
	{
		return rtrim($this->config->datadir, '/') . '/extensions';
	}

	private function loadManifest(string $manifestFile): ?ExtensionManifest
	{
		$json = file_get_contents($manifestFile);
		if ($json === false) {
			$this->logger->warning("Failed to read manifest: {$manifestFile}");

			return null;
		}

		$data = json_decode($json, true);
		if (!is_array($data)) {
			$this->logger->warning("Invalid JSON in manifest: {$manifestFile}");

			return null;
		}

		$manifest = ExtensionManifest::fromArray($data);
		$error    = $this->validator->validate($manifest);

		if ($error !== null) {
			$this->logger->warning("Invalid extension manifest at {$manifestFile}: {$error}");

			return null;
		}

		if (!$this->validator->isCompatible($manifest)) {
			$this->logger->info("Extension {$manifest->id} requires T3 {$manifest->requiresTotalCmsVersion()}, skipping");

			return null;
		}

		return $manifest;
	}

	/**
	 * @return list<string>
	 */
	private function scanDirectory(string $path): array
	{
		$entries = scandir($path);
		if ($entries === false) {
			return [];
		}

		return array_values(array_filter(
			$entries,
			fn (string $entry): bool => $entry !== '.' && $entry !== '..' && !str_starts_with($entry, '.')
		));
	}
}
