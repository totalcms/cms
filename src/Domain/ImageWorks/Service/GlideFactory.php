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

readonly class GlideFactory
{
	public const CACHEDIR  = '.cache';
	public const PALETTE   = 'palette';
	public const IMG_TYPES = ['jpg', 'jpeg', 'pjpg', 'png', 'gif', 'webp', 'avif'];

	public function __construct(
		private StorageAdapterInterface $filesystem,
		private Config $config,
	) {
	}

	/**
	 * Get the original image.
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

	public function create(
		string $source,
		ImageData $imageData,
		?string $watermarkPath = null,
		?string $cacheDir      = null,
	): Server {
		// Create Glide server
		$glide = ServerFactory::create([
			'source'                 => $this->filesystem->flysystem(),
			'cache'                  => $this->filesystem->flysystem(),
			'watermarks'             => $this->filesystem->flysystem(),
			'source_path_prefix'     => $source,
			'cache_path_prefix'      => sprintf('%s/%s', $source, $cacheDir ?? self::CACHEDIR),
			'watermarks_path_prefix' => $watermarkPath ?? TextWatermarkFactory::WATERMARK_DIR,
			'driver'                 => extension_loaded('imagick') ? 'imagick' : 'gd',
			'defaults'               => $this->config->imageworks['defaults'],
			'presets'                => $this->presets($imageData),
			'response'               => new PsrResponseFactory(new Response(), fn ($stream): Stream => new Stream($stream)),
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

	public function filesystem(): StorageAdapterInterface
	{
		return $this->filesystem;
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
		$parts = explode(',', $border);

		$size   = intval($parts[0]);
		$border = $parts[1] ?? '';
		$method = $parts[2] ?? '';

		if ($border === '') {
			$border = 'ffffff';
		}

		if ($method === '') {
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
