<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Twig sub-adapter for file and media access.
 *
 * Accessed in Twig as `cms.media.*`.
 */
readonly class MediaTwigAdapter
{
	private LoggerInterface $logger;

	public function __construct(
		private ObjectFetcher $objectFetcher,
		private Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('twig.log')->createLogger('twig');
	}

	/**
	 * Get the image path for an image property.
	 *
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	public function imagePath(string|array|null $idOrObject, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		if (in_array($idOrObject, [null, '', []], true)) {
			return '';
		}

		$collection = $options['collection'];
		// `property` can be a dot-notation path for nested images:
		//   - top-level:    "image"
		//   - card child:   "mycard.image"
		//   - deck child:   "mydeck.item-3.image"
		// First segment is the URL/storage root property; the rest is the subpath.
		$propertyPath              = (string)$options['property'];
		[$rootProperty, $segments] = self::splitDottedProperty($propertyPath);

		$imageworks = $this->resolvePresetFormat($imageworks);

		if (is_array($idOrObject)) {
			$id = $idOrObject['id'] ?? '';
			if ($id === '') {
				return '';
			}

			$image = self::descendDottedPath($idOrObject, $rootProperty, $segments);
			if (!is_array($image) || !array_key_exists('size', $image) || $image['size'] === 0) {
				return '';
			}

			return self::buildImageworksAPI($this->config->api, $id, $image, $imageworks, $options);
		}

		$image = $this->fetchData($collection, $idOrObject, $rootProperty);
		foreach ($segments as $segment) {
			if (!is_array($image)) {
				return '';
			}
			$image = $image[$segment] ?? null;
		}
		if (!is_array($image) || !array_key_exists('size', $image) || $image['size'] === 0) {
			return '';
		}

		return self::buildImageworksAPI($this->config->api, $idOrObject, $image, $imageworks, $options);
	}

	/**
	 * Split a dotted property path into [rootProperty, ...subsegments].
	 *
	 * @return array{0: string, 1: array<int, string>}
	 */
	public static function splitDottedProperty(string $property): array
	{
		if (!str_contains($property, '.')) {
			return [$property, []];
		}
		$parts = explode('.', $property);
		$root  = array_shift($parts);

		return [$root, $parts];
	}

	/**
	 * Descend through `$data[$root][$segments[0]][$segments[1]]...` and return
	 * the final value (or null if any step misses).
	 *
	 * @param array<string,mixed> $data
	 * @param array<int,string>   $segments
	 */
	public static function descendDottedPath(array $data, string $root, array $segments): mixed
	{
		$cursor = $data[$root] ?? null;
		foreach ($segments as $segment) {
			if (!is_array($cursor)) {
				return null;
			}
			$cursor = $cursor[$segment] ?? null;
		}

		return $cursor;
	}

	/**
	 * Get the image path for a gallery image.
	 *
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	public function galleryPath(string|array|null $idOrObject, string|int|null $name, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if (in_array($idOrObject, [null, '', []], true) || $name === null || $name === '') {
			return '';
		}

		$imageworks = $this->resolvePresetFormat($imageworks);

		if (is_array($idOrObject)) {
			$id = $idOrObject['id'] ?? '';
			if ($id === '') {
				return '';
			}
		} else {
			$id = $idOrObject;
		}

		$image = $this->galleryImageData($idOrObject, $name, $options) ?? [];

		$imageName = is_numeric($name) ? (string)($image['name'] ?? '') : $name;

		return self::buildImageworksGalleryAPI($this->config->api, $id, $imageName, $image, $imageworks, $options);
	}

	/**
	 * Get an image object from inside a gallery by its name.
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>|null
	 */
	public function galleryImageData(string|array $idOrObject, string|int $name, array $options = []): ?array
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if (is_array($idOrObject)) {
			$gallery = $idOrObject[$options['property']] ?? null;
		} else {
			$gallery = $this->fetchData($options['collection'], $idOrObject, $options['property']);
		}

		if (!is_array($gallery) || $gallery === []) {
			$this->logger->debug("No gallery data found for property '{$options['property']}'", ['idOrObject' => is_string($idOrObject) ? $idOrObject : 'object']);

			return null;
		}

		if (is_numeric($name)) {
			$index  = (int)$name - 1;
			$values = array_values($gallery);

			return $values[$index] ?? null;
		}

		$values = array_values($gallery);
		if ($name === 'first') {
			return $values[0] ?? null;
		}
		if ($name === 'last') {
			return $values[count($values) - 1] ?? null;
		}
		if ($name === 'random') {
			return $values[array_rand($values)] ?? null;
		}
		if ($name === 'featured') {
			$featured = array_filter($values, fn (array $img): bool => !empty($img['featured']));
			if ($featured !== []) {
				return $featured[array_rand($featured)];
			}

			return $values[array_rand($values)] ?? null;
		}

		foreach ($gallery as $image) {
			if ($image['name'] === $name) {
				return $image;
			}
		}

		return null;
	}

	/**
	 * Get depot files.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return array<array<string,string|int>>
	 */
	public function depot(string $id, array $options = []): array
	{
		$options = array_merge([
			'collection' => 'depot',
			'property'   => 'depot',
		], $options);

		$files = $this->fetchData($options['collection'], $id, $options['property']);

		return is_array($files) ? $files : [];
	}

	/**
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	public function download(string|array $idOrObject, array $options = []): string
	{
		$collection = $options['collection'] ?? 'file';
		$property   = $options['property'] ?? 'file';
		$password   = $options['pwd'] ?? '';

		$id = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		if ($id === '') {
			return '';
		}

		// Dotted property (`mycard.file`, `mydeck.one.file`) becomes slash
		// segments in the URL — the dispatch action walks the path and serves
		// the nested file.
		$propertyPath = str_replace('.', '/', (string)$property);

		$url = "{$this->config->api}/download/{$collection}/{$id}/{$propertyPath}";

		if (!empty($password) && !$this->isEncryptedPassword($password)) {
			$password = Cipher::encrypt($password);
		}

		if (!empty($password)) {
			$url .= '?pwd=' . urlencode((string)$password);
		}

		return $url;
	}

	/**
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	public function depotDownload(string|array $idOrObject, string $name, array $options = []): string
	{
		$collection = $options['collection'] ?? 'depot';
		$property   = $options['property'] ?? 'depot';
		$path       = $options['path'] ?? '';
		$password   = $options['pwd'] ?? '';

		$id = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		if ($id === '') {
			return '';
		}

		if (str_contains($name, '/')) {
			$pathinfo = pathinfo($name);
			$path     = $pathinfo['dirname'];
			$name     = $pathinfo['basename'];
		}

		$url = "{$this->config->api}/download/{$collection}/{$id}/{$property}/" . urlencode($name);

		if (!empty($password) && !$this->isEncryptedPassword($password)) {
			$password = Cipher::encrypt($password);
		}

		$query = http_build_query(array_filter([
			'path' => trim((string)$path, '/'),
			'pwd'  => $password,
		]));

		if ($query !== '') {
			$url .= "?$query";
		}

		return $url;
	}

	/**
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	public function stream(string|array $idOrObject, array $options = []): string
	{
		$collection = $options['collection'] ?? 'file';
		$property   = $options['property'] ?? 'file';
		$password   = $options['pwd'] ?? '';

		$id = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		if ($id === '') {
			return '';
		}

		// Dotted property → URL segments (see `download()` for the rationale).
		$propertyPath = str_replace('.', '/', (string)$property);

		$url = "{$this->config->api}/stream/{$collection}/{$id}/{$propertyPath}";

		if (!empty($password) && !$this->isEncryptedPassword($password)) {
			$password = Cipher::encrypt($password);
		}

		if (!empty($password)) {
			$url .= '?pwd=' . urlencode((string)$password);
		}

		return $url;
	}

	/**
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	public function depotStream(string|array $idOrObject, string $name, array $options = []): string
	{
		$collection = $options['collection'] ?? 'depot';
		$property   = $options['property'] ?? 'depot';
		$path       = $options['path'] ?? '';
		$password   = $options['pwd'] ?? '';

		$id = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		if ($id === '') {
			return '';
		}

		if (str_contains($name, '/')) {
			$pathinfo = pathinfo($name);
			$path     = $pathinfo['dirname'];
			$name     = $pathinfo['basename'];
		}

		$url = "{$this->config->api}/stream/{$collection}/{$id}/{$property}/" . urlencode($name);

		if (!empty($password) && !$this->isEncryptedPassword($password)) {
			$password = Cipher::encrypt($password);
		}

		$query = http_build_query(array_filter([
			'path' => trim((string)$path, '/'),
			'pwd'  => $password,
		]));

		if ($query !== '') {
			$url .= "?$query";
		}

		return $url;
	}

	/**
	 * Resolve preset format for URL extension.
	 *
	 * @param array<string,mixed> $imageworks
	 *
	 * @return array<string,mixed>
	 */
	public function resolvePresetFormat(array $imageworks): array
	{
		if (isset($imageworks['fm'])) {
			return $imageworks;
		}

		if (!isset($imageworks['p'])) {
			return $imageworks;
		}

		$presetName = (string)$imageworks['p'];
		$presets    = $this->config->imageworks['presets'] ?? [];

		if (!isset($presets[$presetName])) {
			return $imageworks;
		}

		$preset = $presets[$presetName];

		if (isset($preset['fm'])) {
			$imageworks['fm'] = $preset['fm'];
		}

		return $imageworks;
	}

	/**
	 * Check if a password is already encrypted (base64 encoded).
	 */
	private function isEncryptedPassword(string $password): bool
	{
		if (base64_decode($password, true) === false) {
			return false;
		}

		return strlen($password) > 20;
	}

	/**
	 * Build an ImageWorks API URL.
	 *
	 * @param array<string,mixed> $image
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	public static function buildImageworksAPI(string $api, string $id, array $image, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		$collection = $options['collection'];
		// `property` can be a dot-notation path for nested images. Dots become
		// URL slashes so `mycard.image` → `/coll/id/mycard/image.jpg` and
		// `mydeck.item-3.image` → `/coll/id/mydeck/item-3/image.jpg`. The
		// imageworks fetch route dispatches on data shape at the root property.
		$propertyPath = str_replace('.', '/', (string)$options['property']);

		if ($image === [] || !array_key_exists('name', $image) || $image['name'] === '') {
			return '';
		}

		$type = strtolower(pathinfo((string)$image['name'], PATHINFO_EXTENSION));
		if (array_key_exists('fm', $imageworks)) {
			$type = $imageworks['fm'];
			unset($imageworks['fm']);
		}
		$type = in_array($type, GlideFactory::IMG_TYPES) ? $type : 'jpg';

		$api .= "/imageworks/$collection/$id/$propertyPath.$type";

		$imageworks['cache'] = self::resolveCacheToken($image);

		unset($imageworks['datadir']);
		unset($imageworks['route']);

		$parsedUrl = parse_url($api);

		if (!isset($parsedUrl['path'])) {
			return '';
		}

		$existingParams = [];
		if (isset($parsedUrl['query'])) {
			parse_str($parsedUrl['query'], $existingParams);
		}

		$imageworks = array_merge($existingParams, $imageworks);

		return $parsedUrl['path'] . '?' . http_build_query($imageworks);
	}

	/**
	 * Pick the cache-busting token for an image URL.
	 *
	 * Prefers the deterministic content hash written on save; falls back to
	 * a reversed uploadDate for records that predate the hash field.
	 *
	 * @param array<string,mixed> $image
	 */
	private static function resolveCacheToken(array $image): string
	{
		if (!empty($image['hash']) && is_string($image['hash'])) {
			return $image['hash'];
		}

		return strrev((string)preg_replace('/\W+/', '', (string)($image['uploadDate'] ?? '')));
	}

	/**
	 * Build an ImageWorks Gallery API URL.
	 *
	 * @param array<string,mixed> $image
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	public static function buildImageworksGalleryAPI(string $baseapi, string $id, string $name, array $image, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$collection = $options['collection'];
		$property   = $options['property'];

		$api                 = $baseapi . "/imageworks/$collection/$id/$property/$name";
		$dynamicRoutes       = ['first', 'last', 'random', 'featured'];
		$isDynamic           = in_array($name, $dynamicRoutes);

		if ($isDynamic) {
			// Random varies per request by design — keep uniqid so browsers and
			// CDNs never cache a single pick. For first/last/featured the
			// resolved image is known, so use its deterministic cache token.
			$imageworks['cache'] = $name === 'random' || $image === []
				? uniqid()
				: self::resolveCacheToken($image);
		} else {
			if (!array_key_exists('uploadDate', $image) && empty($image['hash'])) {
				return '';
			}

			$type = strtolower(pathinfo((string)$image['name'], PATHINFO_EXTENSION));
			if (array_key_exists('fm', $imageworks)) {
				$type = $imageworks['fm'];
				unset($imageworks['fm']);
			}
			$type     = in_array($type, GlideFactory::IMG_TYPES) ? $type : 'jpg';
			$basename = pathinfo($name)['filename'];

			$api = $baseapi . "/imageworks/$collection/$id/$property/$basename.$type";

			$imageworks['cache'] = self::resolveCacheToken($image);
		}

		unset($imageworks['datadir']);
		unset($imageworks['route']);

		$parsedUrl = parse_url($api);

		if (!isset($parsedUrl['path'])) {
			return '';
		}

		$existingParams = [];
		if (isset($parsedUrl['query'])) {
			parse_str($parsedUrl['query'], $existingParams);
		}

		$imageworks = array_merge($existingParams, $imageworks);

		return $parsedUrl['path'] . '?' . http_build_query($imageworks);
	}

	/**
	 * Fetch a data property from an object.
	 */
	private function fetchData(string $collection, string $id, string $property): mixed
	{
		try {
			$object = $this->objectFetcher->fetchObject($collection, $id);
		} catch (\Exception $e) {
			$this->logger->warning("Object '{$id}' not found in collection '{$collection}'", ['error' => $e->getMessage()]);

			return '';
		}

		$data = $object->toArray();

		if (array_key_exists($property, $data)) {
			return $data[$property];
		}

		$this->logger->debug("Property '{$property}' not found on object '{$id}' in collection '{$collection}'");

		return '';
	}
}
