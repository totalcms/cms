<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Install;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/**
 * Removes a user-installed extension: disable (so the extension.disabled
 * event fires and the agent skill is pulled), delete the directory, drop
 * the state record. Settings (`.system/extension-settings`) and data
 * (`.system/extension-data`) are kept — an operator who reinstalls gets
 * their configuration back.
 *
 * Shared by `tcms extension:remove` and the admin Remove button so both
 * refuse the same things with the same words.
 */
class ExtensionRemover
{
	public function __construct(
		private readonly ExtensionDiscovery $discovery,
		private readonly ExtensionManager $manager,
		private readonly ExtensionStateRepository $states,
	) {
	}

	/**
	 * Throws unless the extension exists and is one this class may delete.
	 * The CLI calls it before prompting so nobody confirms an impossible removal.
	 *
	 * @throws InstallException
	 */
	public function assertRemovable(string $id): void
	{
		$this->resolveRemovablePath($id);
	}

	/**
	 * @throws InstallException
	 */
	public function remove(string $id): void
	{
		// Only ever delete the directory discovery found a user manifest in —
		// never a path reconstructed from the raw id.
		$path = $this->resolveRemovablePath($id);

		$this->manager->disable($id);
		$this->deleteTree($path);
		$this->states->removeState($id);
	}

	/**
	 * The directory a user-installed extension was discovered in, or an
	 * InstallException explaining why it may not be removed. The id must be a
	 * well-formed `vendor/name` (it arrives from a URL or the command line, and
	 * `../..` must never become a path), discovery must have found a manifest
	 * for it, the manifest must come from tcms-data/extensions, and the path
	 * must sit exactly two levels below that directory.
	 *
	 * @throws InstallException
	 */
	private function resolveRemovablePath(string $id): string
	{
		if (preg_match('#^[a-z0-9-]+/[a-z0-9-]+\z#', $id) !== 1) {
			throw InstallException::notFound("Extension '{$id}' not found: extension ids look like vendor/name, in lowercase letters, digits and hyphens");
		}

		$manifest = $this->discovery->discover()[$id] ?? null;
		if (!$manifest instanceof ExtensionManifest) {
			throw InstallException::notFound("Extension '{$id}' not found");
		}

		// Bundled extensions ship with the T3 package and are managed by core
		// updates. Removing files would be undone on the next composer/zip
		// install, so we refuse and point the user at the disable path.
		if ($manifest->bundled) {
			throw InstallException::ownedElsewhere("Extension '{$id}' is bundled with Total CMS and cannot be removed. Use `tcms extension:disable {$id}` to turn it off.");
		}

		// Project extensions live in the site's source-controlled extensions/
		// directory; deleting files behind the repo's back invites a git-vs-disk
		// mismatch. Removal belongs in source control.
		if ($manifest->project) {
			throw InstallException::ownedElsewhere("Extension '{$id}' lives in the project extensions directory and cannot be removed here. Delete it from source control instead, or use `tcms extension:disable {$id}` to turn it off.");
		}

		// Composer installed it into vendor/ and Composer takes it out again;
		// deleting the directory by hand leaves composer.json and the lockfile
		// still requiring it, and the next `composer install` puts it back.
		if ($manifest->composerPackage !== '') {
			throw InstallException::ownedElsewhere("Extension '{$id}' is installed by Composer and cannot be removed here. Run `composer remove {$manifest->composerPackage}` instead, or use `tcms extension:disable {$id}` to turn it off.");
		}

		if ($manifest->origin() !== 'user') {
			throw InstallException::ownedElsewhere("Extension '{$id}' is not a user-installed extension and cannot be removed here.");
		}

		// discover() just ran, so this is the discovered path for the winning
		// (user) manifest. Still confirm it is a real directory exactly two
		// levels under tcms-data/extensions before anything is deleted.
		$path     = $this->discovery->getExtensionPath($id);
		$real     = $path !== null ? realpath($path) : false;
		$userRoot = realpath($this->discovery->getExtensionsDirectory());
		if ($real === false || $userRoot === false || !is_dir($real) || dirname($real, 2) !== $userRoot || !is_file($real . '/extension.json')) {
			throw InstallException::notFound("Extension '{$id}' not found");
		}

		return $real;
	}

	private function deleteTree(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			/** @var \SplFileInfo $item */
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($path);
	}
}
