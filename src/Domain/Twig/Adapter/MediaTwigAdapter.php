<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\ImageworksUrl;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Twig sub-adapter for file and media access.
 *
 * Accessed in Twig as `cms.media.*`.
 */
class MediaTwigAdapter
{
	private readonly LoggerInterface $logger;

	/**
	 * Memo of fetchData() results, keyed `collection:id:property`.
	 *
	 * Passing an object ID (rather than the object) to `cms.render.image()` /
	 * `galleryImage()` inside a loop is the single most common performance trap
	 * in user templates: every call re-hydrates the whole object and transforms
	 * it back to an array just to read one property, making an N-image gallery
	 * O(N^2). Callers who already hold the object never reach this path, so
	 * memoizing here closes the gap between the two idioms.
	 *
	 * Scoped to this instance, which the container shares for one render. Only
	 * template rendering touches these helpers, so there is no write to go stale
	 * against mid-request.
	 *
	 * @var array<string,mixed>
	 */
	private array $dataCache = [];

	public function __construct(
		private readonly ObjectFetcher $objectFetcher,
		private readonly Config $config,
		private readonly ImageGenerator $imageGenerator,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Twig);
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

			return ImageworksUrl::buildImageworksAPI($this->config->api, $id, $image, $imageworks, $options);
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

		return ImageworksUrl::buildImageworksAPI($this->config->api, $idOrObject, $image, $imageworks, $options);
	}

	/**
	 * The largest render imageDataUri() embeds, in bytes. A data: URI is
	 * shipped inline with every copy of the page or file it sits in, so it is
	 * for thumbnails, not photos.
	 */
	public const DATA_URI_MAX_BYTES = 65536;

	/**
	 * An image property rendered by ImageWorks and returned as a `data:` URI,
	 * for a file that cannot load anything from outside itself — an SVG
	 * served as an <img>, an email. Takes the same arguments as imagePath();
	 * pass a small `w`/`h`. Top-level image properties only.
	 *
	 * Returns '' when there is no image, the render fails, or it is larger
	 * than DATA_URI_MAX_BYTES, so the caller can fall back to something else.
	 *
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	public function imageDataUri(string|array|null $idOrObject, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		$collection = (string)$options['collection'];
		$property   = (string)$options['property'];
		if (in_array($idOrObject, [null, '', []], true) || str_contains($property, '.')) {
			return '';
		}

		$id    = is_array($idOrObject) ? (string)($idOrObject['id'] ?? '') : $idOrObject;
		$image = is_array($idOrObject) ? ($idOrObject[$property] ?? null) : $this->fetchData($collection, $id, $property);
		if ($id === '' || !is_array($image) || ($image['size'] ?? 0) === 0) {
			return '';
		}

		try {
			$response = $this->imageGenerator->generateImage($collection, $id, $property, $this->resolvePresetFormat($imageworks));
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('imageDataUri: %s/%s/%s did not render: %s', $collection, $id, $property, $e->getMessage()));

			return '';
		}

		$type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
		$body = (string)$response->getBody();
		if ($response->getStatusCode() !== 200 || !str_starts_with($type, 'image/') || $body === '') {
			return '';
		}

		if (strlen($body) > self::DATA_URI_MAX_BYTES) {
			$this->logger->warning(sprintf('imageDataUri: %s/%s/%s renders to %d bytes, over the %d-byte cap; pass a smaller w/h', $collection, $id, $property, strlen($body), self::DATA_URI_MAX_BYTES));

			return '';
		}

		return 'data:' . $type . ';base64,' . base64_encode($body);
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
	 * Resolve the poster URL for a `video` field property: the uploaded
	 * poster (via ImageWorks, so it gets resizing/format negotiation) when
	 * present, else the vendor thumbnail string, else `''`.
	 *
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	public function videoPoster(string|array|null $idOrObject, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'video',
			'property'   => 'video',
		], $options);

		if (in_array($idOrObject, [null, '', []], true)) {
			return '';
		}

		[$rootProperty, $segments] = self::splitDottedProperty((string)$options['property']);

		if (is_array($idOrObject)) {
			$video = self::descendDottedPath($idOrObject, $rootProperty, $segments);
		} else {
			$video = $this->fetchData($options['collection'], $idOrObject, $rootProperty);
			foreach ($segments as $segment) {
				$video = is_array($video) ? ($video[$segment] ?? null) : null;
			}
		}

		if (!is_array($video)) {
			return '';
		}

		$poster    = $video['poster'] ?? null;
		$hasPoster = is_array($poster) && (string)($poster['name'] ?? '') !== '' && (int)($poster['size'] ?? 0) > 0;

		if ($hasPoster) {
			$posterOptions             = $options;
			$posterOptions['property'] = $options['property'] . '.poster';

			return $this->imagePath($idOrObject, $imageworks, $posterOptions);
		}

		return (string)($video['thumbnail'] ?? '');
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

		return ImageworksUrl::buildImageworksGalleryAPI($this->config->api, $id, $imageName, $image, $imageworks, $options);
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
			$featured = array_filter($values, fn (mixed $img): bool => is_array($img) && !empty($img['featured']));
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
		return $this->propertyFileUrl('download', $idOrObject, $options);
	}

	/**
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	public function stream(string|array $idOrObject, array $options = []): string
	{
		return $this->propertyFileUrl('stream', $idOrObject, $options);
	}

	/**
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	public function depotDownload(string|array $idOrObject, string $name, array $options = []): string
	{
		return $this->depotFileUrl('download', $idOrObject, $name, $options);
	}

	/**
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	public function depotStream(string|array $idOrObject, string $name, array $options = []): string
	{
		return $this->depotFileUrl('stream', $idOrObject, $name, $options);
	}

	/**
	 * `/{route}/{collection}/{id}/{property}` for a file property, `route`
	 * being `download` or `stream`. A dotted property (`mycard.file`,
	 * `mydeck.one.file`) becomes slash segments — the dispatch action walks
	 * the path and serves the nested file.
	 *
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	private function propertyFileUrl(string $route, string|array $idOrObject, array $options): string
	{
		$collection = $options['collection'] ?? 'file';
		$property   = $options['property'] ?? 'file';

		$id = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		if ($id === '') {
			return '';
		}

		$propertyPath = str_replace('.', '/', (string)$property);
		$url          = "{$this->config->api}/{$route}/{$collection}/{$id}/{$propertyPath}";

		$password = $this->passwordParam($options['pwd'] ?? '');
		if ($password !== '') {
			$url .= '?pwd=' . urlencode($password);
		}

		return $url;
	}

	/**
	 * `/{route}/{collection}/{id}/{property}/{name}?path=…` for a file in a
	 * depot. A name with folders (`docs/a.pdf`) supplies the path itself.
	 *
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	private function depotFileUrl(string $route, string|array $idOrObject, string $name, array $options): string
	{
		$collection = $options['collection'] ?? 'depot';
		$property   = $options['property'] ?? 'depot';
		$path       = $options['path'] ?? '';

		$id = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		if ($id === '') {
			return '';
		}

		if (str_contains($name, '/')) {
			$pathinfo = pathinfo($name);
			$path     = $pathinfo['dirname'];
			$name     = $pathinfo['basename'];
		}

		$url = "{$this->config->api}/{$route}/{$collection}/{$id}/{$property}/" . urlencode($name);

		$query = http_build_query(array_filter([
			'path' => trim((string)$path, '/'),
			'pwd'  => $this->passwordParam($options['pwd'] ?? ''),
		]));

		return $query !== '' ? "{$url}?{$query}" : $url;
	}

	/** A download password as it travels in the URL: encrypted, or empty when none was given. */
	private function passwordParam(mixed $password): string
	{
		if (empty($password)) {
			return '';
		}

		$password = (string)$password;

		return $this->isEncryptedPassword($password) ? $password : Cipher::encrypt($password);
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
	 * Fetch a data property from an object.
	 */
	private function fetchData(string $collection, string $id, string $property): mixed
	{
		$key = "{$collection}:{$id}:{$property}";

		if (array_key_exists($key, $this->dataCache)) {
			return $this->dataCache[$key];
		}

		try {
			$object = $this->objectFetcher->fetchObject($collection, $id);
		} catch (\Exception $e) {
			$this->logger->warning("Object '{$id}' not found in collection '{$collection}'", ['error' => $e->getMessage()]);

			return $this->dataCache[$key] = '';
		}

		$data = $object->toArrayWithoutPasswords();

		if (array_key_exists($property, $data)) {
			return $this->dataCache[$key] = $data[$property];
		}

		$this->logger->debug("Property '{$property}' not found on object '{$id}' in collection '{$collection}'");

		return $this->dataCache[$key] = '';
	}
}
