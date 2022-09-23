<?php

namespace App\Domain\Property\Data;

use Cocur\Slugify\Slugify;

/**
 * Slug type property data.
 */
class SlugData extends PropertyData
{
    public string $slug;

    public function __construct(string $slug)
    {
        $this->slug = (new Slugify())->slugify($slug);
    }

    public function transform(): string
    {
        return (string)$this;
    }

    public function __toString(): string
    {
        return $this->slug;
    }
}
