<?php

namespace App\Domain\ImageWorks\Service;

use App\Domain\Property\Data\ColorData;
use App\Domain\Property\Data\ImageData;
use App\Domain\Property\Service\PropertyFetcher;
use App\Utils\ColorUtils;
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

        // Integrate Image data into params

        if (isset($params['fit'])) {
            $crop = sprintf('crop-%g-%g', $imageData->focalpoint['x'], $imageData->focalpoint['y']);

            $params['fit'] = str_replace('focalpoint', $crop, $params['fit']);
        }

        $colors = ['main', 'complimentary'];

        // TODO: colorToHex is currently broken

        if (isset($params['bg'])) {
            foreach ($colors as $color) {
                if ($params['bg'] === $color) {
                    $params['bg'] = ColorUtils::colorToHex(new ColorData($imageData->color[$color]));
                    break;
                }
            }
        }

        if (isset($params['border'])) {
            foreach ($colors as $color) {
                if (str_contains($params['border'], $color)) {
                    $hex              = ColorUtils::colorToHex(new ColorData($imageData->color[$color]));
                    var_dump($hex);
                    $params['border'] = str_replace($color, $hex, $params['border']);
                    break;
                }
            }
        }

        $response = $glide->getImageResponse($imageData->name, $params);

        return $response;
    }
}
