<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use Psr\Http\Message\ResponseInterface;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Utils\PathUtils;

final class GalleryImageGenerator
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
     * @param string $filename
     * @param array  $params
     *
     * @throws \UnexpectedValueException
     *
     * @return ResponseInterface
     */
    public function generate(string $collection, string $id, string $property, string $filename, array $params): ResponseInterface
    {
        $galleryData = $this->propertyFetcher->fetchProperty($collection, $id, $property);

        if (!$galleryData instanceof GalleryData) {
            throw new \UnexpectedValueException('Invalid gallery property found');
        }

        $imageData = array_filter($galleryData->images, fn ($image) => $image['name'] === $filename)[0];
        $imageData = new ImageData($imageData);

        if (!$imageData instanceof ImageData) {
            throw new \UnexpectedValueException('Invalid image property found in gallery');
        }

        $glide = $this->glideFactory->create(
            source: PathUtils::buildPath($collection, $id, $property),
        );

        // Integrate Image data into params

        if (isset($params['fit'])) {
            $params['fit'] = GlideFactory::cropFocalpoint($params['fit'], $imageData->focalpoint);
        }

        if (isset($params['bg'])) {
            $params['bg'] = GlideFactory::updateBackgroundColor($params['bg'], $imageData->color);
        }

        if (isset($params['border'])) {
            $params['border'] = GlideFactory::updateBorderColor($params['border'], $imageData->color);
        }

        $response = $glide->getImageResponse($imageData->name, $params);

        return $response;
    }
}
