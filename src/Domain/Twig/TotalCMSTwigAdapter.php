<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

/**
 * Twig Adapter with Total CMS.
 */
final class TotalCMSTwigAdapter
{
    public string $api;
    public string $assetsDir;
    private array $storage;
    private Config $config;
    private IndexReader $collectionReader;
    private ObjectFetcher $objectFetcher;
    private CollectionFetcher $collectionFetcher;

    public function __construct(
        Config $config,
        IndexReader $collectionReader,
        ObjectFetcher $objectFetcher,
        CollectionFetcher $collectionFetcher
    ) {
        $this->config            = $config;
        $this->collectionReader  = $collectionReader;
        $this->objectFetcher     = $objectFetcher;
        $this->collectionFetcher = $collectionFetcher;

        $this->api       = $this->config->api;
        $this->assetsDir = $this->config->assetsDir;
        $this->storage   = [];
    }

    // Get collection meta data
    public function formDefinitions(string $property, string $collection, ?string $id): array
    {
        $collection = $this->collectionFetcher->fetchCollection($collection);
        $properties = [];

        if (isset($collection->properties[$property])) {
            $properties = $collection->properties[$property];
        }
        if (!empty($id) && isset($collection->customProperties[$id][$property])) {
            $properties = array_merge($properties, $collection->customProperties[$id][$property]);
        }

        return $properties;
    }

    // store data in the adapter
    public function getData(string $key): mixed
    {
        return isset($this->storage[$key]) ? $this->storage[$key] : null;
    }

    // store data in the adapter
    public function storeData(string $key, mixed $value): void
    {
        $this->storage[$key] = $value;
    }

    // Reset stored collection name
    public function clearStorage(): void
    {
        $this->storage = [];
    }

    // Get collection meta data
    public function collection(string $collection): array
    {
        $collection = $this->collectionFetcher->fetchCollection($collection);

        return $collection->toArray();
    }

    // Get all objects from a collection
    public function objects(string $collection): array
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
        // if there is an exception, return an empty array for the template
        try {
            $object = $this->objectFetcher->fetchObject($collection, $id);
        } catch (\Exception $e) {
            return [];
        }

        return $object->toArray();
    }

    // Get an data property from an object
    public function data(string $collection, string $id, string $property): mixed
    {
        $object = $this->object($collection, $id);

        if (key_exists($property, $object)) {
            return $object[$property];
        }

        return '';
    }

    // Get an text property from an object
    public function text(string $id, string $collection = 'text', string $property = 'text'): string
    {
        return strval($this->data($collection, $id, $property));
    }

    // Get an styledtext property from an object
    public function styledtext(string $id, string $collection = 'styledtext', string $property = 'styledtext'): string
    {
        return strval($this->data($collection, $id, $property));
    }

    // Get an text property from an object
    public function depot(string $id, string $collection = 'depot', string $property = 'files'): array
    {
        $files = $this->data($collection, $id, $property);

        return is_array($files) ? $files : [];
    }

    // Get an text property from an object
    public function image(?string $id, array $options = [], string $collection = 'image', string $property = 'image'): string
    {
        if (empty($id)) {
            return '';
        }

        $type = 'jpg';
        if (isset($options['type'])) {
            $type = $options['type'];
            unset($options['type']);
        }

        $api = $this->api . "/imageworks/$collection/$id/$property.$type";

        // cache busting links
        $image = $this->data($collection, $id, 'image');
        $cache = strrev(preg_replace('/\W+/', '', $image['uploadDate']));
        $api .= "?cache=$cache";

        if (!empty($options)) {
            $options = http_build_query($options);
            $api .= "&$options";
        }

        return $api;
    }

    // Get an alt tag for an image
    public function alt(string $id, string $collection = 'image', string $property = 'image'): string
    {
        $image = $this->data($collection, $id, $property);

        return $image['alt'];
    }
}
