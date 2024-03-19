<?php

namespace TotalCMS\Transformer;

use League\Fractal;
use TotalCMS\Domain\Object\Data\ObjectData;

final class ObjectMetaTransformer extends Fractal\TransformerAbstract
{
    /**
     * Fractal transform for a object.
     *
     * @param ObjectData $object The object
     *
     * @return array
     */
    public function transform(ObjectData $object): array
    {
        return $object->toArray();
    }
}
