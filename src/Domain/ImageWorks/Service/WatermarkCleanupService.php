<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LoggerFactory;

/**
 * Service for cleaning up watermark cache files.
 *
 * This is separate from TextWatermarkFactory to avoid circular dependencies,
 * since CacheManager needs cleanup capabilities but TextWatermarkFactory
 * requires EditionFeatureService which depends on CacheManager.
 */
readonly class WatermarkCleanupService
{
	private LoggerInterface $logger;

	public function __construct(
		private StorageAdapterInterface $filesystem,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('watermark-cleanup');
	}

	/**
	 * Clean up a specific watermark file.
	 */
	public function cleanup(string $watermarkPath): void
	{
		$fullPath = TextWatermarkFactory::WATERMARK_DIR . '/' . $watermarkPath;
		if ($this->filesystem->fileExists($fullPath)) {
			$this->filesystem->delete($fullPath);
		}
	}

	/**
	 * Clear cached watermarks older than specified time.
	 *
	 * @param int $maxAge Maximum age in seconds (default: 30 days). Use 0 to clear all regardless of age.
	 *
	 * @return int Number of files cleaned up
	 */
	public function clearOldCache(int $maxAge = 2592000): int
	{
		$cleaned    = 0;
		$clearAll   = $maxAge === 0;
		$cutoffTime = $clearAll ? 0 : time() - $maxAge;

		try {
			$files = $this->filesystem->listFiles(TextWatermarkFactory::WATERMARK_DIR);

			foreach ($files as $filePath) {
				$filename = basename($filePath);
				if (str_starts_with($filename, 'text_watermark_')) {
					// If clearing all, skip timestamp check
					if ($clearAll) {
						$this->filesystem->delete($filePath);
						$cleaned++;
						continue;
					}

					// Use flysystem directly to get file metadata
					$lastModified = $this->filesystem->flysystem()->lastModified($filePath);

					if ($lastModified && $lastModified < $cutoffTime) {
						$this->filesystem->delete($filePath);
						$cleaned++;
					}
				}
			}
		} catch (\Exception $e) {
			// Log error but don't fail
			$this->logger->warning('Error cleaning watermark cache', [
				'error'     => $e->getMessage(),
				'exception' => $e::class,
			]);
		}

		return $cleaned;
	}
}
