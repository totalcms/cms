<?php

namespace TotalCMS\Transformer;

use League\Fractal;
use TotalCMS\Domain\Collection\Data\CollectionData as Collection;

final class CollectionMetaTransformer extends Fractal\TransformerAbstract
{
    /**
     * Fractal transform for a collection.
     *
     * @param Collection $collection The collection object
     *
     * @return array
     */
    public function transform(Collection $collection): array
    {
        return $collection->toArray();
    }
}
