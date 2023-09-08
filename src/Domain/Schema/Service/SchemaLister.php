<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Repository\SchemaRepository;

/**
 * Service.
 */
final class SchemaLister
{
    private SchemaRepository $storage;

    public function __construct(SchemaRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * List all Schemas.
     *
     * @return array<object>
     */
    public function listAllSchemas(): array
    {
        return array_merge(
            $this->listReservedSchemas(),
            $this->listCustomSchemas()
        );
    }

    /**
     * List reserved Schemas.
     *
     * @return array<object>
     */
    public function listReservedSchemas(): array
    {
        return $this->storage->listReservedSchemas();
    }

    /**
     * List custom Schemas.
     *
     * @return array<object>
     */
    public function listCustomSchemas(): array
    {
        return $this->storage->listCustomSchemas();
    }
}
