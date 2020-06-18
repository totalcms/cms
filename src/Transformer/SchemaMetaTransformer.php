<?php

namespace App\Transformer;

use App\Domain\Schema\Data\SchemaData;
use League\Fractal;

class SchemaMetaTransformer extends Fractal\TransformerAbstract
{
    /**
     * Fractal transform for a schema
     *
     * @param SchemaData $schema the schema object
     *
     * @return mixed[]
     */
    public function transform(SchemaData $schema) : array
    {
        return $schema->schema;
    }
}
