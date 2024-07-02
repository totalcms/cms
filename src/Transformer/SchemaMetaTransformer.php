<?php

namespace TotalCMS\Transformer;

use League\Fractal;
use TotalCMS\Domain\Schema\Data\SchemaData;

final class SchemaMetaTransformer extends Fractal\TransformerAbstract
{
    /**
     * Fractal transform for a schema.
     *
     * @param SchemaData $schema The schema object
     *
     * @return array<string,mixed>
     */
    public function transform(SchemaData $schema): array
    {
        return $schema->toArray();
    }
}
