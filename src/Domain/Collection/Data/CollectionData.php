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
    // Reserved names that cannot be used for collections
    public const RESERVED_NAMES = [
        'templates',
        'logs',
        '.schemas',
        'schemas',
    ];

    private Serializer $serializer;

    public string $id;               // collection id
    public string $name;             // collection name
    public string $description;      // collection description
    public string $schema;           // schema name
    public string $url;              // collection url to object page minus the slug
    public array $properties;        // Rules for form labels, help text and field types
    public array $customProperties;  // Rules for factory object generation

    public function __construct()
    {
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    public function toArray(): array
    {
        $collection = [
            'id'          => $this->id,
            'schema'      => $this->schema,
            'name'        => $this->name ?? ucfirst($this->id),
            'description' => $this->description ?? "A collection of {$this->id} objects that conform to the {$this->schema} schema.",
            'url'         => $this->url ?? '',
            'properties'  => $this->properties ?? new \stdClass(),
        ];

        if (!empty($this->customProperties)) {
            $collection['customProperties'] = $this->customProperties;
        }

        return $collection;
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
