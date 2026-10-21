<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

/**
 * Builds the public ImageWorks URLs for an image or gallery image: route,
 * output format, cache token and the processing parameters.
 *
 * Pure functions of their arguments, so the admin form fields, upload
 * previews and the Twig renderers all call them statically.
 */
final class ImageworksUrl
{
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

		[$type, $imageworks] = self::imageType((string)$image['name'], $imageworks);
		$imageworks['cache'] = self::resolveCacheToken($image);

		return self::imageworksUrl($api . "/imageworks/$collection/$id/$propertyPath.$type", $imageworks);
	}

	/**
	 * The output format for an ImageWorks URL: the `fm` parameter when given
	 * (consumed from the parameters), else the source extension, falling back
	 * to jpg for anything Glide cannot write.
	 *
	 * @param array<string,string|int> $imageworks
	 *
	 * @return array{0: string, 1: array<string,string|int>}
	 */
	private static function imageType(string $name, array $imageworks): array
	{
		$type = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (array_key_exists('fm', $imageworks)) {
			$type = (string)$imageworks['fm'];
			unset($imageworks['fm']);
		}

		return [in_array($type, GlideFactory::IMG_TYPES) ? $type : 'jpg', $imageworks];
	}

	/**
	 * Append the ImageWorks parameters to a route, merging any query the base
	 * API already carried (the Stacks preview server adds one). `datadir` and
	 * `route` are internal and never reach the URL.
	 *
	 * @param array<string,string|int> $imageworks
	 */
	private static function imageworksUrl(string $api, array $imageworks): string
	{
		unset($imageworks['datadir'], $imageworks['route']);

		$parsedUrl = parse_url($api);
		if (!isset($parsedUrl['path'])) {
			return '';
		}

		$existingParams = [];
		if (isset($parsedUrl['query'])) {
			parse_str($parsedUrl['query'], $existingParams);
		}

		return $parsedUrl['path'] . '?' . http_build_query(array_merge($existingParams, $imageworks));
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

			[$type, $imageworks] = self::imageType((string)$image['name'], $imageworks);
			$basename            = pathinfo($name)['filename'];

			$api = $baseapi . "/imageworks/$collection/$id/$property/$basename.$type";

			$imageworks['cache'] = self::resolveCacheToken($image);
		}

		return self::imageworksUrl($api, $imageworks);
	}
}
