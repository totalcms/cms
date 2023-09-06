<?php

namespace TotalCMS\Domain\Schema\Data;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Schema Data object.
 */
final class SchemaData
{
    public const SCHEMA_PREFIX    = 'https://www.totalcms.co/schemas/';
    public const SCHEMA_VERSION   = 'https://json-schema.org/draft/2020-12/schema';
    public const RESERVED_SCHEMAS = [
        'blog',
        'color',
        'date',
        'depot',
        'email',
        'feed',
        'file',
        'gallery',
        'image',
        'meta',
        'number',
        'schema',
        'styledtext',
        'svg',
        'templates', // This is a special case, it's not a schema but a reserved folder
        'text',
        'toggle',
        'url',
    ];

    public string $id;
    public string $description;
    public array $properties;
    public array $required;
    public array $index;
    protected Serializer $serializer;

    public function __construct()
    {
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    public function toArray(): array
    {
        return [
            '$schema'     => self::SCHEMA_VERSION,
            '$id'         => self::SCHEMA_PREFIX . $this->id . '.json',
            'type'        => 'object',
            'id'          => $this->id,
            'description' => $this->description,
            'properties'  => $this->properties,
            'required'    => $this->required,
            'index'       => $this->index,
        ];
    }

    public function toJson(): string
    {
        return $this->serializer->serialize($this->toArray(), 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
    }
}
