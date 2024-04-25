<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Utils\PathUtils;

final class ImageGenerator
{
    private PropertyFetcher $propertyFetcher;
    private GlideFactory $glideFactory;

    public function __construct(PropertyFetcher $propertyFetcher, GlideFactory $glideFactory)
    {
        $this->propertyFetcher = $propertyFetcher;
        $this->glideFactory    = $glideFactory;
    }

    private function returnOriginalImage(string $collection, string $id, string $property, ImageData $imageData): ResponseInterface
    {
        $imagePath = PathUtils::buildPath($collection, $id, $property, $imageData->name);
        $imageFile = fopen($imagePath, 'rb');
        if ($imageFile === false) {
            throw new \UnexpectedValueException("Unable to locate image file: $imagePath");
        }
        $stream = new Stream($imageFile);

        $mimeType = mime_content_type($imagePath);

        return (new Response())
            ->withHeader('Content-Type', $mimeType ?: 'image/jpeg')
            ->withBody($stream);
    }

    /**
     * Generate Image from a property.
     *
     * @param string $collection
     * @param string $id
     * @param string $property
     * @param array  $params
     *
     * @throws \UnexpectedValueException
     *
     * @return ResponseInterface
     */
    public function generate(string $collection, string $id, string $property, array $params): ResponseInterface
    {
        $imageData = $this->propertyFetcher->fetchProperty($collection, $id, $property);

        if (!$imageData instanceof ImageData) {
            throw new \UnexpectedValueException('Invalid image property found');
        }

        // If no params are provided, return the original image
        // The Action class automatically adds the format to the params so we need to check for that
        if (empty($params) || (count($params) === 1 && isset($params['fm']))) {
            $imagePath = PathUtils::buildPath($collection, $id, $property, $imageData->name);
            $response  = $this->glideFactory->originalImage($imagePath);

            return (new Response())
                ->withHeader('Content-Type', $response['mimeType'] ?: 'image/jpeg')
                ->withBody($response['stream']);
        }

        $glide = $this->glideFactory->create(
            source: PathUtils::buildPath($collection, $id, $property),
        );

        // Integrate Image data into params

        // Make sure that the requested width and height are not larger than the original image
        if (isset($params['w']) && $params['w'] > $imageData->width) {
            $params['w'] = $imageData->width;
        }

        if (isset($params['h']) && $params['h'] > $imageData->height) {
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

        $response = $glide->getImageResponse($imageData->name, array_filter($params));

        return $response;
    }
}
