<?php

namespace TotalCMS\Domain\Schema\Service;

use TotalCMS\Domain\Schema\Repository\SchemaRepository;

/**
 * Service.
 */
final class SchemaRemover
{
    private SchemaRepository $storage;

    public function __construct(SchemaRepository $storage)
    {
        $this->storage = $storage;
    }

    /**
     * delete a schema.
     *
     * @param string $id
     *
     * @throws \UnexpectedValueException
     */
    public function deleteSchema(string $id): bool
    {
        $reserved = $this->storage->reservedSchemasIds();
        if (in_array($id, $reserved)) {
            throw new \UnexpectedValueException("Unable to delete schema type ({$id}) is reserved", 1);
        }

        return $this->storage->deleteSchema($id);
    }
}
