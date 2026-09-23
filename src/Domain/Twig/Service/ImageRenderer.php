<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\ImageWorks\Service\ImageDimensionCalculator;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Support\Config;

/**
 * `cms.render.image()`, `cms.render.picture()` and `cms.render.alt()`: one
 * image property (top-level or nested through a dotted path) as an
 * ImageWorks `<img>` or responsive `<picture>`, and the alt-text fallback
 * chain that the gallery renderer shares.
 *
 * RenderTwigAdapter is the Twig-facing entry point and delegates here.
 */
class ImageRenderer
{
	/**
	 * What `picture()` does with no `widths` / `formats` / `sizes` given and
	 * nothing under `imageworks.picture` in settings. The widths span phone
	 * to a 2x desktop hero; the formats are the two every current browser
	 * negotiates, best first; `100vw` is the honest default for `sizes` —
	 * it is what a browser assumes anyway when the attribute is missing,
	 * so stating it changes nothing until a template narrows it.
	 *
	 * @var array{widths: list<int>, formats: list<string>, sizes: string}
	 */
	public const PICTURE_DEFAULTS = [
		'widths'  => [480, 768, 1024, 1440, 1920],
		'formats' => ['avif', 'webp'],
		'sizes'   => '100vw',
	];

	/** ImageWorks output extension → the MIME type a `<source type>` must carry. */
	private const MIME = [
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'pjpg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
	];

	public function __construct(
		private readonly MediaTwigAdapter $media,
		private readonly DataTwigAdapter $data,
		private readonly ?Config $config = null,
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
	 * A responsive `<picture>`: one `<source>` per modern format carrying a
	 * `srcset` of ImageWorks candidates, then an `<img>` fallback in the
	 * image's own format that carries the same `srcset` — so a browser that
	 * ignores `<picture>` still picks a sensible size.
	 *
	 * `$imageworks` is the base transform applied to every candidate
	 * (`h`, `fit`, `q`, a preset …). `w` there is a ceiling: no candidate
	 * wider than it is emitted. The candidate widths, the formats and the
	 * `sizes` attribute come from `$options`, else from `imageworks.picture`
	 * in settings, else {@see PICTURE_DEFAULTS}.
	 *
	 * Each `w` descriptor is the width ImageWorks will actually deliver, not
	 * the one requested — Glide never upscales, so a candidate wider than the
	 * source would resolve to the source's own width and lie to the browser
	 * about its size. Candidates past the source are dropped and the source
	 * width itself becomes the top candidate. A GIF gets no `<source>`
	 * children at all: re-encoding to a still format would drop animation.
	 *
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options widths, formats, sizes, plus collection, property, loading, class
	 */
	public function picture(string|array|null $idOrObject, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
			'loading'    => 'lazy',
		], $options);

		if (in_array($idOrObject, [null, '', []], true)) {
			return '';
		}

		$image = $this->imageData($idOrObject, $options);
		if ($image === [] || (int)($image['size'] ?? 0) === 0) {
			return '';
		}

		$settings = $this->config?->imageworks['picture'] ?? [];
		$widths   = $this->intList($options['widths'] ?? $settings['widths'] ?? self::PICTURE_DEFAULTS['widths']);
		$formats  = $this->formatList($options['formats'] ?? $settings['formats'] ?? self::PICTURE_DEFAULTS['formats']);
		$sizes    = (string)($options['sizes'] ?? $settings['sizes'] ?? self::PICTURE_DEFAULTS['sizes']);

		// The image's own format is what the <img> fallback serves; a <source>
		// in that same format would be redundant.
		$native = strtolower(pathinfo((string)($image['name'] ?? ''), PATHINFO_EXTENSION));
		$native = isset(self::MIME[$native]) ? $native : 'jpg';

		$base = $imageworks;
		unset($base['fm']);
		$ceiling = isset($base['w']) ? (int)$base['w'] : null;
		unset($base['w']);

		$candidates = $this->candidateWidths($widths, (int)($image['width'] ?? 0), $ceiling);
		if ($candidates === []) {
			return '';
		}

		$srcset = function (string $format) use ($idOrObject, $base, $options, $image, $candidates): string {
			$parts = [];
			$seen  = [];
			foreach ($candidates as $w) {
				$params    = $base + ['w' => $w, 'fm' => $format];
				$delivered = ImageDimensionCalculator::calculateFromImageData($image, $params)['width'];
				$delivered = $delivered > 0 ? $delivered : $w;
				// Two requested widths can clamp to one delivered width.
				if (isset($seen[$delivered])) {
					continue;
				}
				$seen[$delivered] = true;
				$parts[]          = $this->media->imagePath($idOrObject, $params, $options) . " {$delivered}w";
			}

			return implode(', ', $parts);
		};

		$sources = '';
		if ($native !== 'gif') {
			foreach ($formats as $format) {
				if ($format === $native) {
					continue;
				}
				$sources .= HTMLUtils::inlineElement('source', [
					'type'   => self::MIME[$format],
					'srcset' => $srcset($format),
					'sizes'  => $sizes,
				]);
			}
		}

		$largest    = $candidates[array_key_last($candidates)];
		$dimensions = ImageDimensionCalculator::calculateFromImageData($image, $base + ['w' => $largest]);

		$img = HTMLUtils::inlineElement('img', [
			'src'           => $this->media->imagePath($idOrObject, $base + ['w' => $largest, 'fm' => $native], $options),
			'srcset'        => $srcset($native),
			'sizes'         => $sizes,
			'alt'           => self::altFromImageData($image),
			'width'         => $dimensions['width'],
			'height'        => $dimensions['height'],
			'class'         => $options['class'] ?? null,
			'loading'       => $options['loading'] ?? null,
			'draggable'     => 'false',
			'oncontextmenu' => 'return false;',
		]);

		$html = HTMLUtils::element('picture', $sources . $img);

		if (!empty($image['link'])) {
			return HTMLUtils::element('a', $html, ['href' => $image['link']]);
		}

		return $html;
	}

	/**
	 * Ascending, unique, positive widths no wider than the source or the
	 * caller's ceiling, with the source width itself appended as the top
	 * candidate when it is known and not already listed — the largest
	 * candidate is then the true full-resolution file. With an unknown
	 * source width (a record that predates dimension capture) the list is
	 * used as given; there is nothing to guard against.
	 *
	 * @param list<int> $widths
	 *
	 * @return list<int>
	 */
	private function candidateWidths(array $widths, int $sourceWidth, ?int $ceiling): array
	{
		$max = $sourceWidth > 0 ? $sourceWidth : PHP_INT_MAX;
		if ($ceiling !== null && $ceiling > 0) {
			$max = min($max, $ceiling);
		}

		$out = array_values(array_unique(array_filter($widths, static fn (int $w): bool => $w > 0 && $w <= $max)));
		sort($out);

		if ($sourceWidth > 0 && $max === $sourceWidth && !in_array($sourceWidth, $out, true)) {
			$out[] = $sourceWidth;
		}

		return $out;
	}

	/**
	 * Resolve the image array the same way image() and alt() do: an object
	 * array walked by dotted property, or a record fetched by id.
	 *
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	private function imageData(string|array $idOrObject, array $options): array
	{
		[$rootProp, $segments] = MediaTwigAdapter::splitDottedProperty((string)$options['property']);
		if (is_array($idOrObject)) {
			$image = MediaTwigAdapter::descendDottedPath($idOrObject, $rootProp, $segments);
		} else {
			$image = $this->data->raw($options['collection'], $idOrObject, $rootProp);
			foreach ($segments as $segment) {
				$image = is_array($image) ? ($image[$segment] ?? null) : null;
			}
		}

		return is_array($image) ? $image : [];
	}

	/** @return list<int> */
	private function intList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		return array_values(array_map(static fn (mixed $v): int => (int)$v, array_filter($value, is_numeric(...))));
	}

	/**
	 * Only formats ImageWorks can write and a `<source type>` can name.
	 *
	 * @return list<string>
	 */
	private function formatList(mixed $value): array
	{
		if (!is_array($value)) {
			return [];
		}

		$out = [];
		foreach ($value as $format) {
			$format = strtolower((string)$format);
			if (isset(self::MIME[$format]) && !in_array($format, $out, true)) {
				$out[] = $format;
			}
		}

		return $out;
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

		return self::altFromImageData($this->imageData($idOrObject, $options));
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
