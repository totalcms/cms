<?php

namespace TotalCMS\Domain\Property\Data;

/**
 * String type property data.
 */
class GalleryData extends PropertyData
{
    public array $images = [];

    public function __construct(array $images = [])
    {
        $this->images = $images;
    }

    public function transform(): array
    {
        return $this->images;
    }
}
