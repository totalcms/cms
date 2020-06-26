<?php

namespace App\Transformer;

use App\Domain\Object\Data\ObjectData;
use League\Fractal;

class ObjectMetaTransformer extends Fractal\TransformerAbstract
{
    /**
     * Fractal transform for a object
     *
     * @param ObjectData $object the object object
     *
     * @return mixed[]
     */
    public function transform(ObjectData $object) : array
    {
        return $object->toArray();
    }
}
