<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Update\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Applies downloaded updates by swapping the application directory.
 *
 * Process: extract zip → backup current → swap → clear cache → log
 */
class UpdateApplier
{
	private readonly LoggerInterface $logger;
	private readonly string $appRoot;

	public function __construct(
		private readonly Config $config,
		private readonly MaintenanceMode $maintenanceMode,
		private readonly CacheManager $cacheManager,
		LoggerFactory $loggerFactory,
	) {
		$this->logger  = $loggerFactory->addFileHandler('updates.log')->createLogger('update');
		$this->appRoot = dirname(__DIR__, 4); // src/Domain/Update/Service → app root
	}

	/**
	 * Apply an update from a downloaded zip file.
	 */
	public function apply(string $zipPath, string $version): void
	{
		$this->logger->info("Starting update to {$version}");

		$extractDir = sys_get_temp_dir() . '/totalcms-update-extract';
		$backupDir  = $this->appRoot . ".backup-{$version}-" . date('Ymd-His');

		try {
			// Extract zip
			$this->extract($zipPath, $extractDir);

			// Enable maintenance mode
			$this->maintenanceMode->enable();
			$this->logger->info('Maintenance mode enabled');

			// Backup current app
			$this->backup($this->appRoot, $backupDir);
			$this->logger->info("Current app backed up to {$backupDir}");

			// Swap files from extracted dir into app root
			$this->swapFiles($extractDir, $this->appRoot);
			$this->logger->info('Files swapped');

			// Clear all caches
			$this->cacheManager->clearAllCaches();
			$this->logger->info('Caches cleared');

			// Disable maintenance mode
			$this->maintenanceMode->disable();
			$this->logger->info('Maintenance mode disabled');

			// Clean up backup after successful update
			$this->deleteDirectory($backupDir);
			$this->logger->info('Backup cleaned up');

			$this->logger->info("Update to {$version} complete");
		} catch (\Throwable $e) {
			$this->maintenanceMode->disable();
			$this->logger->error("Update failed: {$e->getMessage()}");

			// Attempt rollback if backup exists
			if (is_dir($backupDir)) {
				try {
					$this->swapFiles($backupDir, $this->appRoot);
					$this->logger->info('Rolled back to previous version after failure');
				} catch (\Throwable $rollbackError) {
					$this->logger->critical("Rollback also failed: {$rollbackError->getMessage()}");
				}
			}

			throw new \RuntimeException("Update to {$version} failed: {$e->getMessage()}", 0, $e);
		} finally {
			// Clean up extract directory
			$this->deleteDirectory($extractDir);
			// Clean up downloaded zip
			if (file_exists($zipPath)) {
				unlink($zipPath);
			}
		}
	}

	/**
	 * Roll back to the most recent backup.
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
			$this->swapFiles($backupDir, $this->appRoot);
			$this->cacheManager->clearAllCaches();
			$this->maintenanceMode->disable();
			$this->logger->info('Rollback complete');

			// Remove the backup after successful rollback
			$this->deleteDirectory($backupDir);
		} catch (\Throwable $e) {
			$this->maintenanceMode->disable();
			throw new \RuntimeException("Rollback failed: {$e->getMessage()}", 0, $e);
		}
	}

	private function extract(string $zipPath, string $extractDir): void
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
	}

	private function backup(string $source, string $destination): void
	{
		if (!rename($source, $destination)) {
			throw new \RuntimeException("Failed to backup {$source} to {$destination}");
		}

		// Recreate the app root (rename moved it)
		mkdir($source, 0755, true);
	}

	/**
	 * Move files from source directory into destination.
	 */
	private function swapFiles(string $source, string $destination): void
	{
		$iterator = new \DirectoryIterator($source);
		foreach ($iterator as $item) {
			if ($item->isDot()) {
				continue;
			}

			$destPath = $destination . '/' . $item->getFilename();

			// Remove existing file/dir at destination
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
			\RecursiveIteratorIterator::CHILD_FIRST
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
