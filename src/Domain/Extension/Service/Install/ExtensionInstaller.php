<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Install;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\DangerousCodeScanner;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Infrastructure\Filesystem\FileUtils;
use TotalCMS\Support\Config;
use TotalCMS\Support\QuietCall;

/**
 * Installs an extension zip into `tcms-data/extensions/{vendor}/{name}`.
 *
 * The admin upload, `tcms extension:install` and, later, the marketplace
 * client all come through here. Order of operations:
 *
 *  1. inspect the archive (ExtensionArchive — path safety, manifest)
 *  2. ownership: an id that bundled, Composer or project code already owns is
 *     refused; an existing user copy means this is an update
 *  3. extract into a staging dir under tmpdir
 *  4. scan the staged tree with DangerousCodeScanner
 *  5. state, written BEFORE the swap and put back if the swap fails: a fresh
 *     install writes none and removes any orphaned record (so it lands
 *     disabled and Enable runs the review); an update keeps state/permissions/settings, records
 *     the new version, clears error + quarantine, and — if the extension is
 *     enabled and the scan found something — disables it with the same
 *     updateDisabled record ExtensionEnrollment writes. That closes the gap
 *     where a developer re-ships the same version number and the
 *     version-mismatch gate never fires.
 *  6. move the old directory aside (to a dot-prefixed folder discovery
 *     ignores), move staging into place, restore on failure
 *  7. reset the opcode cache so the next request runs the new files
 *
 * Nothing here enables an extension.
 */
class ExtensionInstaller
{
	public function __construct(
		private readonly ExtensionArchive $archive,
		private readonly ExtensionDiscovery $discovery,
		private readonly ExtensionStateRepository $states,
		private readonly Config $config,
		private readonly LoggerInterface $logger,
		private readonly DangerousCodeScanner $scanner = new DangerousCodeScanner(),
		private readonly ?\Closure $moveTree = null,
	) {
	}

	/**
	 * Inspect + ownership check, nothing written. The CLI shows this before
	 * asking the operator to confirm an update.
	 *
	 * @throws InstallException
	 */
	public function plan(string $zipPath): InstallPlan
	{
		$plan     = $this->archive->inspect($zipPath);
		$id       = $plan->manifest->id;
		$existing = $this->discovery->discover()[$id] ?? null;

		if ($existing instanceof ExtensionManifest) {
			$this->assertReplaceable($id, $existing);
			$this->assertCanonicalLocation($id);
		}

		return $plan->withExisting($existing);
	}

	/**
	 * @throws InstallException
	 */
	public function install(string $zipPath): InstallResult
	{
		$plan   = $this->plan($zipPath);
		$id     = $plan->manifest->id;
		$target = $this->discovery->getExtensionsDirectory() . '/' . $id;

		$staging = $this->stagingDirectory();
		try {
			$this->archive->extractTo($zipPath, $plan, $staging);
			$findings = $this->scanner->scan($staging);

			// Write the post-install state BEFORE the new files land, so risky
			// code never sits under `enabled: true` — not even if saving the
			// state fails. Put the old record back if the swap does not happen.
			$previous = $this->states->getState($id);
			$previous = $previous instanceof ExtensionState ? clone $previous : null;
			$disabled = $this->reconcileState($id, $plan, count($findings));

			try {
				$this->swap($staging, $target, $id);
			} catch (\Throwable $e) {
				$this->restoreState($id, $previous);

				throw $e;
			}
		} finally {
			$this->deleteTree(dirname($staging));
		}

		$this->resetRuntimeCaches();

		$this->logger->info(sprintf(
			"Extension '%s' %s at version %s from zip (%d scan findings%s).",
			$id,
			$plan->isUpdate() ? 'updated' : 'installed',
			$plan->manifest->version,
			count($findings),
			$disabled ? ', disabled pending review' : '',
		));

		return new InstallResult(
			id: $id,
			name: $plan->manifest->name,
			version: $plan->manifest->version,
			previousVersion: $plan->existing?->version,
			isUpdate: $plan->isUpdate(),
			findings: count($findings),
			disabled: $disabled,
			warnings: $plan->warnings,
			path: $target,
		);
	}

	/**
	 * Only a user-installed copy may be replaced. The other three origins are
	 * owned by the package, by Composer or by source control; overwriting them
	 * here would be undone by the next update, `composer install` or `git pull`,
	 * or would silently shadow code the site's repo thinks it is running.
	 */
	private function assertReplaceable(string $id, ExtensionManifest $existing): void
	{
		switch ($existing->origin()) {
			case 'bundled':
				throw InstallException::ownedElsewhere("Extension '{$id}' is bundled with Total CMS and cannot be replaced by an upload. Disable it instead, or install the extension under a different id.");
			case 'project':
				throw InstallException::ownedElsewhere("Extension '{$id}' lives in the project extensions directory and cannot be replaced by an upload. Update it in source control instead.");
			case 'composer':
				throw InstallException::ownedElsewhere("Extension '{$id}' is installed by Composer and cannot be replaced by an upload. Run `composer update {$existing->composerPackage}` instead, or `composer remove {$existing->composerPackage}` and upload again.");
			default:
				return;
		}
	}

	/**
	 * Discovery accepts an id whose folder differs from `{vendor}/{name}`
	 * (a hand-copied `foo/bar` holding `acme/thing`). Installing next to it
	 * would leave two copies with one id, and discovery would load whichever
	 * it scanned last — maybe the old one. Refuse and name the old copy.
	 *
	 * @throws InstallException
	 */
	private function assertCanonicalLocation(string $id): void
	{
		$existingPath = $this->discovery->getExtensionPath($id);
		$canonical    = $this->discovery->getExtensionsDirectory() . '/' . $id;
		$resolved     = $existingPath !== null ? realpath($existingPath) : false;

		if ($resolved !== false && $resolved === realpath($canonical)) {
			return;
		}

		$where = $existingPath ?? 'an unexpected location';

		throw InstallException::ownedElsewhere("Extension '{$id}' is already installed at {$where}, not at {$canonical}. Remove the existing copy first, then upload again.");
	}

	/**
	 * Put back the state record that was replaced before a failed swap.
	 * Logged, not thrown: the swap's own error is the one the operator needs.
	 */
	private function restoreState(string $id, ?ExtensionState $previous): void
	{
		try {
			if ($previous instanceof ExtensionState) {
				$this->states->saveState($id, $previous);
			} elseif ($this->states->getState($id) instanceof ExtensionState) {
				$this->states->removeState($id);
			}
		} catch (\Throwable $e) {
			$this->logger->error("Extension '{$id}': could not restore the previous state after a failed install: {$e->getMessage()}");
		}
	}

	private function stagingDirectory(): string
	{
		$root = rtrim($this->config->tmpdir, '/') . '/extension-install/' . bin2hex(random_bytes(8));
		$dir  = $root . '/tree';
		if (!FileUtils::ensureDirectory($dir)) {
			throw InstallException::writeFailed("Could not create a staging directory under {$this->config->tmpdir}.");
		}

		return $dir;
	}

	/**
	 * Move the staged tree into place. An existing directory is renamed aside
	 * first and restored if the install cannot complete. rename() fails
	 * across devices (tmpdir on another mount), so fall back to copy + delete.
	 */
	private function swap(string $staging, string $target, string $id): void
	{
		$parent = dirname($target);
		if (!FileUtils::ensureDirectory($parent)) {
			throw InstallException::writeFailed("Could not create {$parent}.");
		}

		// The backup sits directly under the extensions directory with a dot
		// prefix: discovery skips dot entries, so the old copy can never be
		// picked up as a second extension with the same id.
		$backup = null;
		if (is_dir($target)) {
			$backup = $this->discovery->getExtensionsDirectory() . '/.backup-' . str_replace('/', '-', $id) . '-' . gmdate('YmdHis');
			if (file_exists($backup)) {
				$backup .= '-' . bin2hex(random_bytes(3));
			}
			if (!QuietCall::run(fn (): bool => rename($target, $backup))) {
				throw InstallException::writeFailed("Could not move the existing {$target} aside — check directory permissions.");
			}
		}

		try {
			($this->moveTree ?? $this->defaultMoveTree(...))($staging, $target);
		} catch (\Throwable $e) {
			$this->deleteTree($target);
			if ($backup !== null && !@rename($backup, $target)) {
				$this->logger->error("Could not restore the previous extension files from {$backup} to {$target}; restore them by hand.");

				throw InstallException::writeFailed('Could not install the extension files (' . $e->getMessage() . "), and the previous version could not be put back. It is saved at {$backup}.");
			}

			throw InstallException::writeFailed('Could not install the extension files: ' . $e->getMessage());
		}

		if ($backup !== null) {
			$this->deleteTree($backup);
		}
	}

	/**
	 * rename() when possible; copy + verify otherwise. copyTree() reports
	 * failures instead of throwing, so turn them into an exception here.
	 *
	 * @throws \RuntimeException
	 */
	private function defaultMoveTree(string $staging, string $target): void
	{
		if (@rename($staging, $target)) {
			return;
		}

		$copied = FileUtils::copyTree($staging, $target);
		if ($copied['failed'] !== []) {
			throw new \RuntimeException('Could not copy: ' . implode(', ', array_slice($copied['failed'], 0, 5)));
		}
	}

	/**
	 * @return bool true when an enabled extension was disabled by the scan
	 */
	private function reconcileState(string $id, InstallPlan $plan, int $findings): bool
	{
		if (!$plan->isUpdate()) {
			// A state record with no directory is orphaned (folder deleted by
			// hand); drop it so a re-upload cannot boot without review.
			if ($this->states->getState($id) instanceof ExtensionState) {
				$this->states->removeState($id);
			}

			return false;
		}

		$state = $this->states->getState($id);
		if (!$state instanceof ExtensionState) {
			return false;
		}

		$state->version = $plan->manifest->version;
		$state->error   = null;
		$state->clearQuarantine();

		$disabled = false;
		if ($state->enabled && $findings > 0) {
			$state->enabled        = false;
			$state->updateDisabled = [
				'reason'    => 'A new version of this extension added risky code patterns.',
				'findings'  => $findings,
				'updatedAt' => gmdate('c'),
			];
			$disabled = true;
			$this->logger->warning("Extension '{$id}' disabled: the uploaded version added risky code ({$findings} findings).");
		}

		$this->states->saveState($id, $state);

		return $disabled;
	}

	private function resetRuntimeCaches(): void
	{
		if (function_exists('opcache_reset')) {
			@opcache_reset();
		}
		clearstatcache(true);
	}

	private function deleteTree(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			/** @var \SplFileInfo $item */
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($dir);
	}
}
