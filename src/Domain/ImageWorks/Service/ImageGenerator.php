<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use League\Glide\Server;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\ImageWorks\Data\Watermark;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Infrastructure\Filesystem\PathUtils;

class ImageGenerator
{
	private string $collection;
	private string $id;
	private string $property;
	/** @var array<string,mixed> */
	private array $params;

	public function __construct(
		private readonly StorageAdapterInterface $filesystem,
		private readonly PropertyFetcher $propertyFetcher,
		private readonly GlideFactory $glideFactory,
		private readonly WatermarkFactory $watermarkFactory,
	) {
	}

	/** @param array<string,mixed> $params */
	public function generateImage(
		string $collection,
		string $id,
		string $property,
		array $params,
	): ResponseInterface {
		$imageData = $this->propertyFetcher->fetchProperty($collection, $id, $property);

		if (!$imageData instanceof ImageData) {
			throw new \UnexpectedValueException('Invalid image property found');
		}

		$this->collection = $collection;
		$this->id         = $id;
		$this->property   = $property;
		$this->params     = $this->cleanupParams($params, $imageData);

		return $this->responseFromImageData($imageData);
	}

	/** @param array<string,mixed> $params */
	public function generateGalleryImage(
		string $collection,
		string $id,
		string $property,
		string $name,
		array $params,
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

		return $this->responseFromImageData($imageData);
	}

	/** @param array<string,mixed> $params */
	public function generateUploadImage(
		string $collection,
		string $id,
		string $property,
		string $name,
		array $params,
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

		return $this->responseFromImageData($imageData);
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
		// If no params are provided, return the original image
		// The Action class automatically adds the format to the params so we need to check for that
		if ($params === [] || (count($params) === 1 && isset($params['fm']) && str_ends_with((string)$params['fm'], $imageData->name))) {
			return [];
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

		if (isset($params['cache'])) {
			unset($params['cache']);
		}

		if (isset($params['mark']) && !isset($params['markw'])) {
			$params['markw'] = '100w';
		}

		if (isset($params['marktext']) && !isset($params['marktextw'])) {
			$params['marktextw'] = '100w';
		}

		return array_filter($params);
	}

	private function returnOriginalImage(ImageData $imageData): ResponseInterface
	{
		// If no params are provided, return the original image
		// The Action class automatically adds the format to the params so we need to check for that
		$imagePath = PathUtils::buildPath($this->collection, $this->id, $this->property, $imageData->name);
		$response  = $this->glideFactory->originalImage($imagePath);

		return (new Response())
			->withHeader('Content-Type', $response['mimeType'] ?: 'image/jpeg')
			->withBody($response['stream']);
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
			fn ($param): bool => !str_starts_with($param, 'mark'),
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
			error_log('Failed to clean up temporary watermark file: ' . $e->getMessage());
		}

		return $finalResponse;
	}

	private function responseFromImageData(ImageData $imageData): ResponseInterface
	{
		if ($this->params === []) {
			return $this->returnOriginalImage($imageData);
		}

		$imageMark = $this->watermarkFactory->createImageWatermark($this->params);
		$textMark  = $this->watermarkFactory->createTextWatermark($this->params);

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
			return $this->handleBothWatermarks($glide, $imageData, $imageMark, $textMark, $params);
		}

		if ($hasTextMark) {
			// Only text watermark provided, return image with params
			$params = array_merge($params, $textMark->toArray());

			return $glide->getImageResponse($imageData->name, $params);
		}

		if ($hasImageMark) {
			// Only image watermark provided, return image with params
			$params = array_merge($params, $imageMark->toArray());

			return $glide->getImageResponse($imageData->name, $params);
		}

		// No watermarks provided, return image with params
		return $glide->getImageResponse($imageData->name, $params);
	}
}
