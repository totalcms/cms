<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;

/**
 * Service.
 */
final class SchemaFetcher
{
    private SchemaRepository $storage;

    public function __construct(SchemaRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * fetch a schema.
     *
     * @param string $id
     *
     * @return SchemaData
     */
    public function fetchSchema(string $id): SchemaData
    {
        return $this->storage->getSchema($id);
    }
}
