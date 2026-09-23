<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Media\Service;

use TotalCMS\Domain\Admin\FormField\ImageField;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;

/**
 * The admin-preview ImageWorks URL for an image that was just saved —
 * identical to the one ImageField / GalleryField render on a page refresh
 * (same dimensions, quality and cache token), so the droplet UI can swap
 * its local thumbnail for the stored image without a reload.
 */
final class UploadPreviewUrl
{
	/**
	 * Empty for non-image property types or when the saved image cannot be
	 * found in the object; never throws — the preview is additive.
	 *
	 * @param string $api The site base path (ImageWorks is a public route, not under /api)
	 */
	public static function build(string $type, string $api, string $collection, string $id, string $property, ?string $subpath, ObjectData $object): string
	{
		if (!in_array($type, ['image', 'gallery'], true)) {
			return '';
		}

		try {
			$imageworks = [
				'w' => ImageField::PREVIEW_WIDTH,
				'h' => ImageField::PREVIEW_HEIGHT,
				'q' => ImageField::PREVIEW_QUALITY,
			];

			$image = $object->toArray()[$property] ?? null;

			if ($type === 'gallery') {
				// GallerySaver appends the new upload, so it is the last entry.
				$image = is_array($image) ? end($image) : null;
				if (!is_array($image) || empty($image['name'])) {
					return '';
				}

				return MediaTwigAdapter::buildImageworksGalleryAPI($api, $id, (string)$image['name'], $image, $imageworks, [
					'collection' => $collection,
					'property'   => $property,
				]);
			}

			// Possibly nested in a card/deck: walk the subpath down to the saved
			// child and mirror it in the dot-notation property path.
			$propertyPath = $property;
			if ($subpath !== null && $subpath !== '') {
				foreach (explode('/', $subpath) as $segment) {
					$image = is_array($image) ? ($image[$segment] ?? null) : null;
				}
				$propertyPath .= '.' . str_replace('/', '.', $subpath);
			}
			if (!is_array($image) || empty($image['name'])) {
				return '';
			}

			return MediaTwigAdapter::buildImageworksAPI($api, $id, $image, $imageworks, [
				'collection' => $collection,
				'property'   => $propertyPath,
			]);
		} catch (\Throwable) {
			return '';
		}
	}
}
