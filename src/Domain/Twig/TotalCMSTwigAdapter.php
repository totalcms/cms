<?php

namespace TotalCMS\Domain\Twig;

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Support\Config;
use TotalCMS\Utils\HTMLUtils;

/**
 * Twig Adapter with Total CMS.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
final class TotalCMSTwigAdapter
{
	public TotalFormFactory $form;
	public string $api;

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
		$this->api  = $this->config->api;
		$this->form = $this->totalFormFactory;
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

	/**
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @param array<string,bool> $options
	 */
	public function objectUrl(string $id, string $collection, array $options = []): string
	{
		$options = array_merge([
			'pretty' => false,
		], $options);

		$collection = $this->collection($collection);
		$url        = $collection['url'] ?: '';

		if ($options['pretty']) {
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

	/** @param array<string,string> $options */
	public function toggle(string $id, array $options = []): bool
	{
		$options = array_merge([
			'collection' => 'toggle',
			'property'   => 'status',
		], $options);

		return boolval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function date(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'date',
			'property'   => 'date',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function color(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'color',
			'property'   => 'color',
		], $options);

		return $this->data($options['collection'], $id, $options['property']);
	}

	/** @param array<string,string> $options */
	public function svg(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'svg',
			'property'   => 'svg',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function email(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'email',
			'property'   => 'email',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function url(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'url',
			'property'   => 'url',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function number(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'number',
			'property'   => 'number',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function text(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'text',
			'property'   => 'text',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function styledtext(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'styledtext',
			'property'   => 'styledtext',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/**
	 * @param array<string,string> $options
	 *
	 * @return array<array<string,string|int>>
	 */
	public function depot(string $id, array $options = []): array
	{
		$options = array_merge([
			'collection' => 'depot',
			'property'   => 'files',
		], $options);

		$files = $this->data($options['collection'], $id, $options['property']);

		return is_array($files) ? $files : [];
	}

	/**
	 * @param array<string,string> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function image(?string $id, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		if (empty($id)) {
			return '';
		}

		$imagePath = $this->imagePath($id, $imageworks, $options);
		if (empty($imagePath)) {
			return '';
		}

		$alt = $this->alt($id, $options);

		return sprintf('<img src="%s" alt="%s" oncontextmenu="return false;" draggable="false" />', $imagePath, $alt);
	}

	// Get the image path for an image property
	/**
	 * @param array<string,string> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function imagePath(?string $id, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		if (empty($id)) {
			return '';
		}

		$collection = $options['collection'];
		$property   = $options['property'];

		$image = $this->data($collection, $id, $property);
		if (!is_array($image) || !key_exists('uploadDate', $image)) {
			return '';
		}

		return self::buildImageworksAPI($this->api, $id, $image, $imageworks, $options);
	}

	/**
	 * @param array<string,mixed> $image
	 * @param array<string,string> $options
	 * @param array<string,string|int> $imageworks
	 */
	public static function buildImageworksAPI(string $api, string $id, array $image, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		$collection = $options['collection'];
		$property   = $options['property'];

		if (empty($image) || !key_exists('name', $image)) {
			return '';
		}

		// Default to original image type
		$type = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
		// If type is set in imageworks options, use that
		if (key_exists('fm', $imageworks)) {
			$type = $imageworks['fm'];
			unset($imageworks['fm']);
		}
		// If type is not in the list of allowed types, default to jpg
		$type = in_array($type, GlideFactory::IMG_TYPES) ? $type : 'jpg';

		$api .= "/imageworks/$collection/$id/$property.$type";

		// cache busting links
		$imageworks['cache'] = strrev(preg_replace('/\W+/', '', $image['uploadDate']));

		// From Stacks Preview Server - Not used in Imageworks and breaks the image generation
		unset($imageworks['datadir']);
		unset($imageworks['route']);

		// Parse the existing URL and its query parameters
		$parsedUrl = parse_url($api);

		if (!isset($parsedUrl['path'])) {
			return '';
		}

		$existingParams = [];
		if (isset($parsedUrl['query'])) {
			parse_str($parsedUrl['query'], $existingParams);
		}

		// Merge the existing parameters with the new imageworks options
		$imageworks = array_merge($existingParams, $imageworks);

		// Reconstruct the URL without the original query string, and append the new query string
		$api = $parsedUrl['path'] . '?' . http_build_query($imageworks);

		return $api;
	}

	/**
	 * @param array<string,string|int> $thumbSettings
	 * @param array<string,string|int> $fullSettings
	 * @param array<string,string> $options
	 */
	public function gallery(string $id, array $thumbSettings = [], array $fullSettings = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if (empty($thumbSettings)) {
			$thumbSettings = ['w' => 300, 'h' => 200];
		}

		$gallery = '';

		$images = $this->data($options['collection'], $id, $options['property']);
		foreach ($images as $image) {
			$img = HTMLUtils::inlineElement('img', [
				'src' => $this->galleryImage($id, $image['name'], $thumbSettings, $options),
				'alt' => $image['alt'],
			]);
			$link = HTMLUtils::element('a', $img, [
				'href'         => $this->galleryImage($id, $image['name'], $fullSettings, $options),
				'data-lg-size' => "{$image['width']}-{$image['height']}",
			]);
			$gallery .= $link;
		}

		return HTMLUtils::element('div', $gallery, ['class' => 'cms-gallery']);
	}

	/**
	 * @param array<string,string> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function galleryImage(?string $id, ?string $filename, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if (empty($id) || empty($filename)) {
			return '';
		}

		$imagePath = $this->galleryPath($id, $filename, $imageworks, $options);
		if (empty($imagePath)) {
			return '';
		}

		$alt = $this->galleryAlt($id, $filename, $options);

		return sprintf('<img src="%s" alt="%s" oncontextmenu="return false;" draggable="false" />', $imagePath, $alt);
	}

	// get an image object from inside a gallery by it's name
	/**
	 * @param array<string,string> $options
	 *
	 * @return array<string,mixed>
	 */
	public function galleryImageData(string $id, string $name, array $options = []): ?array
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$gallery = $this->data($options['collection'], $id, $options['property']);
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
	 * @param array<string,string> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function galleryPath(?string $id, ?string $name, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if (empty($id) || empty($name)) {
			return '';
		}

		$image = $this->galleryImageData($id, $name, $options) ?? [];

		return self::buildImageworksGalleryAPI($this->api, $id, $name, $image, $imageworks, $options);
	}

	/**
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 *
	 * @param array<string,mixed> $image
	 * @param array<string,string> $options
	 * @param array<string,string|int> $imageworks
	 */
	public static function buildImageworksGalleryAPI(string $baseapi, string $id, string $name, array $image, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$collection = $options['collection'];
		$property   = $options['property'];

		// Default to dynamic API routes
		$api                 = $baseapi . "/imageworks/$collection/$id/$property/$name";
		$imageworks['cache'] = uniqid();
		$dynamicRoutes       = ['first', 'last', 'random', 'featured'];

		// Process the image as regular filename
		if (!in_array($name, $dynamicRoutes)) {
			if (!is_array($image) || !key_exists('uploadDate', $image)) {
				return '';
			}

			// Default to original image type
			$type = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
			// If type is set in imageworks, use that
			if (key_exists('fm', $imageworks)) {
				$type = $imageworks['fm'];
				unset($imageworks['fm']);
			}
			// If type is not in the list of allowed types, default to jpg
			$type     = in_array($type, GlideFactory::IMG_TYPES) ? $type : 'jpg';
			$basename = pathinfo($name)['filename'];

			$api = $baseapi . "/imageworks/$collection/$id/$property/$basename.$type";

			// cache busting links
			$imageworks['cache'] = strrev(preg_replace('/\W+/', '', $image['uploadDate']));
		}

		// From Stacks Preview Server - Not used in Imageworks and breaks the image generation
		unset($imageworks['datadir']);
		unset($imageworks['route']);

		// Parse the existing URL and its query parameters
		$parsedUrl = parse_url($api);

		if (!isset($parsedUrl['path'])) {
			return '';
		}

		$existingParams = [];
		if (isset($parsedUrl['query'])) {
			parse_str($parsedUrl['query'], $existingParams);
		}

		// Merge the existing parameters with the new imageworks
		$imageworks = array_merge($existingParams, $imageworks);

		// Reconstruct the URL without the original query string, and append the new query string
		$api = $parsedUrl['path'] . '?' . http_build_query($imageworks);

		return $api;
	}

	// Get an alt tag for an image
	/** @param array<string,string> $options */
	public function alt(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		$image = $this->data($options['collection'], $id, $options['property']);

		if (!is_array($image) || !key_exists('alt', $image)) {
			return '';
		}

		return $image['alt'];
	}

	// Get an alt tag for a gallery image
	/** @param array<string,string> $options */
	public function galleryAlt(string $id, string $filename, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$image = $this->galleryImageData($id, $filename, $options);

		if (!is_array($image) || !key_exists('alt', $image)) {
			return '';
		}

		return $image['alt'];
	}
}
