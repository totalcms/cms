<?php

namespace App\Transformer;

use App\Domain\Collection\Data\CollectionData as Collection;
use League\Fractal;

class CollectionMetaTransformer extends Fractal\TransformerAbstract
{
    /**
     * Fractal transform for a collection
     *
     * @param Collection $collection the collection object
     *
     * @return array<mixed>
     */
    public function transform(Collection $collection) : array
    {
        return [
            'name'   => $collection->name,
            'schema' => $collection->schema,
            'url'    => $collection->url ?? '',
        ];
    }
}
