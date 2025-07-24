<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use League\Glide\Responses\PsrResponseFactory;
use League\Glide\Server;
use League\Glide\ServerFactory;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;
use TotalCMS\Domain\ImageWorks\Data\WatermarkProcessor;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

final class GlideFactory
{
	public const CACHEDIR  = '.cache';
	public const PALETTE   = 'palette';
	public const IMG_TYPES = ['jpg', 'jpeg', 'pjpg', 'png', 'gif', 'webp', 'avif'];

	public function __construct(
		private StorageAdapterInterface $filesystem,
		private Config $config,
		private WatermarkProcessor $watermarkProcessor,
	) {
	}

	/**
	 * Get the original image.
	 *
	 * @param string $imagePath
	 *
	 * @return array<string,mixed>
	 */
	public function originalImage(string $imagePath): array
	{
		$imageFile = $this->filesystem->readStream($imagePath);
		$mimeType  = $this->filesystem->mimeType($imagePath);

		return [
			'stream'   => new Stream($imageFile),
			'mimeType' => $mimeType ?: 'image/jpeg',
		];
	}

	/**
	 * Create a glide server and process parameters.
	 *
	 * Supports sequential watermark processing for both image and text watermarks.
	 *
	 * @param string $source
	 * @param ImageData $imageData
	 * @param ?string $cache
	 * @param ?string $watermark
	 * @param array<string,mixed> $params
	 *
	 * @return array{server: Server, params: array<string,mixed>, needsSecondPass?: bool, secondPassParams?: array<string,mixed>}
	 */
	public function create(string $source, ImageData $imageData, ?string $cache = null, ?string $watermark = null, array $params = []): array
	{
		// Process watermarks using the dedicated processor
		$watermarkResult = $this->watermarkProcessor->processWatermarks($params);

		// Debug logging
		if ($watermarkResult->needsSequentialProcessing()) {
			$primaryParams = $watermarkResult->getPrimaryPassParams();
			$secondaryParams = $watermarkResult->getSecondaryPassParams();
			error_log('Sequential watermark processing: First pass mark = ' . ($primaryParams['mark'] ?? 'none'));
			error_log('Sequential watermark processing: Second pass mark = ' . ($secondaryParams['mark'] ?? 'none'));
		} elseif ($watermarkResult->hasWatermarks()) {
			$primaryParams = $watermarkResult->getPrimaryPassParams();
			error_log('Single watermark: mark = ' . ($primaryParams['mark'] ?? 'none'));
		}

		// Determine watermark path prefix
		$watermarkPathPrefix = $watermarkResult->getWatermarkPathPrefix($this->watermarkPath($watermark));

		// Create Glide server
		$glide = ServerFactory::create([
			'source'                 => $this->filesystem->flysystem(),
			'cache'                  => $this->filesystem->flysystem(),
			'watermarks'             => $this->filesystem->flysystem(),
			'source_path_prefix'     => $source,
			'cache_path_prefix'      => sprintf('%s/%s', $source, $cache ?? self::CACHEDIR),
			'watermarks_path_prefix' => $watermarkPathPrefix,
			'driver'                 => extension_loaded('imagick') ? 'imagick' : 'gd',
			'defaults'               => $this->config->imageworks['defaults'],
			'presets'                => $this->presets($imageData),
			'response'               => new PsrResponseFactory(new Response(), fn ($stream) => new Stream($stream)),
		]);

		$result = [
			'server' => $glide,
			'params' => $watermarkResult->getPrimaryPassParams(),
		];

		// Add second pass information if needed
		if ($watermarkResult->needsSequentialProcessing()) {
			$result['needsSecondPass'] = true;
			$result['secondPassParams'] = $watermarkResult->getSecondaryPassParams();
		}

		return $result;
	}

	/** @return array<string,array<string,mixed>> */
	public function presets(ImageData $imageData): array
	{
		$presets = $this->config->imageworks['presets'];

		foreach ($presets as $key => $preset) {
			if (array_key_exists('fit', $preset)) {
				$presets[$key]['fit'] = self::cropFocalpoint($preset['fit'], $imageData->focalpoint);
			}
		}

		return $presets;
	}

	public function watermarkPath(?string $watermark): string
	{
		$objectID = $watermark ?? $this->config->imageworks['watermarksGallery'];

		return sprintf('gallery/%s/gallery', $objectID);
	}

	/**
	 * Get the filesystem adapter.
	 *
	 * @return StorageAdapterInterface
	 */
	public function filesystem(): StorageAdapterInterface
	{
		return $this->filesystem;
	}

	/**
	 * Create a server specifically for text watermarks (second pass).
	 *
	 * @param string $source
	 * @param ImageData $imageData
	 * @param ?string $cache
	 *
	 * @return Server
	 */
	public function createTextWatermarkServer(string $source, ImageData $imageData, ?string $cache = null): Server
	{
		return ServerFactory::create([
			'source'                 => $this->filesystem->flysystem(),
			'cache'                  => $this->filesystem->flysystem(),
			'watermarks'             => $this->filesystem->flysystem(),
			'source_path_prefix'     => $source,
			'cache_path_prefix'      => sprintf('%s/%s', $source, $cache ?? self::CACHEDIR),
			'watermarks_path_prefix' => '.watermarks', // Always use .watermarks for text watermarks
			'driver'                 => extension_loaded('imagick') ? 'imagick' : 'gd',
			'defaults'               => $this->config->imageworks['defaults'],
			'presets'                => $this->presets($imageData),
			'response'               => new PsrResponseFactory(new Response(), fn ($stream) => new Stream($stream)),
		]);
	}

	/** @param array<string,int> $focalpoint */
	public static function cropFocalpoint(string $crop, array $focalpoint): string
	{
		$newcrop = sprintf('crop-%g-%g', $focalpoint['x'], $focalpoint['y']);

		return str_replace('crop-focalpoint', $newcrop, $crop);
	}

	/** @param array<string> $imageColors */
	public static function updateBackgroundColor(string $background, array $imageColors): string
	{
		return self::colorFromPalette($background, $imageColors);
	}

	/** @param array<string> $imageColors */
	public static function updateBorderColor(string $border, array $imageColors): string
	{
		[$size, $border, $method] = explode(',', $border);

		$size = intval($size);

		if (empty($border)) {
			$border = 'ffffff';
		}

		if (empty($method)) {
			$method = 'overlay';
		}

		$border = self::colorFromPalette($border, $imageColors);

		return implode(',', [$size, $border, $method]);
	}

	/** @param array<string> $imageColors */
	private static function colorFromPalette(string $color, array $imageColors): string
	{
		// This method is used to get the color from the palette if the color is a palette color
		if (str_starts_with($color, self::PALETTE)) {
			$index = intval(str_replace(self::PALETTE, '', $color));
			$color = $imageColors[$index] ?? $imageColors[0];
		}

		return str_replace('#', '', $color);
	}

}
