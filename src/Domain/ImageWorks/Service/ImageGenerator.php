<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Utils\PathUtils;

final class ImageGenerator
{
	private string $collection;
	private string $id;
	private string $property;
	/** @var array<string,mixed> */
	private array $params;

	public function __construct(
		private PropertyFetcher $propertyFetcher,
		private GlideFactory $glideFactory
	) {
	}

	/** @param array<string,mixed> $params */
	public function generateImage(
		string $collection,
		string $id,
		string $property,
		array $params
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
		string $filename,
		array $params
	): ResponseInterface {
		$galleryData = $this->propertyFetcher->fetchProperty($collection, $id, $property);

		if (!$galleryData instanceof GalleryData) {
			throw new \UnexpectedValueException('Invalid gallery property found');
		}

		switch ($filename) {
			case 'first':
				$imageData = array_shift($galleryData->images);
				break;
			case 'last':
				$imageData = array_pop($galleryData->images);
				break;
			case 'random':
				$imageData = $galleryData->images[array_rand($galleryData->images)];
				break;
			case 'featured':
				usort($galleryData->images, fn ($a, $b) => $a['featured'] <=> $b['featured']);
				$imageData = array_shift($galleryData->images);
				break;
			default:
				$imageData = array_filter($galleryData->images, fn ($image) => pathinfo($image['name'])['filename'] === $filename);
				$imageData = array_shift($imageData);
		}

		if (empty($imageData)) {
			throw new \UnexpectedValueException('Gallery Image not found');
		}

		$imageData = new ImageData($imageData);

		if (!$imageData instanceof ImageData) {
			throw new \UnexpectedValueException('Invalid image property found in gallery');
		}

		$this->collection = $collection;
		$this->id         = $id;
		$this->property   = $property;
		$this->params     = $this->cleanupParams($params, $imageData);

		return $this->responseFromImageData($imageData);
	}

	/**
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return array<string,int|string>
	 */
	private function cleanupParams(array $params, ImageData $imageData): array
	{
		// If no params are provided, return the original image
		// The Action class automatically adds the format to the params so we need to check for that
		if (empty($params) || (count($params) === 1 && isset($params['fm']))) {
			return [];
		}

		// Make sure that the requested width and height are not larger than the original image
		if (isset($params['w']) && $params['w'] > $imageData->width && $imageData->width > 0) {
			$params['w'] = $imageData->width;
		}

		if (isset($params['h']) && $params['h'] > $imageData->height && $imageData->height > 0) {
			$params['h'] = $imageData->height;
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

	private function responseFromImageData(ImageData $imageData): ResponseInterface
	{
		if (empty($this->params)) {
			return $this->returnOriginalImage($imageData);
		}

		$glide = $this->glideFactory->create(
			source: PathUtils::buildPath($this->collection, $this->id, $this->property),
			imageData: $imageData,
		);

		return $glide->getImageResponse($imageData->name, $this->params);
	}
}
