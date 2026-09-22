<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Composer\InstalledVersions;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;
use TotalCMS\Support\Version;

/**
 * Discovers extensions from four sources:
 *
 *  1. the bundled path shipped in the T3 package (`resources/extensions/`)
 *  2. the user-installed path (`tcms-data/extensions/`)
 *  3. Composer: every installed package of type `totalcms-extension`, read
 *     from its install path under `vendor/` (what `composer require` gives)
 *  4. the project path (`extensions/` at the project root, next to tcms-data)
 *
 * The project root exists for site-specific extensions that live in the
 * site's own git repo: tcms-data is content (backed up, not versioned), while
 * a project extension is code — keeping it outside tcms-data means source
 * control needs no gitignore allow-list carving. Directory-existence gated:
 * no `extensions/` folder, no scan (same convention as the builder folder).
 *
 * Bundled/project origins are flagged on the returned manifest so the admin
 * UI / CLI can hide destructive actions (neither can be "removed" — bundled
 * ships with the package, project belongs to source control).
 *
 * Composer packages need no installer and no autoload work: Composer already
 * autoloads their classes and `composer update` moves them. The version
 * Composer reports is the one that counts, so it replaces the manifest's.
 * Ownership is Composer's — such an extension can be disabled but not
 * removed here, the same rule as bundled and project.
 *
 * Conflict resolution: later sources win — project over Composer over
 * user-installed over bundled. User-over-bundled gives admins a deliberate override path for
 * bundled extensions (e.g. patching a bug locally before the next release) —
 * same idea as a `node_modules` package shadowing a global one. Project-over-
 * user resolves a half-finished migration in favor of the source-controlled
 * copy. A warning is logged so no override goes unnoticed.
 */
final class ExtensionDiscovery
{
	/** @var array<string,string> Extension ID => absolute directory path */
	private array $discoveredPaths = [];

	/**
	 * @param string|null $projectExtensionsDir override for the project-level
	 *                                          extensions directory (tests);
	 *                                          defaults to `<projectRoot>/extensions`
	 * @param string|null $bundledExtensionsDir override for the bundled
	 *                                          extensions directory (tests);
	 *                                          defaults to `<packageRoot>/resources/extensions`
	 * @param \Closure|null $composerPackages   override for what Composer reports
	 *                                          as installed `totalcms-extension`
	 *                                          packages (tests): returns
	 *                                          `package name => [path, version]`;
	 *                                          defaults to Composer\InstalledVersions
	 */
	public function __construct(
		private readonly Config $config,
		private readonly ManifestValidator $validator,
		private readonly LoggerInterface $logger,
		private readonly ?string $projectExtensionsDir = null,
		private readonly ?string $bundledExtensionsDir = null,
		private readonly ?\Closure $composerPackages = null,
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

		// Bundled first; user-installed overrides bundled; Composer overrides
		// both; project overrides everything.
		$bundled  = $this->scanPath($this->getBundledExtensionsDirectory(), bundled: true);
		$user     = $this->scanPath($this->getExtensionsDirectory(), bundled: false);
		$composer = $this->scanComposerPackages();
		$project  = $this->scanPath($this->getProjectExtensionsDirectory(), bundled: false, project: true);

		// Later sources win on collision. Log so no override goes unnoticed.
		foreach (array_keys($user) as $id) {
			if (isset($bundled[$id])) {
				$this->logger->info(
					"Extension '{$id}' is bundled with Total CMS but also installed in tcms-data — the user-installed copy will be loaded.",
				);
			}
		}
		foreach ($composer as $id => $manifest) {
			if (isset($user[$id])) {
				$this->logger->warning(
					"Extension '{$id}' is installed by Composer ({$manifest->composerPackage}) but also present in tcms-data/extensions — the Composer copy will be loaded. Remove the tcms-data copy to silence this warning.",
				);
			} elseif (isset($bundled[$id])) {
				$this->logger->info(
					"Extension '{$id}' is bundled with Total CMS but also installed by Composer ({$manifest->composerPackage}) — the Composer copy will be loaded.",
				);
			}
		}
		foreach (array_keys($project) as $id) {
			if (isset($composer[$id])) {
				$this->logger->info(
					"Extension '{$id}' is installed by Composer but also present in the project extensions directory — the project copy will be loaded.",
				);
			} elseif (isset($user[$id])) {
				$this->logger->warning(
					"Extension '{$id}' exists in both the project extensions directory and tcms-data/extensions — the project copy will be loaded. Remove the tcms-data copy to silence this warning.",
				);
			} elseif (isset($bundled[$id])) {
				$this->logger->info(
					"Extension '{$id}' is bundled with Total CMS but also present in the project extensions directory — the project copy will be loaded.",
				);
			}
		}

		return array_merge($bundled, $user, $composer, $project);
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

		// Fallback: reconstruct from ID. Check in override order — project
		// first, then user, then bundled (matches discover() precedence).
		$parts = explode('/', $extensionId, 2);
		if (count($parts) !== 2) {
			return null;
		}

		foreach ([$this->getProjectExtensionsDirectory(), $this->getExtensionsDirectory(), $this->getBundledExtensionsDirectory()] as $base) {
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
	 * Path to project-level extensions: `extensions/` at the project root,
	 * next to tcms-data. Site-owned source code, typically committed to the
	 * site's git repo. Purely convention-based — the directory existing is
	 * the only switch.
	 */
	public function getProjectExtensionsDirectory(): string
	{
		return $this->projectExtensionsDir ?? PathResolver::projectRoot() . '/extensions';
	}

	/**
	 * Path to bundled extensions shipped with the T3 package. These cannot
	 * be removed — only disabled via the existing extension-permission UI.
	 */
	public function getBundledExtensionsDirectory(): string
	{
		return $this->bundledExtensionsDir ?? (PathResolver::packageRoot() . '/resources/extensions');
	}

	/**
	 * Scan a single base path. Sets the bundled/project flags on each manifest
	 * so downstream code can distinguish package-shipped, user-installed, and
	 * project-owned extensions.
	 *
	 * @return array<string,ExtensionManifest>
	 */
	private function scanPath(string $extensionsDir, bool $bundled, bool $project = false): array
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
					$flagged = $manifest->withBundled($bundled)->withProject($project);

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

	/**
	 * Every installed Composer package of type `totalcms-extension`, read
	 * from its install path. No directory layout to scan: Composer knows the
	 * path, and the package's own composer.json chose the type.
	 *
	 * @return array<string,ExtensionManifest>
	 */
	private function scanComposerPackages(): array
	{
		$manifests = [];

		foreach ($this->installedComposerExtensions() as $package => $installed) {
			$extPath      = rtrim($installed['path'], '/');
			$manifestFile = $extPath . '/extension.json';

			if (!is_file($manifestFile)) {
				$this->logger->warning("Composer package '{$package}' is of type totalcms-extension but has no extension.json at {$extPath} — skipped.");

				continue;
			}

			$manifest = $this->loadManifest($manifestFile);
			if (!$manifest instanceof ExtensionManifest) {
				continue;
			}

			// Composer's version is what `composer update` moves; extension.json's
			// is whatever the author last remembered to bump. Report the former.
			$flagged = $manifest->withComposerPackage($package);
			if ($installed['version'] !== '') {
				$flagged = $flagged->withVersion(ltrim($installed['version'], 'v'));
			}

			$manifests[$flagged->id]             = $flagged;
			$this->discoveredPaths[$flagged->id] = $extPath;
		}

		return $manifests;
	}

	/**
	 * What Composer reports as installed with type `totalcms-extension`:
	 * `package name => [path, version]`. Reads Composer\InstalledVersions,
	 * which every Composer-built vendor/ ships (the zip dist's included); an
	 * install without it simply has none.
	 *
	 * @return array<string,array{path:string,version:string}>
	 */
	private function installedComposerExtensions(): array
	{
		if ($this->composerPackages instanceof \Closure) {
			/** @var array<string,array{path:string,version:string}> */
			return ($this->composerPackages)();
		}

		if (!class_exists(InstalledVersions::class)) {
			return [];
		}

		$installed = [];
		foreach (InstalledVersions::getInstalledPackagesByType('totalcms-extension') as $package) {
			$path = InstalledVersions::getInstallPath($package);
			if (!is_string($path) || !is_dir($path)) {
				continue;
			}
			$installed[$package] = [
				'path'    => realpath($path) ?: $path,
				'version' => (string)(InstalledVersions::getPrettyVersion($package) ?? ''),
			];
		}

		return $installed;
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
