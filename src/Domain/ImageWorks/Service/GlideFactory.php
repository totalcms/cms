<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use League\Glide\Responses\PsrResponseFactory;
use League\Glide\Server;
use League\Glide\ServerFactory;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;
use TotalCMS\Domain\ImageWorks\Data\Watermark;
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
		private TextWatermark $textWatermark,
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
		// Create watermark objects
		$imageWatermark = Watermark::fromImageParams($params);
		$textWatermark = null;

		// Generate text watermark if requested
		if (isset($params['marktext']) && !empty($params['marktext'])) {
			try {
				$textWatermark = Watermark::fromTextParams($params, $this->textWatermark);
			} catch (\Exception $e) {
				error_log('Text watermark generation failed: ' . $e->getMessage());
			}
		}

		// Determine which watermarks we have
		$hasImageWatermark = $imageWatermark !== null && !$imageWatermark->isEmpty();
		$hasTextWatermark = $textWatermark !== null && !$textWatermark->isEmpty();
		$needsSequentialProcessing = $hasImageWatermark && $hasTextWatermark;

		// Determine watermark path prefix
		$watermarkPathPrefix = '.watermarks'; // Default for text watermarks
		if ($hasImageWatermark) {
			$watermarkPathPrefix = $this->watermarkPath($watermark);
		}

		// Clean parameters by removing watermark-specific parameters
		$cleanedParams = $this->removeWatermarkParameters($params);

		// Prepare primary pass parameters
		$primaryPassParams = $cleanedParams;
		if ($needsSequentialProcessing) {
			// Image watermark goes first
			$primaryPassParams = array_merge($cleanedParams, $imageWatermark->toArray());
		} elseif ($hasImageWatermark) {
			$primaryPassParams = array_merge($cleanedParams, $imageWatermark->toArray());
		} elseif ($hasTextWatermark) {
			$primaryPassParams = array_merge($cleanedParams, $textWatermark->toArray());
			$watermarkPathPrefix = '.watermarks'; // Text watermarks always use .watermarks
		}

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
			'params' => $primaryPassParams,
		];

		// Add second pass information if needed
		if ($needsSequentialProcessing) {
			$result['needsSecondPass'] = true;
			$result['secondPassParams'] = $textWatermark->toArray();
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
	 * Remove watermark parameters from the params array.
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function removeWatermarkParameters(array $params): array
	{
		$cleanedParams = $params;
		$parametersToRemove = [
			'mark', 'markpos', 'markw', 'markh', 'markx', 'marky', 'markfit', 'markpad',
			'marktext', 'marktextpos', 'marktextw', 'marktexth', 'marktextx', 'marktexty', 
			'marktextfit', 'marktextpad', 'marktextsize', 'marktextcolor', 'marktextangle',
			'marktextfont', 'marktextstrokewidth', 'marktextstrokecolor'
		];

		foreach ($parametersToRemove as $param) {
			unset($cleanedParams[$param]);
		}

		return $cleanedParams;
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
