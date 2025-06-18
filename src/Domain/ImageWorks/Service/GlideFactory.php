<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use League\Glide\Responses\PsrResponseFactory;
use League\Glide\Server;
use League\Glide\ServerFactory;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

final class GlideFactory
{
	private StorageAdapterInterface $filesystem;
	private Config $config;

	public const CACHEDIR  = '.cache';
	public const PALETTE   = 'palette';
	public const IMG_TYPES = ['jpg', 'jpeg', 'pjpg', 'png', 'gif', 'webp', 'avif'];

	public function __construct(StorageAdapterInterface $filesystem, Config $config)
	{
		$this->filesystem = $filesystem;
		$this->config     = $config;
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
	 * Create a glide server.
	 *
	 * @param string $source
	 * @param ?string $cache
	 * @param ?string $watermark
	 * @param ImageData $imageData
	 *
	 * @return Server
	 */
	public function create(string $source, ImageData $imageData, ?string $cache = null, ?string $watermark = null): Server
	{
		$glide = ServerFactory::create([
			'source'                 => $this->filesystem->flysystem(),
			'cache'                  => $this->filesystem->flysystem(),
			'watermarks'             => $this->filesystem->flysystem(),
			'source_path_prefix'     => $source,
			'cache_path_prefix'      => sprintf('%s/%s', $source, $cache ?? self::CACHEDIR),
			'watermarks_path_prefix' => $this->watermarkPath($watermark),
			'driver'                 => extension_loaded('imagick') ? 'imagick' : 'gd',
			'defaults'               => $this->config->imageworks['defaults'],
			'presets'                => $this->presets($imageData),
			'response'               => new PsrResponseFactory(new Response(), fn ($stream) => new Stream($stream)),
		]);

		return $glide;
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
