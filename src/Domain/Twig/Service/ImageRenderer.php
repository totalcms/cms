<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\ImageWorks\Service\ImageDimensionCalculator;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;

/**
 * `cms.render.image()` and `cms.render.alt()`: one image property (top-level
 * or nested through a dotted path) as an ImageWorks `<img>`, and the alt-text
 * fallback chain that the gallery renderer shares.
 *
 * RenderTwigAdapter is the Twig-facing entry point and delegates here.
 */
class ImageRenderer
{
	public function __construct(
		private readonly MediaTwigAdapter $media,
		private readonly DataTwigAdapter $data,
	) {
	}

	/**
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	public function image(string|array|null $idOrObject, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
			'loading'    => 'lazy',
		], $options);

		if (in_array($idOrObject, [null, '', []], true)) {
			return '';
		}

		$imagePath = $this->media->imagePath($idOrObject, $imageworks, $options);
		if ($imagePath === '') {
			return '';
		}

		// Resolve image data, descending dotted `property` for card/deck-nested.
		[$rootProp, $segments] = MediaTwigAdapter::splitDottedProperty((string)$options['property']);
		if (is_array($idOrObject)) {
			$image = MediaTwigAdapter::descendDottedPath($idOrObject, $rootProp, $segments) ?? [];
		} else {
			$image = $this->data->raw($options['collection'], $idOrObject, $rootProp);
			foreach ($segments as $segment) {
				$image = is_array($image) ? ($image[$segment] ?? null) : null;
			}
		}
		if (!is_array($image)) {
			$image = [];
		}

		// Calculate dimensions for layout stability (prevents CLS)
		$dimensions = ImageDimensionCalculator::calculateFromImageData($image, $imageworks);

		$html = HTMLUtils::inlineElement('img', [
			'src'           => $imagePath,
			'alt'           => $this->alt($idOrObject, $options),
			'width'         => $dimensions['width'],
			'height'        => $dimensions['height'],
			'class'         => $options['class'] ?? null,
			'loading'       => $options['loading'] ?? null,
			'draggable'     => 'false',
			'oncontextmenu' => 'return false;',
		]);

		if (!empty($image['link'])) {
			return HTMLUtils::element('a', $html, ['href' => $image['link']]);
		}

		return $html;
	}

	/**
	 * Get an alt tag for an image.
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 */
	public function alt(string|array $idOrObject, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		// Resolve image data, descending dotted `property` for card/deck-nested.
		[$rootProp, $segments] = MediaTwigAdapter::splitDottedProperty((string)$options['property']);
		if (is_array($idOrObject)) {
			$image = MediaTwigAdapter::descendDottedPath($idOrObject, $rootProp, $segments);
		} else {
			$image = $this->data->raw($options['collection'], $idOrObject, $rootProp);
			foreach ($segments as $segment) {
				$image = is_array($image) ? ($image[$segment] ?? null) : null;
			}
		}

		if (!is_array($image)) {
			return '';
		}

		return self::altFromImageData($image);
	}

	/**
	 * The alt-text fallback chain for an image array: alt, else the EXIF
	 * title, else the EXIF description, else the file name. Shared by the
	 * single-image and gallery renderers.
	 *
	 * @param array<string,mixed> $image
	 */
	public static function altFromImageData(array $image): string
	{
		if (!empty($image['alt'])) {
			return $image['alt'];
		}
		if (!empty($image['exif']['title'])) {
			return $image['exif']['title'];
		}
		if (!empty($image['exif']['description'])) {
			return $image['exif']['description'];
		}

		return $image['name'] ?? '';
	}
}
