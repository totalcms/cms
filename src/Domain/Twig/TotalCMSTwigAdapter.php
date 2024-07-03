<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Support\Config;

/**
 * Twig Adapter with Total CMS.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
final class TotalCMSTwigAdapter
{
    public string $api;
    /** @var array<string,mixed> */
    private array $storage;

    public function __construct(
        private Config $config,
        private IndexReader $collectionReader,
        private ObjectFetcher $objectFetcher,
        private CollectionLister $collectionLister,
        private CollectionFetcher $collectionFetcher,
        private SchemaLister $schemaLister,
        private SchemaFetcher $schemaFetcher,
        private TotalFormFactory $totalFormFactory,
    ) {
        $this->api     = $this->config->api;
        $this->storage = [];
    }

    // TODO: REMOVE after refactor
    /** @return array<string,mixed> */
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

    public function config(string $key, ?string $setting): mixed
    {
        if ($setting === null) {
            return $this->config->$key;
        }

        $config = $this->config->$key;
        if (is_array($config) && key_exists($setting, $config)) {
            return $config[$setting];
        }

        return '';
    }

    // store data in the adapter
    // TODO: REMOVE after refactor
    public function getData(string $key): mixed
    {
        return key_exists($key, $this->storage) ? $this->storage[$key] : null;
    }

    // store data in the adapter
    // TODO: REMOVE after refactor
    public function storeData(string $key, mixed $value): void
    {
        $this->storage[$key] = $value;

        return;
    }

    // Reset stored collection name
    // TODO: REMOVE after refactor
    public function clearStorage(): void
    {
        $this->storage = [];
    }

    // Get all schemas
    /** @return array<array<string,mixed>> */
    public function schemas(): array
    {
        $schemas = $this->schemaLister->listAllSchemas();

        return array_map(fn ($schema) => $schema->toArray(), $schemas);
    }

    // Get all reserved schemas
    /** @return array<array<string,mixed>> */
    public function reservedSchemas(): array
    {
        $schemas = $this->schemaLister->listReservedSchemas();

        return array_map(fn ($schema) => $schema->toArray(), $schemas);
    }

    // Get all custom schemas
    /** @return array<array<string,mixed>> */
    public function customSchemas(): array
    {
        $schemas = $this->schemaLister->listCustomSchemas();

        return array_map(fn ($schema) => $schema->toArray(), $schemas);
    }

    // Get schema definition
    /** @return array<string,mixed> */
    public function schema(string $schema): array
    {
        $schema = $this->schemaFetcher->fetchSchema($schema);

        return $schema->toArray();
    }

    // Get all collections
    /** @return array<object> */
    public function collections(): array
    {
        return $this->collectionLister->listAllCollections();
    }

    // Get collection meta data
    /** @return array<string,mixed> */
    public function collection(string $collection): array
    {
        $collection = $this->collectionFetcher->fetchCollection($collection);

        if ($collection === null) {
            return [];
        }

        return $collection->toArray();
    }

    /** @SuppressWarnings(PHPMD.BooleanArgumentFlag) */
    public function objectUrl(string $id, string $collection, bool $pretty = false): string
    {
        $collection = $this->collection($collection);
        $url        = $collection['url'] ?: '';

        if ($pretty) {
            if (str_ends_with($url, '/')) {
                return sprintf('%s%s', $url, $id);
            }

            return sprintf('%s/%s', $url, $id);
        }

        return sprintf('%s?id=%s', $url, $id);
    }

    // Get all objects from a collection
    /** @return array<array<string,mixed>> */
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
    /** @return array<mixed> */
    public function property(string $collection, string $property): array
    {
        $collection = $this->collectionReader->fetchIndex($collection);

        if ($collection === null) {
            return [];
        }

        return $collection->objects->pluck($property)->flatten()->unique()->toArray();
    }

    // Get an objects from a collection
    /** @return array<string,mixed> */
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

    // Get an property from an object
    /** @return array<array<string,string|int>> */
    public function depot(string $id, string $collection = 'depot', string $property = 'files'): array
    {
        $files = $this->data($collection, $id, $property);

        return is_array($files) ? $files : [];
    }

    /** @param array<string,string|int> $options */
    public function image(?string $id, array $options = [], string $collection = 'image', string $property = 'image'): string
    {
        if (empty($id)) {
            return '';
        }

        $imagePath = $this->imagePath($id, $options, $collection, $property);
        if (empty($imagePath)) {
            return '';
        }

        $alt = $this->alt($id, $collection, $property);

        return sprintf('<img src="%s" alt="%s" oncontextmenu="return false;" draggable="false" />', $imagePath, $alt);
    }

    // Get the image path for an image property
    /** @param array<string,string|int> $options */
    public function imagePath(?string $id, array $options = [], string $collection = 'image', string $property = 'image'): string
    {
        if (empty($id)) {
            return '';
        }

        $image = $this->data($collection, $id, $property);
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
        $parsedUrl = parse_url($api);

        if (!isset($parsedUrl['path'])) {
            return '';
        }

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

    /** @param array<string,string|int> $options */
    public function galleryImage(?string $id, ?string $filename, array $options = [], string $collection = 'gallery', string $property = 'gallery'): string
    {
        if (empty($id) || empty($filename)) {
            return '';
        }

        $imagePath = $this->galleryPath($id, $filename, $options, $collection, $property);
        if (empty($imagePath)) {
            return '';
        }

        $alt = $this->galleryAlt($id, $filename, $collection, $property);

        return sprintf('<img src="%s" alt="%s" oncontextmenu="return false;" draggable="false" />', $imagePath, $alt);
    }

    // get an image object from inside a gallery by it's name
    /** @return array<string,mixed> */
    public function galleryImageData(string $id, string $name, string $collection = 'gallery', string $property = 'gallery'): ?array
    {
        $gallery = $this->data($collection, $id, $property);
        if (!is_array($gallery)) {
            return null;
        }

        $image = array_filter($gallery, fn ($image) => pathinfo($image['name'])['filename'] === $name);

        foreach ($gallery as $image) {
            if ($image['name'] === $name) {
                return $image;
            }
        }

        return null;
    }

    // Get the image path for gallery image
    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @param array<string,string|int> $options
     */
    public function galleryPath(?string $id, ?string $name, array $options = [], string $collection = 'gallery', string $property = 'gallery'): string
    {
        if (empty($id) || empty($name)) {
            return '';
        }

        // Default to dynamic API routes
        $api              = $this->api . "/imageworks/$collection/$id/$property/$name";
        $options['cache'] = uniqid();
        $dynamicRoutes    = ['first', 'last', 'random', 'featured'];

        // Process the image as regular filename
        if (!in_array($name, $dynamicRoutes)) {
            $image = $this->galleryImageData($id, $name, $collection, $property);
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
            $type     = in_array($type, GlideFactory::IMG_TYPES) ? $type : 'jpg';
            $basename = pathinfo($name)['filename'];

            $api = $this->api . "/imageworks/$collection/$id/$property/$basename.$type";

            // cache busting links
            $options['cache'] = strrev(preg_replace('/\W+/', '', $image['uploadDate']));
        }

        // From Stacks Preview Server - Not used in Imageworks and breaks the image generation
        unset($options['datadir']);
        unset($options['route']);

        // Parse the existing URL and its query parameters
        $parsedUrl = parse_url($api);

        if (!isset($parsedUrl['path'])) {
            return '';
        }

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

        if (!is_array($image) || !key_exists('alt', $image)) {
            return '';
        }

        return $image['alt'];
    }

    // Get an alt tag for a gallery image
    public function galleryAlt(string $id, string $filename, string $collection = 'gallery', string $property = 'gallery'): string
    {
        $image = $this->galleryImageData($id, $filename, $collection, $property);

        if (!is_array($image) || !key_exists('alt', $image)) {
            return '';
        }

        return $image['alt'];
    }

    /** @param array<string,mixed> $options */
    public function objectFormBuilder(array $options = []): TotalForm
    {
        return $this->totalFormFactory->objectFormBuilder($options);
    }

    /** @param array<string,mixed> $options */
    public function textForm(array $options): string
    {
        $form = $this->totalFormFactory->objectFormBuilder($options);

        return $form->autoBuild();
    }
}
