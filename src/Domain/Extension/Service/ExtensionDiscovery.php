<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;
use TotalCMS\Support\Version;

/**
 * Discovers extensions by scanning both the bundled path (in the T3 package)
 * and the user-installed path (in tcms-data). Bundled extensions are flagged
 * on the returned manifest so the admin UI / CLI can hide destructive actions.
 *
 * Conflict resolution: if the same extension id is present in both paths, the
 * **user-installed copy wins**. This gives admins a deliberate override path
 * for bundled extensions (e.g. patching a bug locally before the next release)
 * — same idea as a `node_modules` package shadowing a global one. A warning is
 * logged so the override doesn't go unnoticed.
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
	 * Scan the bundled + user extension directories and return all valid
	 * manifests, keyed by extension ID. Bundled-flag is set on each manifest
	 * based on which directory it came from.
	 *
	 * @return array<string,ExtensionManifest>
	 */
	public function discover(): array
	{
		// Reset between calls so re-discovery in tests doesn't accumulate paths.
		$this->discoveredPaths = [];

		// Bundled first; user-installed can override.
		$bundled = $this->scanPath($this->getBundledExtensionsDirectory(), bundled: true);
		$user    = $this->scanPath($this->getExtensionsDirectory(), bundled: false);

		// User-installed wins on collision. Log so the override is visible.
		foreach ($user as $id => $manifest) {
			if (isset($bundled[$id])) {
				$this->logger->info(
					"Extension '{$id}' is bundled with Total CMS but also installed in tcms-data — the user-installed copy will be loaded.",
				);
			}
		}

		return array_merge($bundled, $user);
	}

	/**
	 * Get the absolute path to a specific extension's directory.
	 */
	public function getExtensionPath(string $extensionId): ?string
	{
		// Use discovered path if available (handles ID ≠ directory name).
		if (isset($this->discoveredPaths[$extensionId])) {
			return $this->discoveredPaths[$extensionId];
		}

		// Fallback: reconstruct from ID. Check user dir first (matches override
		// semantics), then bundled.
		$parts = explode('/', $extensionId, 2);
		if (count($parts) !== 2) {
			return null;
		}

		foreach ([$this->getExtensionsDirectory(), $this->getBundledExtensionsDirectory()] as $base) {
			$path = $base . '/' . $parts[0] . '/' . $parts[1];
			if (is_dir($path)) {
				return $path;
			}
		}

		return null;
	}

	public function getExtensionsDirectory(): string
	{
		return rtrim($this->config->datadir, '/') . '/extensions';
	}

	/**
	 * Path to bundled extensions shipped with the T3 package. These cannot
	 * be removed — only disabled via the existing extension-permission UI.
	 */
	public function getBundledExtensionsDirectory(): string
	{
		return PathResolver::packageRoot() . '/resources/extensions';
	}

	/**
	 * Scan a single base path. Sets the bundled flag on each manifest so
	 * downstream code can distinguish package-shipped from user-installed.
	 *
	 * @return array<string,ExtensionManifest>
	 */
	private function scanPath(string $extensionsDir, bool $bundled): array
	{
		if (!is_dir($extensionsDir)) {
			return [];
		}

		$manifests = [];

		foreach ($this->scanDirectory($extensionsDir) as $vendor) {
			$vendorPath = $extensionsDir . '/' . $vendor;
			if (!is_dir($vendorPath)) {
				continue;
			}

			foreach ($this->scanDirectory($vendorPath) as $extension) {
				$extPath      = $vendorPath . '/' . $extension;
				$manifestFile = $extPath . '/extension.json';

				if (!is_file($manifestFile)) {
					continue;
				}

				$manifest = $this->loadManifest($manifestFile);
				if ($manifest instanceof ExtensionManifest) {
					$flagged = $manifest->withBundled($bundled);

					// Bundled extensions ship in the T3 package — they can't
					// have a different version than core. Force the manifest
					// version to match so `extension:list` reports the truth
					// (and devs writing bundled extensions don't have to
					// remember to bump per-extension versions on each release).
					if ($bundled) {
						$flagged = $flagged->withVersion(Version::number());
					}

					$manifests[$flagged->id]             = $flagged;
					$this->discoveredPaths[$flagged->id] = $extPath;
				}
			}
		}

		return $manifests;
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

		// Compatibility (Total CMS / PHP version) is intentionally NOT enforced here.
		// Incompatible extensions are returned so they remain visible in the admin UI
		// with a "cannot be enabled" message. Enable() guards against actually loading them.

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
