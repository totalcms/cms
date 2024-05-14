<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Support\Config;

/**
 * Twig Adapter with Total CMS.
 */
final class TotalCMSTwigAdapter
{
    public string $api;
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

        $this->api     = $this->config->api;
        $this->storage = [];
    }

    // Get collection meta data
    public function formDefinitions(string $property, string $collection, ?string $id): array
    {
        $collection = $this->collectionFetcher->fetchCollection($collection);
        $properties = [];

        if ($collection === null) {
            return [];
        }

        if (key_exists($property, $collection->properties)) {
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
        return key_exists($key, $this->storage) ? $this->storage[$key] : null;
    }

    // store data in the adapter
    public function storeData(string $key, mixed $value): void
    {
        $this->storage[$key] = $value;

        return;
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

        if ($collection === null) {
            return [];
        }

        return $collection->toArray();
    }

    // Get all objects from a collection
    public function objects(string $collection): array
    {
        // if there is an exception, return an empty array
        try {
            $collection = $this->collectionReader->fetchIndex($collection);
        } catch (\Exception $e) {
            return [];
        }

        if ($collection === null) {
            return [];
        }

        return $collection->objects->toArray();
    }

    // Get a list of all values from a property in a collection
    public function property(string $collection, string $property): array
    {
        $collection = $this->collectionReader->fetchIndex($collection);

        if ($collection === null) {
            return [];
        }

        return $collection->objects->pluck($property)->flatten()->unique()->toArray();
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

        if (is_array($object) && key_exists($property, $object)) {
            return $object[$property];
        }

        return '';
    }

    public function toggle(string $id, string $collection = 'toggle', string $property = 'status'): bool
    {
        return boolval($this->data($collection, $id, $property));
    }

    public function date(string $id, string $collection = 'date', string $property = 'date'): string
    {
        return strval($this->data($collection, $id, $property));
    }

    public function color(string $id, string $collection = 'color', string $property = 'color'): string
    {
        return $this->data($collection, $id, $property);
    }

    public function svg(string $id, string $collection = 'svg', string $property = 'svg'): string
    {
        return strval($this->data($collection, $id, $property));
    }

    public function email(string $id, string $collection = 'email', string $property = 'email'): string
    {
        return strval($this->data($collection, $id, $property));
    }

    public function url(string $id, string $collection = 'url', string $property = 'url'): string
    {
        return strval($this->data($collection, $id, $property));
    }

    public function number(string $id, string $collection = 'number', string $property = 'number'): string
    {
        return strval($this->data($collection, $id, $property));
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

        $image = $this->data($collection, $id, 'image');
        if (!is_array($image) || !key_exists('uploadDate', $image)) {
            return '';
        }

        // Default to original image type
        $type = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
        // If type is set in options, use that
        if (key_exists('fm', $options)) {
            $type = $options['fm'];
            unset($options['fm']);
        }
        // If type is not in the list of allowed types, default to jpg
        $type = in_array($type, GlideFactory::IMG_TYPES) ? $type : 'jpg';

        $api = $this->api . "/imageworks/$collection/$id/$property.$type";

        // cache busting links
        $options['cache'] = strrev(preg_replace('/\W+/', '', $image['uploadDate']));

        // From Stacks Preview Server - Not used in Imageworks and breaks the image generation
        unset($options['datadir']);
        unset($options['route']);

        // Parse the existing URL and its query parameters
        $parsedUrl      = parse_url($api);
        $existingParams = [];
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $existingParams);
        }

        // Merge the existing parameters with the new options
        $options = array_merge($existingParams, $options);

        // Reconstruct the URL without the original query string, and append the new query string
        $api = $parsedUrl['path'] . '?' . http_build_query($options);

        return $api;
    }

    // Get an alt tag for an image
    public function alt(string $id, string $collection = 'image', string $property = 'image'): string
    {
        $image = $this->data($collection, $id, $property);

        return is_array($image) && key_exists('alt', $image) ? $image['alt'] : '';
    }
}
