<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use League\Glide\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\ImageWorks\Data\Watermark;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\Config;

class ImageGenerator
{
	private string $collection;
	private string $id;
	private string $property;
	/** @var array<string,mixed> */
	private array $params;
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly StorageAdapterInterface $filesystem,
		private readonly PropertyFetcher $propertyFetcher,
		private readonly SchemaFetcher $schemaFetcher,
		private readonly GlideFactory $glideFactory,
		private readonly WatermarkFactory $watermarkFactory,
		private readonly Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('totalcms.log')
			->createLogger('imagegenerator');
	}

	/** @param array<string,mixed> $params */
	public function generateImage(
		string $collection,
		string $id,
		string $property,
		array $params,
		?ServerRequestInterface $request = null,
	): ResponseInterface {
		$imageData = $this->propertyFetcher->fetchProperty($collection, $id, $property);

		if (!$imageData instanceof ImageData) {
			throw new \UnexpectedValueException('Invalid image property found');
		}

		$this->collection = $collection;
		$this->id         = $id;
		$this->property   = $property;
		$this->params     = $this->cleanupParams($params, $imageData);

		return $this->responseFromImageData($imageData, $request);
	}

	/** @param array<string,mixed> $params */
	public function generateGalleryImage(
		string $collection,
		string $id,
		string $property,
		string $name,
		array $params,
		?ServerRequestInterface $request = null,
	): ResponseInterface {
		$galleryData = $this->propertyFetcher->fetchProperty($collection, $id, $property);

		if (!$galleryData instanceof GalleryData) {
			throw new \UnexpectedValueException('Invalid gallery property found');
		}

		if ($galleryData->images === []) {
			throw new \UnexpectedValueException('Gallery has no images');
		}

		$imageData = match ($name) {
			'first'    => array_shift($galleryData->images),
			'last'     => array_pop($galleryData->images),
			'random'   => $this->getRandomImage($galleryData->images),
			'featured' => $this->getFeaturedImage($galleryData->images),
			default    => $this->getImageByName($galleryData->images, $name),
		};

		if (empty($imageData)) {
			throw new \UnexpectedValueException('Gallery Image not found');
		}

		$this->collection = $collection;
		$this->id         = $id;
		$this->property   = $property;
		$this->params     = $this->cleanupParams($params, $imageData);

		return $this->responseFromImageData($imageData, $request);
	}

	/** @param array<string,mixed> $params */
	public function generateUploadImage(
		string $collection,
		string $id,
		string $property,
		string $name,
		array $params,
		?ServerRequestInterface $request = null,
	): ResponseInterface {
		// Create dummy ImageData object
		$imageData         = new ImageData();
		$imageData->name   = $name;
		// $imageData->width  = 0;
		// $imageData->height = 0;

		$this->collection = $collection;
		$this->id         = $id;
		$this->property   = $property;
		$this->params     = $this->cleanupParams($params, $imageData);

		return $this->responseFromImageData($imageData, $request);
	}

	/** @param array<ImageData> $images */
	private function getImageByName(array $images, string $name): ?ImageData
	{
		$imageData = array_filter($images, fn (ImageData $image): bool => pathinfo($image->name)['filename'] === $name);

		if ($imageData === []) {
			return null;
		}

		return array_shift($imageData);
	}

	/** @param array<ImageData> $images */
	private function getRandomImage(array $images): ImageData
	{
		$count = count($images) - 1;

		if ($count === 0) {
			return $images[0];
		}

		$randomKey = mt_rand(0, $count);

		return $images[$randomKey];
	}

	/** @param array<ImageData> $images */
	private function getFeaturedImage(array $images): ImageData
	{
		$featured = array_filter($images, fn (ImageData $image): bool => $image->featured);
		$count    = count($featured);
		if ($count === 0) {
			// if no featured images are found, return a random image
			return $this->getRandomImage($images);
		}
		if ($count > 1) {
			shuffle($featured);
		}

		return array_shift($featured);
	}

	/**
	 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
	 * @SuppressWarnings("PHPMD.NPathComplexity")
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return array<string,int|string>
	 */
	private function cleanupParams(array $params, ImageData $imageData): array
	{
		// Remove metadata params that don't affect image processing
		unset($params['id'], $params['collection'], $params['property'], $params['name'], $params['cache']);

		// If no params are provided, return the original image
		// The Action class automatically adds the format to the params so we need to check for that
		// If the only param is 'fm' and it matches the original image's format, return original
		if ($params === [] || (count($params) === 1 && isset($params['fm']) && str_ends_with($imageData->name, (string)$params['fm']))) {
			return [];
		}

		// Resolve preset first so dimension constraints apply to preset values too
		if (isset($params['p'])) {
			$params = $this->resolvePreset($params);
		}

		// Make sure that the requested width and height are not larger than the original image
		if (isset($params['w']) && $params['w'] > $imageData->width && $imageData->width > 0) {
			$params['w'] = $imageData->width;
		}

		if (isset($params['w']) && $params['w'] == 0) {
			unset($params['w']);
		}

		if (isset($params['h']) && $params['h'] > $imageData->height && $imageData->height > 0) {
			$params['h'] = $imageData->height;
		}

		if (isset($params['h']) && $params['h'] == 0) {
			unset($params['h']);
		}

		if (isset($params['fit'])) {
			$params['fit'] = GlideFactory::cropFocalpoint($params['fit'], $imageData->focalpoint);
		}

		if (isset($params['bg'])) {
			$params['bg'] = GlideFactory::updateBackgroundColor($params['bg'], $imageData->palette);
		}

		if (isset($params['border'])) {
			$params['border'] = GlideFactory::updateBorderColor($params['border'], $imageData->palette);
		}

		if (isset($params['mark']) && !isset($params['markw'])) {
			$params['markw'] = '100w';
		}

		// Don't auto-set marktextw for text watermarks - allow fixed-size text via marktextsize
		// Users can explicitly set marktextw if they want scaling behavior
		// if (isset($params['marktext']) && !isset($params['marktextw'])) {
		// 	$params['marktextw'] = '100w';
		// }

		// Merge schema watermark settings (schema overrides URL parameters for maximum security)
		$schemaWatermarks = $this->getSchemaWatermarkSettings();
		// Check if watermark should be applied based on limit setting
		if ($schemaWatermarks !== [] && $this->shouldApplyWatermark($params, $schemaWatermarks)) {
			// Remove limit setting before merging (not a valid imageworks parameter)
			unset($schemaWatermarks['limit']);
			$params = array_merge($params, $schemaWatermarks);
		}

		// Only filter out null and empty string values, preserve 0 and other falsy values that are valid for Glide
		return array_filter($params, fn ($value): bool => $value !== null && $value !== '');
	}

	/**
	 * Resolve preset values into params.
	 * Preset values are merged first, then explicit params override them.
	 * This ensures dimension constraints are applied to preset w/h values.
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return array<string,mixed>
	 */
	private function resolvePreset(array $params): array
	{
		$presetName = (string)$params['p'];
		$presets    = $this->config->imageworks['presets'] ?? [];

		if (!isset($presets[$presetName])) {
			return $params;
		}

		$presetValues = $presets[$presetName];

		// Remove preset key since we're resolving it manually
		unset($params['p']);

		// Merge preset values first, then overlay explicit params
		// This means explicit params take precedence over preset values
		return array_merge($presetValues, $params);
	}

	/**
	 * Get watermark settings from property schema.
	 * Schema watermarks are enforced and cannot be overridden via URL parameters.
	 *
	 * @return array<string,string|int>
	 */
	private function getSchemaWatermarkSettings(): array
	{
		try {
			$schema         = $this->schemaFetcher->fetchSchemaForCollection($this->collection);
			$propertySchema = $schema->properties[$this->property] ?? [];
			$settings       = $propertySchema['settings'] ?? [];

			return $settings['watermark'] ?? [];
		} catch (\Exception) {
			return [];
		}
	}

	/**
	 * Determine if watermark should be applied based on limit setting.
	 * Watermark is applied if:
	 * - No limit is set (always watermark), OR
	 * - Requested width exceeds limit, OR
	 * - Requested height exceeds limit, OR
	 * - No dimensions requested (original image).
	 *
	 * @param array<string,string|int> $params Request parameters
	 * @param array<string,string|int> $watermarkSettings Schema watermark settings
	 */
	private function shouldApplyWatermark(array $params, array $watermarkSettings): bool
	{
		// If no limit is set, always apply watermark
		if (!isset($watermarkSettings['limit'])) {
			return true;
		}

		$limit = (int)$watermarkSettings['limit'];

		// If no dimensions requested (original image), apply watermark
		if (!isset($params['w']) && !isset($params['h'])) {
			return true;
		}

		// Check if requested width exceeds limit
		if (isset($params['w']) && (int)$params['w'] > $limit) {
			return true;
		}

		// Check if requested height exceeds limit
		// Dimensions are below limit, don't apply watermark
		return isset($params['h']) && (int)$params['h'] > $limit;
	}

	private function returnOriginalImage(ImageData $imageData, ?ServerRequestInterface $request = null): ResponseInterface
	{
		// If no params are provided, return the original image
		// The Action class automatically adds the format to the params so we need to check for that
		$imagePath = PathUtils::buildPath($this->collection, $this->id, $this->property, $imageData->name);
		$response  = $this->glideFactory->originalImage($imagePath);

		// Generate cache headers
		$cacheHeaders = $this->generateCacheHeaders($imagePath, $request);

		// Check if we should return 304 Not Modified
		if ($cacheHeaders['not_modified']) {
			return (new Response())
				->withStatus(304)
				->withHeader('Cache-Control', $cacheHeaders['cache_control'])
				->withHeader('ETag', $cacheHeaders['etag']);
		}

		// Get content length from ImageData or filesystem
		$contentLength = $imageData->size > 0 ? (string)$imageData->size : null;

		$httpResponse = (new Response())
			->withHeader('Content-Type', $response['mimeType'] ?: 'image/jpeg')
			->withHeader('Cache-Control', $cacheHeaders['cache_control'])
			->withHeader('ETag', $cacheHeaders['etag'])
			->withHeader('Last-Modified', $cacheHeaders['last_modified'])
			->withBody($response['stream']);

		if ($contentLength !== null) {
			$httpResponse = $httpResponse->withHeader('Content-Length', $contentLength);
		}

		return $httpResponse;
	}

	/**
	 * Generate cache headers and check for conditional requests.
	 *
	 * @param array<string,mixed> $params Optional params for processed images
	 *
	 * @return array<string,mixed>
	 */
	private function generateCacheHeaders(string $imagePath, ?ServerRequestInterface $request = null, array $params = []): array
	{
		// Try to get file modification time for ETag and Last-Modified
		try {
			$lastModified = $this->filesystem->flysystem()->lastModified($imagePath);
		} catch (\Exception) {
			$lastModified = time();
		}

		// Generate ETag from path, modification time, and params (for processed images)
		$etagBase     = $imagePath . $lastModified . serialize($params);
		$etag         = '"' . md5($etagBase) . '"';
		$lastModDate  = gmdate('D, d M Y H:i:s', $lastModified) . ' GMT';
		$cacheControl = 'public, max-age=31536000, immutable';

		$notModified = false;

		// Check conditional headers if request is provided
		if ($request instanceof ServerRequestInterface) {
			// Check If-None-Match (ETag)
			$ifNoneMatch = $request->getHeaderLine('If-None-Match');
			if ($ifNoneMatch === $etag) {
				$notModified = true;
			}

			// Check If-Modified-Since (Last-Modified)
			$ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
			if ($ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $lastModified) {
				$notModified = true;
			}
		}

		return [
			'etag'          => $etag,
			'last_modified' => $lastModDate,
			'cache_control' => $cacheControl,
			'not_modified'  => $notModified,
		];
	}

	/**
	 * Add cache headers to a PSR-7 response.
	 *
	 * @param array<string,mixed> $cacheHeaders
	 */
	private function addCacheHeaders(ResponseInterface $response, array $cacheHeaders): ResponseInterface
	{
		return $response
			->withHeader('Cache-Control', $cacheHeaders['cache_control'])
			->withHeader('ETag', $cacheHeaders['etag'])
			->withHeader('Last-Modified', $cacheHeaders['last_modified']);
	}

	/**
	 * @param array<string,mixed> $params
	 *
	 * @return array<string,mixed>
	 */
	private function filterWatermarkParams(array $params = []): array
	{
		$params = $params === [] ? $this->params : $params;

		return array_filter(
			$params,
			fn ($param): bool => !str_starts_with((string)$param, 'mark'),
			ARRAY_FILTER_USE_KEY
		);
	}

	/** @param array<string,mixed> $params */
	private function handleBothWatermarks(
		Server $glide,
		ImageData $imageData,
		Watermark $imageMark,
		Watermark $textMark,
		array $params,
	): ResponseInterface {
		// Both image and text watermarks provided, return image with params
		$imageParams = array_merge($params, $imageMark->toArray());
		$textParams  = array_merge($params, $textMark->toArray());

		$firstPassResponse = $glide->getImageResponse($imageData->name, $imageParams);

		// Get the processed image data from first pass
		// Create a temporary file for the intermediate result
		$firstPassImageData = (string)$firstPassResponse->getBody();
		$tempFileName       = 'temp_' . uniqid() . '.png';
		$tempPath           = PathUtils::buildPath($this->collection, $this->id, $this->property, $tempFileName);
		$this->filesystem->write($tempPath, $firstPassImageData);

		// Create second pass server specifically for text watermark
		$secondServer = $this->glideFactory->create(
			source        : PathUtils::buildPath($this->collection, $this->id, $this->property),
			imageData     : $imageData,
			watermarkPath : TextWatermarkFactory::WATERMARK_DIR,
		);

		// Apply text watermark to the intermediate result
		$finalResponse = $secondServer->getImageResponse($tempFileName, $textParams);

		// Clean up temporary file
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

		return $finalResponse;
	}

	private function responseFromImageData(ImageData $imageData, ?ServerRequestInterface $request = null): ResponseInterface
	{
		if ($this->params === []) {
			return $this->returnOriginalImage($imageData, $request);
		}

		// Generate cache headers for processed images
		$imagePath    = PathUtils::buildPath($this->collection, $this->id, $this->property, $imageData->name);
		$cacheHeaders = $this->generateCacheHeaders($imagePath, $request, $this->params);

		// Check if we should return 304 Not Modified
		if ($cacheHeaders['not_modified']) {
			return (new Response())
				->withStatus(304)
				->withHeader('Cache-Control', $cacheHeaders['cache_control'])
				->withHeader('ETag', $cacheHeaders['etag']);
		}

		$imageMark = $this->watermarkFactory->createImageWatermark($this->params);
		// Use requested width if specified, otherwise use original image width
		$effectiveWidth = isset($this->params['w']) ? (int)$this->params['w'] : $imageData->width;
		$textMark       = $this->watermarkFactory->createTextWatermark($this->params, $effectiveWidth);

		$hasImageMark = $imageMark instanceof Watermark && !$imageMark->isEmpty();
		$hasTextMark  = $textMark  instanceof Watermark && !$textMark->isEmpty();

		$watermarkPath = $hasImageMark ? $imageMark->path : TextWatermarkFactory::WATERMARK_DIR;

		$glide = $this->glideFactory->create(
			source        : PathUtils::buildPath($this->collection, $this->id, $this->property),
			imageData     : $imageData,
			watermarkPath : $watermarkPath,
		);

		$params = $this->filterWatermarkParams();

		if ($hasImageMark && $hasTextMark) {
			$response = $this->handleBothWatermarks($glide, $imageData, $imageMark, $textMark, $params);

			return $this->addCacheHeaders($response, $cacheHeaders);
		}

		if ($hasTextMark) {
			// Only text watermark provided, return image with params
			$params   = array_merge($params, $textMark->toArray());
			$response = $glide->getImageResponse($imageData->name, $params);

			return $this->addCacheHeaders($response, $cacheHeaders);
		}

		if ($hasImageMark) {
			// Only image watermark provided, return image with params
			$params   = array_merge($params, $imageMark->toArray());
			$response = $glide->getImageResponse($imageData->name, $params);

			return $this->addCacheHeaders($response, $cacheHeaders);
		}

		// No watermarks provided, return image with params
		$response = $glide->getImageResponse($imageData->name, $params);

		return $this->addCacheHeaders($response, $cacheHeaders);
	}
}
