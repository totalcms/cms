<?php

namespace App\Domain\Schema\Service;

use App\Domain\Schema\Repository\SchemaRepository;
use App\Interfaces\ServiceInterface;

/**
 * Service.
 */
final class SchemaService implements ServiceInterface
{
    private SchemaRepository $repository;

    /**
     * Constructor.
     *
     * @param SchemaRepository $repository The repository
     */
    public function __construct(SchemaRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getSchemaforCollection(string $collection) : array
    {
        return $this->repository->listAllCollections();
    }
}
