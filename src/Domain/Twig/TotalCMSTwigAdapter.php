<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

/**
 * Twig Adapter with Total CMS.
 */
final class TotalCMSTwigAdapter
{
    public string $api;
    private Config $config;
    private IndexReader $collectionReader;
    private ObjectFetcher $objectFetcher;

    public function __construct(
        Config $config,
        IndexReader $collectionReader,
        ObjectFetcher $objectFetcher,
    ) {
        $this->config           = $config;
        $this->collectionReader = $collectionReader;
        $this->objectFetcher    = $objectFetcher;

        $this->api    = $this->config->api;
    }

    // Get all objects from a collection
    public function collection(string $collection): array
    {
        $collection = $this->collectionReader->fetchIndex($collection);

        return $collection->objects->toArray();
    }

    // Get a list of all values from a property in a collection
    public function property(string $collection, string $property): array
    {
        $collection = $this->collectionReader->fetchIndex($collection);

        return $collection->objects->pluck($property)->toArray();
    }

    // Get an objects from a collection
    public function object(string $collection, string $id): array
    {
        $object = $this->objectFetcher->fetchObject($collection, $id);

        return $object->toArray();
    }

    // Get an data property from an object
    public function data(string $collection, string $id, string $property): mixed
    {
        $object = $this->object($collection, $id);

        return $object[$property];
    }

    // Get an text property from an object
    public function text(string $id, string $collection = 'text', string $property = 'text'): string
    {
        return strval($this->data($collection, $id, $property));
    }
}
