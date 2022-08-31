<?php

namespace App\Domain\Object\Data;

use Cocur\Slugify\Slugify;
use Illuminate\Support\Collection;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Data collection object.
 */
class ObjectData
{
    public string $id;
    /** @var Collection<string, mixed> */
    public Collection $properties;
    protected Serializer $serializer;

    public function __construct(string $id, array $properties)
    {
        $this->id         = (new Slugify())->slugify($id);
        $this->properties = new Collection($properties);
        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
    }

    public function toArray(): array
    {
        $base = ['id' => $this->id];

        return array_merge($base, $this->properties->toArray());
    }

    public function toJson(): string
    {
        return $this->serializer->serialize($this->toArray(), 'json');
    }
}
