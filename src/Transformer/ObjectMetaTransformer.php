<?php

namespace App\Transformer;

use App\Domain\Storage\ObjectData;
use League\Fractal;

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
