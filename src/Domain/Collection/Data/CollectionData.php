<?php

namespace TotalCMS\Domain\Collection\Data;

use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Data object.
 */
final class CollectionData
{
    public const RESERVED_COLLECTIONS = [
        'blog',
        'color',
        'date',
        'depot',
        'email',
        'feed',
        'file',
        'gallery',
        'image',
        'number',
        'styledtext',
        'svg',
        'text',
        'toggle',
        'url',
    ];

    public string $id;               // collection id
    public string $name;             // collection name
    public string $description;      // collection description
    public string $schema;           // schema name
    public string $url;              // collection url to object page minus the slug
    public array $properties;        // Rules for form labels, help text and field types
    public array $customProperties;  // Rules for factory object generation
    protected Serializer $serializer;

    public function __construct()
    {
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'schema'           => $this->schema,
            'name'             => $this->name ?? $this->id,
            'description'      => $this->description ?? '',
            'url'              => $this->url ?? '',
            'properties'       => $this->properties ?? [],
            'customProperties' => $this->customProperties ?? [],
        ];
    }

    public function isValid(): bool
    {
        return isset($this->id) && isset($this->schema);
    }

    public function toJson(): string
    {
        return $this->serializer->serialize($this->toArray(), 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
    }
}
