<?php

namespace App\Transformer;

use App\Domain\Schema\Data\SchemaData;
use League\Fractal;

final class SchemaMetaTransformer extends Fractal\TransformerAbstract
{
    /**
     * Fractal transform for a schema.
     *
     * @param SchemaData $schema The schema object
     *
     * @return array
     */
    public function transform(SchemaData $schema): array
    {
        return $schema->schema;
    }
}
