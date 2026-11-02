<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

use League\Glide\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Data\ImageLocation;
use TotalCMS\Domain\ImageWorks\Data\Watermark;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * An image with both an image and a text watermark takes two Glide passes:
 * the image mark, then the text mark over that result. The intermediate file
 * gets a unique temp name so two requests at once cannot delete each other's,
 * but the text pass's cache key is pinned to a stable name (see textPass()),
 * so the finished image is cached like any other and the second request is a
 * cache hit.
 *
 * The cache key used to come from the temp name, so every request rendered
 * again and left a `.cache/temp_*.png/` folder behind. A miss here sweeps
 * those leftovers from the image's cache folder.
 */
final readonly class DoubleWatermarkRenderer
{
	private LoggerInterface $logger;

	public function __construct(
		private StorageAdapterInterface $filesystem,
		private GlideFactory $glideFactory,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::ImageGenerator);
	}

	/**
	 * Whether the finished image (the text pass's output) is already cached.
	 *
	 * @param array<string,mixed> $params The params without watermarks
	 */
	public function isCached(Server $glide, ImageLocation $location, ImageData $imageData, Watermark $imageMark, Watermark $textMark, array $params): bool
	{
		$pass = $this->textPass($glide, $location, $imageData, $imageMark, $textMark, $params);

		return $pass['server']->cacheFileExists($pass['name'], $pass['params']);
	}

	/** @param array<string,mixed> $params The params without watermarks */
	public function render(Server $glide, ImageLocation $location, ImageData $imageData, Watermark $imageMark, Watermark $textMark, array $params): ResponseInterface
	{
		$pass = $this->textPass($glide, $location, $imageData, $imageMark, $textMark, $params);

		if ($pass['server']->cacheFileExists($pass['name'], $pass['params'])) {
			return $pass['server']->getImageResponse($pass['name'], $pass['params']);
		}

		$this->removeLegacyTempCaches($location);

		$firstPass    = $glide->getImageResponse($imageData->name, array_merge($params, $imageMark->toArray()));
		$tempFileName = 'temp_' . bin2hex(random_bytes(8)) . '.png';
		$tempPath     = $location->path($tempFileName);
		$this->filesystem->write($tempPath, (string)$firstPass->getBody());

		try {
			return $pass['server']->getImageResponse($tempFileName, $pass['params']);
		} finally {
			try {
				$this->filesystem->delete($tempPath);
			} catch (\Exception $e) {
				// Log but don't fail if cleanup fails
				$this->logger->warning('Failed to clean up temporary watermark file', [
					'path'      => $tempPath,
					'error'     => $e->getMessage(),
					'exception' => $e::class,
				]);
			}
		}
	}

	/**
	 * The Glide server for the text pass, with its cache path fixed to a name
	 * derived from the image pass's own cache key (which already covers the
	 * source and every image-pass param). Whatever temp file the pass reads,
	 * the same request lands on the same cache file.
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return array{server: Server, name: string, params: array<string,mixed>}
	 */
	private function textPass(Server $glide, ImageLocation $location, ImageData $imageData, Watermark $imageMark, Watermark $textMark, array $params): array
	{
		$name       = 'marks-' . hash('xxh3', $glide->getCachePath($imageData->name, array_merge($params, $imageMark->toArray()))) . '.png';
		$textParams = array_merge($params, $textMark->toArray());

		$server = $this->glideFactory->create(
			source        : $location->path(),
			imageData     : $imageData,
			watermarkPath : TextWatermarkFactory::WATERMARK_DIR,
		);

		$cachePath = $server->getCachePath($name, $textParams);
		$server->setCachePathCallable(fn (): string => $cachePath);

		return ['server' => $server, 'name' => $name, 'params' => $textParams];
	}

	/** Delete the `.cache/temp_*.png/` folders the old two-pass render leaked. */
	private function removeLegacyTempCaches(ImageLocation $location): void
	{
		$cacheDir = $location->path() . '/' . GlideFactory::CACHEDIR;

		try {
			if (!$this->filesystem->directoryExists($cacheDir)) {
				return;
			}

			foreach ($this->filesystem->listDirectories($cacheDir) as $dir) {
				if (preg_match('/^temp_[0-9a-f.]+\.png$/', basename($dir)) === 1) {
					$this->filesystem->deleteDirectory($dir);
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning('Failed to remove leftover watermark cache folders', [
				'path'  => $cacheDir,
				'error' => $e->getMessage(),
			]);
		}
	}
}
