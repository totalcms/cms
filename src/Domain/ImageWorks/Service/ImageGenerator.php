<?php

namespace App\Domain\ImageWorks\Service;

use App\Domain\Property\Data\ImageData;
use App\Domain\Property\Service\PropertyFetcher;
use App\Utils\PathUtils;
use Psr\Http\Message\ResponseInterface;
use UnexpectedValueException;

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
     * @throws UnexpectedValueException
     *
     * @return ResponseInterface
     */
    public function generate(string $collection, string $id, string $property, array $params): ResponseInterface
    {
        $imageData = $this->propertyFetcher->fetchProperty($collection, $id, $property);

        if (!$imageData instanceof ImageData) {
            throw new UnexpectedValueException('Invalid image property found');
        }

        $glide = $this->glideFactory->create(
            source: PathUtils::buildPath($collection, $id, $property),
        );

        $response = $glide->getImageResponse($imageData->name, $params);

        return $response;
    }
}
