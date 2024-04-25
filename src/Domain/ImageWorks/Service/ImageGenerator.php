<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use Psr\Http\Message\ResponseInterface;
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
