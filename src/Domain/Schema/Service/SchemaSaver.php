<?php

namespace App\Domain\Schema\Service;

use App\Domain\Schema\Data\SchemaData;
use App\Domain\Schema\Service\SchemaFetcher;
use App\Domain\Schema\Repository\SchemaRepository;
use RuntimeException;
use UnexpectedValueException;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\Helper;

/**
 * Service.
 */
final class SchemaSaver
{
    private SchemaRepository $storage;
    private SchemaFetcher $fetcher;

    protected const ID_EXT = '.json#';

    public function __construct(
        SchemaRepository $storage,
        SchemaFetcher $fetcher,
    ) {
        $this->storage = $storage;
        $this->fetcher = $fetcher;
    }

    /**
     * Validate a schema
     *
     * @param array $data
     *
     * @return bool
     */
    public function validateSchema(array $data): bool
    {
        $schema = $this->fetcher->fetchSchema('schema');

        $schema = Helper::toJSON($schema->schema);
        $data   = Helper::toJSON($data);

        $validator = new Validator();
        $result = $validator->validate($data, $schema);

        return $result->isValid();
    }

    /**
     * Save a collection schema.
     *
     * @param string $schemaJSON
     *
     * @throws RuntimeException
     * @throws UnexpectedValueException
     *
     * @return SchemaData
     */
    public function saveSchema(string $schemaJSON): SchemaData
    {
        $data = json_decode($schemaJSON, true);

        // if name is provided, use the to create the ID
        // if (isset($data['type'])) {
        //     $data['$id'] = ".schemas/". $data['type'] . self::ID_EXT;
        // }

        if ($this->validateSchema($data) === false) {
            throw new UnexpectedValueException('Invalid schema data provided', 1);
        }

        $schema = new SchemaData();
        $schema->schema = $data;
        $schema->type = basename($schema->schema['$id'], self::ID_EXT);

        if (!$schema instanceof SchemaData) {
            throw new UnexpectedValueException('Invalid schema data provided', 1);
        }

        $this->storage->saveSchema($schema);
        return $schema;
    }
}
