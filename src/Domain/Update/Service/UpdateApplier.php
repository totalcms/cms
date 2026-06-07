<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Update\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\PathResolver;

/**
 * Applies a downloaded update by swapping the shipped code, in place.
 *
 * Two rules make this safe to run from the web request that triggered it:
 *
 *  1. **Never replace user data.** Only the top-level items the update archive
 *     actually ships are touched, and the PRESERVE list is skipped even if the
 *     archive contains them — so `tcms-data/`, `.env`, the writable config, and
 *     `logs/` survive the update untouched. (The old approach moved the WHOLE
 *     install dir aside and refilled it from the zip, which deleted anything the
 *     zip didn't carry — i.e. the customer's content.)
 *
 *  2. **No application code after the swap.** Once the files are swapped, the
 *     running process can no longer reliably autoload any class it hadn't
 *     already loaded (the files on disk are now a different version). So caches
 *     are cleared BEFORE the swap (old code still fully loadable), and after the
 *     swap we only touch PHP built-ins — `opcache_reset()` / `clearstatcache()`
 *     — never an app service. The next request boots cleanly on the new code.
 *     (This is what caused "Class TextWatermarkFactory not found": clearAllCaches
 *     ran AFTER the swap and tried to autoload the watermark cleanup service.)
 */
class UpdateApplier
{
	/**
	 * Paths an update must never overwrite or move, even if the archive happens
	 * to contain them — user content, config, logs, and VCS metadata.
	 *
	 * @var list<string>
	 */
	private const PRESERVE = ['tcms-data', '.env', 'tcms.php', 'logs', '.git'];

	private readonly LoggerInterface $logger;
	private readonly string $appRoot;

	public function __construct(
		private readonly MaintenanceMode $maintenanceMode,
		private readonly CacheManager $cacheManager,
		LoggerFactory $loggerFactory,
		?string $appRoot = null,
	) {
		$this->logger  = $loggerFactory->channelLogger(LogChannel::Update);
		$this->appRoot = $appRoot ?? PathResolver::projectRoot();
	}

	/**
	 * Apply an update from a downloaded zip file.
	 */
	public function apply(string $zipPath, string $version): void
	{
		if (PathResolver::isComposerInstall()) {
			throw new \RuntimeException('This installation is managed by Composer. Run `composer update totalcms/cms` to update.');
		}

		$this->logger->info("Starting update to {$version}");

		// Extract NEXT TO the app root (same parent as the backup dir), NOT into
		// sys_get_temp_dir(). The swap installs files with rename(), which fails
		// with EXDEV across filesystems — and on many servers the system temp dir
		// is a different mount/tmpfs than the web root. Keeping the staging dir on
		// the app root's filesystem guarantees every rename (back up, install,
		// roll back) stays same-device. (Symptom of getting this wrong:
		// "Failed to install src".)
		$extractDir = $this->appRoot . '.update-extract-' . bin2hex(random_bytes(4));
		$backupDir  = $this->appRoot . '.backup-' . $version . '-' . date('Ymd-His');

		try {
			// Extract zip — `$source` is the real root of the new files (a single
			// wrapping directory, if any, is unwrapped).
			$source = $this->extract($zipPath, $extractDir);

			$this->maintenanceMode->enable();
			$this->logger->info('Maintenance mode enabled');

			// Clear caches BEFORE the swap — the old code is still fully loadable,
			// so this can't fault on a half-swapped filesystem.
			$this->cacheManager->clearAllCaches();
			$this->logger->info('Caches cleared');

			// Replace each shipped item, moving the old copy into the backup.
			// PRESERVE paths (tcms-data, .env, …) are never touched.
			$this->swapItems($source, $this->appRoot, $backupDir);
			$this->logger->info('Files swapped');

			// Past this point: PHP built-ins only — no app service may be called,
			// its class might not be loadable from the just-swapped files.
			$this->resetRuntimeCaches();

			$this->maintenanceMode->disable();
			$this->logger->info('Maintenance mode disabled');

			$this->deleteDirectory($backupDir);
			$this->logger->info("Update to {$version} complete");
		} catch (\Throwable $e) {
			$this->maintenanceMode->disable();
			$this->logger->error("Update failed: {$e->getMessage()}");

			// Restore the items we replaced. User data was never moved, so it's
			// still in place.
			if (is_dir($backupDir)) {
				try {
					$this->rollbackItems($backupDir, $this->appRoot);
					$this->resetRuntimeCaches();
					$this->deleteDirectory($backupDir);
					$this->logger->info('Rolled back to previous version after failure');
				} catch (\Throwable $rollbackError) {
					$this->logger->critical("Rollback also failed: {$rollbackError->getMessage()}");
				}
			}

			throw new \RuntimeException("Update to {$version} failed: {$e->getMessage()}", 0, $e);
		} finally {
			$this->deleteDirectory($extractDir);
			if (file_exists($zipPath)) {
				unlink($zipPath);
			}
		}
	}

	/**
	 * Roll back to the most recent backup left by a failed update.
	 */
	public function rollback(): void
	{
		$backupDir = $this->findLatestBackup();
		if ($backupDir === null) {
			throw new \RuntimeException('No backup found to roll back to.');
		}

		$this->logger->info("Rolling back to {$backupDir}");

		$this->maintenanceMode->enable();

		try {
			// Old code is being restored; clearing caches here is safe — the
			// restored classes are fully present once rollbackItems finishes.
			$this->rollbackItems($backupDir, $this->appRoot);
			$this->cacheManager->clearAllCaches();
			$this->resetRuntimeCaches();
			$this->maintenanceMode->disable();
			$this->logger->info('Rollback complete');

			$this->deleteDirectory($backupDir);
		} catch (\Throwable $e) {
			$this->maintenanceMode->disable();
			throw new \RuntimeException("Rollback failed: {$e->getMessage()}", 0, $e);
		}
	}

	/**
	 * Extract the archive and return the directory that actually holds the new
	 * files — unwrapping a single top-level wrapper directory if the archive has
	 * one (e.g. `totalcms-3.5.0/…`), which would otherwise land the whole tree
	 * one level too deep.
	 */
	private function extract(string $zipPath, string $extractDir): string
	{
		if (is_dir($extractDir)) {
			$this->deleteDirectory($extractDir);
		}
		mkdir($extractDir, 0755, true);

		$zip = new \ZipArchive();
		if ($zip->open($zipPath) !== true) {
			throw new \RuntimeException('Failed to open update zip');
		}

		$zip->extractTo($extractDir);
		$zip->close();

		$scanned = scandir($extractDir);
		$entries = $scanned === false ? [] : array_values(array_filter(
			$scanned,
			static fn (string $e): bool => $e !== '.' && $e !== '..',
		));

		if (count($entries) === 1 && is_dir($extractDir . '/' . $entries[0])) {
			return $extractDir . '/' . $entries[0];
		}

		return $extractDir;
	}

	/**
	 * Replace each item shipped in $source into $destination, moving any existing
	 * copy into $backupDir first. PRESERVE paths are skipped entirely so user
	 * content and config are never moved or overwritten.
	 */
	private function swapItems(string $source, string $destination, string $backupDir): void
	{
		if (!is_dir($backupDir)) {
			mkdir($backupDir, 0755, true);
		}

		foreach (new \DirectoryIterator($source) as $item) {
			if ($item->isDot()) {
				continue;
			}

			$name = $item->getFilename();
			if (in_array($name, self::PRESERVE, true)) {
				$this->logger->info("Preserving user path, not replacing: {$name}");

				continue;
			}

			$destPath = $destination . '/' . $name;

			if (file_exists($destPath) && !rename($destPath, $backupDir . '/' . $name)) {
				throw new \RuntimeException("Failed to back up {$destPath}");
			}

			if (!rename($item->getPathname(), $destPath)) {
				throw new \RuntimeException("Failed to install {$name}");
			}
		}
	}

	/**
	 * Restore items previously moved into $backupDir back into $destination,
	 * removing the (new, possibly broken) copy first.
	 */
	private function rollbackItems(string $backupDir, string $destination): void
	{
		foreach (new \DirectoryIterator($backupDir) as $item) {
			if ($item->isDot()) {
				continue;
			}

			$name     = $item->getFilename();
			$destPath = $destination . '/' . $name;

			if (file_exists($destPath)) {
				if (is_dir($destPath)) {
					$this->deleteDirectory($destPath);
				} else {
					unlink($destPath);
				}
			}

			rename($item->getPathname(), $destPath);
		}
	}

	/**
	 * Invalidate the PHP runtime caches so the next request loads the new files.
	 * Built-ins only — safe to call after the code has been swapped.
	 */
	private function resetRuntimeCaches(): void
	{
		if (function_exists('opcache_reset')) {
			@opcache_reset();
		}
		clearstatcache(true);
	}

	private function findLatestBackup(): ?string
	{
		$parentDir = dirname($this->appRoot);
		$appName   = basename($this->appRoot);
		$pattern   = $parentDir . '/' . $appName . '.backup-*';
		$backups   = glob($pattern);

		if ($backups === false || $backups === []) {
			return null;
		}

		// Sort descending to get the most recent
		rsort($backups);

		return $backups[0];
	}

	private function deleteDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach ($iterator as $file) {
			if ($file->isDir()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}

		rmdir($dir);
	}
}
