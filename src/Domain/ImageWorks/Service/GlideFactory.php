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
	private TextWatermark $textWatermark;

	public const CACHEDIR  = '.cache';
	public const PALETTE   = 'palette';
	public const IMG_TYPES = ['jpg', 'jpeg', 'pjpg', 'png', 'gif', 'webp', 'avif'];

	public function __construct(StorageAdapterInterface $filesystem, Config $config, TextWatermark $textWatermark)
	{
		$this->filesystem    = $filesystem;
		$this->config        = $config;
		$this->textWatermark = $textWatermark;
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
		// Check if text watermark is requested before processing
		$hasTextWatermark = isset($params['marktext']) && !empty($params['marktext']);

		// Handle text watermark if specified
		$this->processTextWatermark($params);

		// Check if we need sequential processing (both image and text watermarks)
		$needsSecondPass = isset($params['_textmark']) && isset($params['mark']);
		$secondPassParams = [];

		if ($needsSecondPass) {
			// Prepare second pass parameters for text watermark
			$secondPassParams = $params['_textmarkparams'];
			$secondPassParams['mark'] = $params['_textmark'];
			
			// Debug logging
			error_log('Sequential watermark processing: First pass mark = ' . $params['mark']);
			error_log('Sequential watermark processing: Second pass mark = ' . $secondPassParams['mark']);
			
			// Clean up internal parameters from first pass
			unset($params['_textmark'], $params['_textmarkparams']);
		}

		// Determine watermark path prefix for first pass
		$watermarkPathPrefix = '.watermarks'; // Default fallback
		
		if ($needsSecondPass) {
			// First pass is image watermark - use the correct gallery path
			$watermarkPathPrefix = $this->watermarkPath($watermark);
		} else if ($hasTextWatermark) {
			// Only text watermark - use .watermarks
			$watermarkPathPrefix = '.watermarks';
		} else if ($watermark) {
			// Only image watermark - use gallery path
			$watermarkPathPrefix = $this->watermarkPath($watermark);
		}

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
			'params' => $params,
		];

		// Add second pass information if needed
		if ($needsSecondPass) {
			$result['needsSecondPass'] = true;
			$result['secondPassParams'] = $secondPassParams;
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

	/**
	 * Process text watermark parameters and generate text watermark if needed.
	 * 
	 * This method handles text watermark generation and prepares parameters for
	 * potential sequential watermark processing (image + text).
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return void
	 */
	private function processTextWatermark(array &$params): void
	{
		// Check if text watermark is requested
		if (!isset($params['marktext']) || empty($params['marktext'])) {
			return;
		}

		try {
			// Generate text watermark image
			$textWatermarkPath = $this->textWatermark->generateTextWatermark($params);

			// Store text watermark parameters for later use if we have both image and text watermarks
			if (isset($params['mark'])) {
				// We have both image and text watermarks - store text watermark info
				$params['_textmark'] = $textWatermarkPath;
				$params['_textmarkparams'] = $this->extractTextWatermarkParams($params);
				
				// Keep the original image watermark as the primary mark for first pass
				// Text watermark will be applied in a second pass
			} else {
				// Only text watermark - use it as the primary mark
				$params['mark'] = $textWatermarkPath;
				
				// Map text-specific positioning parameters to standard watermark parameters
				$this->mapTextPositioningParams($params);
			}

			// Remove text-specific parameters as they're processed
			$textParams = [
				'marktext', 'marktextsize', 'marktextcolor', 'marktextfont', 'marktextbg', 
				'marktextpad', 'marktextangle', 'marktextalpha',
				// New positioning parameters
				'marktextpos', 'marktextw', 'marktexth', 'marktextx', 'marktexty', 'marktextfit'
			];
			foreach ($textParams as $param) {
				unset($params[$param]);
			}
		} catch (\Exception $e) {
			// Log error but don't fail the entire image generation
			error_log('Text watermark generation failed: ' . $e->getMessage());
		}
	}

	/**
	 * Extract text watermark positioning parameters.
	 *
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function extractTextWatermarkParams(array $params): array
	{
		$textParams = [];
		
		// Text-specific positioning parameters
		if (isset($params['marktextpos'])) {
			$textParams['markpos'] = $params['marktextpos'];
		}
		if (isset($params['marktextw'])) {
			$textParams['markw'] = $params['marktextw'];
		}
		if (isset($params['marktexth'])) {
			$textParams['markh'] = $params['marktexth'];
		}
		if (isset($params['marktextx'])) {
			$textParams['markx'] = $params['marktextx'];
		}
		if (isset($params['marktexty'])) {
			$textParams['marky'] = $params['marktexty'];
		}
		if (isset($params['marktextpad'])) {
			$textParams['markpad'] = $params['marktextpad'];
		}
		if (isset($params['marktextfit'])) {
			$textParams['markfit'] = $params['marktextfit'];
		}

		// Default positioning if not specified
		if (!isset($textParams['markpos'])) {
			$textParams['markpos'] = 'bottom-left'; // Different default from image watermarks
		}
		if (!isset($textParams['markw'])) {
			$textParams['markw'] = '100w';
		}

		return $textParams;
	}

	/**
	 * Map text positioning parameters to standard watermark parameters.
	 *
	 * @param array<string,mixed> $params
	 * @return void
	 */
	private function mapTextPositioningParams(array &$params): void
	{
		// Map text-specific positioning to standard positioning
		if (isset($params['marktextpos'])) {
			$params['markpos'] = $params['marktextpos'];
			unset($params['marktextpos']);
		} else {
			$params['markpos'] = 'bottom-left'; // Different default for text
		}

		if (isset($params['marktextw'])) {
			$params['markw'] = $params['marktextw'];
			unset($params['marktextw']);
		} else if (!isset($params['markw'])) {
			$params['markw'] = '100w';
		}

		if (isset($params['marktexth'])) {
			$params['markh'] = $params['marktexth'];
			unset($params['marktexth']);
		}

		if (isset($params['marktextx'])) {
			$params['markx'] = $params['marktextx'];
			unset($params['marktextx']);
		}

		if (isset($params['marktexty'])) {
			$params['marky'] = $params['marktexty'];
			unset($params['marktexty']);
		}

		if (isset($params['marktextfit'])) {
			$params['markfit'] = $params['marktextfit'];
			unset($params['marktextfit']);
		}

		// Remove marktextpad since it's handled during text generation, not positioning
		if (isset($params['marktextpad'])) {
			unset($params['marktextpad']);
		}
	}
}
