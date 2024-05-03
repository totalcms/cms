<?php

namespace TotalCMS\Domain\Object\Data;

use Cocur\Slugify\Slugify;
use Illuminate\Support\Collection;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Property\Data\PropertyData;

/**
 * Data collection object.
 */
class ObjectData
{
    // Reserved names that cannot be used for objects
    public const RESERVED_NAMES = [
        'index',
        'id',
    ];

    public string $id;
    /** @var Collection<string, PropertyData> */
    public Collection $properties;
    protected Serializer $serializer;

    public function __construct(string $id, array $properties)
    {
        $this->id         = (new Slugify())->slugify($id);
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
        // Transform properties
        $collection       = new Collection($properties);
        $this->properties = $collection->map(fn ($property) => $property->transform());
    }

    public function toArray(): array
    {
        $base = ['id' => $this->id];

        return array_merge($base, $this->properties->toArray());
    }

    public function toJson(): string
    {
        return $this->serializer->serialize($this->toArray(), 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);
    }
}
