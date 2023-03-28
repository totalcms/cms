<?php

namespace TotalCMS\Transformer;

use TotalCMS\Domain\Index\Data\IndexData;
use League\Fractal;

final class IndexTransformer extends Fractal\TransformerAbstract
{
    /**
     * Fractal transform for a collection index.
     *
     * @param IndexData $index The collection index object
     *
     * @return array
     */
    public function transform(IndexData $index): array
    {
        return [
            'objects' => $index->objects->toArray(),
        ];
    }
}
