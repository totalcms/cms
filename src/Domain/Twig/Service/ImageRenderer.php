<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\ImageWorks\Service\ImageDimensionCalculator;
use TotalCMS\Domain\ImageWorks\Service\PictureCandidates;
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
	/** Alias kept for callers that read the defaults from here. */
	public const PICTURE_DEFAULTS = PictureCandidates::DEFAULTS;

	public function __construct(
		private readonly MediaTwigAdapter $media,
		private readonly DataTwigAdapter $data,
		private readonly ?Config $config = null,
	) {
	}

	/**
	 * The attributes that discourage saving an image — no drag-to-desktop,
	 * no context menu — or none. `protect` in the call's options decides;
	 * without it, `imageworks.protect` in settings does; without that, the
	 * image is protected. Shared with the gallery renderer so one switch
	 * covers every `<img>` Total CMS writes.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,string>
	 */
	public static function protection(array $options, ?Config $config): array
	{
		$protect = $options['protect'] ?? $config?->imageworks['protect'] ?? true;

		return filter_var($protect, FILTER_VALIDATE_BOOLEAN)
			? ['draggable' => 'false', 'oncontextmenu' => 'return false;']
			: [];
	}

	/**
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $imageworks
	 * `alt` in the options replaces the stored alt text; an explicit empty
	 * string marks the image decorative. Without it the fallback chain in
	 * {@see altFromImageData()} applies.
	 * @param array<string,mixed> $options collection, property, loading, class, alt, protect
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
			'alt'           => array_key_exists('alt', $options) ? (string)$options['alt'] : $this->alt($idOrObject, $options),
			'width'         => $dimensions['width'],
			'height'        => $dimensions['height'],
			'class'         => $options['class'] ?? null,
			'loading'       => $options['loading'] ?? null,
		] + self::protection($options, $this->config));

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
	 * in settings, else {@see PictureCandidates::DEFAULTS}.
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
	 * A `crop` rect in `$imageworks` (`width,height,x,y`) is the source
	 * every candidate is cut from — Glide crops before it sizes — so the
	 * candidate widths, the `<img>` dimensions and every `w` descriptor are
	 * taken from the rect, not the upload. `alt` in the options works as in
	 * {@see image()} and applies to the light `<img>` only.
	 * @param array<string,mixed> $options widths, formats, sizes, dark, darkProperty, alt, protect, plus collection, property, loading, class
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

		$picture = PictureCandidates::settings($this->config, $options);
		$widths  = $picture['widths'];
		$formats = $picture['formats'];
		$sizes   = $picture['sizes'];

		// The image's own format is what the <img> fallback serves; a <source>
		// in that same format would be redundant.
		$native = PictureCandidates::nativeFormat($image);

		$base = $imageworks;
		unset($base['fm']);

		// `w` in the transforms is a ceiling for the candidates, not a candidate.
		$ceilinged  = $base;
		$candidates = PictureCandidates::widths($widths, $image, $ceilinged);
		unset($base['w']);
		if ($candidates === []) {
			return '';
		}

		// Dark-scheme sources lead: a browser takes the first <source> whose
		// media and type both match, so these must come before the unqualified
		// light set. The dark image has no <img> of its own to fall back to, so
		// its own stored format is appended as the last-resort candidate.
		$sources = '';
		$dark    = $this->darkVariant($idOrObject, $options);
		if ($dark !== null) {
			[$darkTarget, $darkImage, $darkOptions] = $dark;
			$darkCandidates                         = PictureCandidates::widths($widths, $darkImage, $ceilinged);
			$darkNative                             = PictureCandidates::nativeFormat($darkImage);
			foreach ($darkCandidates === [] ? [] : array_unique([...$formats, $darkNative]) as $format) {
				$sources .= HTMLUtils::inlineElement('source', [
					'media'  => '(prefers-color-scheme: dark)',
					'type'   => PictureCandidates::MIME[$format],
					'srcset' => $this->srcset($darkTarget, $darkImage, $base, $darkOptions, $darkCandidates, $format),
					'sizes'  => $sizes,
				]);
			}
		}

		if ($native !== 'gif') {
			foreach ($formats as $format) {
				if ($format === $native) {
					continue;
				}
				$sources .= HTMLUtils::inlineElement('source', [
					'type'   => PictureCandidates::MIME[$format],
					'srcset' => $this->srcset($idOrObject, $image, $base, $options, $candidates, $format),
					'sizes'  => $sizes,
				]);
			}
		}

		$largest    = $candidates[array_key_last($candidates)];
		$dimensions = ImageDimensionCalculator::calculateFromImageData($image, $base + ['w' => $largest]);

		$img = HTMLUtils::inlineElement('img', [
			'src'           => $this->media->imagePath($idOrObject, $base + ['w' => $largest, 'fm' => $native], $options),
			'srcset'        => $this->srcset($idOrObject, $image, $base, $options, $candidates, $native),
			'sizes'         => $sizes,
			'alt'           => array_key_exists('alt', $options) ? (string)$options['alt'] : self::altFromImageData($image),
			'width'         => $dimensions['width'],
			'height'        => $dimensions['height'],
			'class'         => $options['class'] ?? null,
			'loading'       => $options['loading'] ?? null,
		] + self::protection($options, $this->config));

		$html = HTMLUtils::element('picture', $sources . $img);

		if (!empty($image['link'])) {
			return HTMLUtils::element('a', $html, ['href' => $image['link']]);
		}

		return $html;
	}

	/**
	 * One `srcset` of ImageWorks candidates for `$image` in `$format`, each
	 * described by the width ImageWorks will actually deliver (two requested
	 * widths can clamp to one delivered width, and collapse into one entry).
	 *
	 * @param string|array<string,mixed> $target what imagePath() resolves: the object or its id
	 * @param array<string,mixed> $image
	 * @param array<string,string|int> $base
	 * @param array<string,mixed> $options
	 * @param list<int> $candidates
	 */
	private function srcset(string|array $target, array $image, array $base, array $options, array $candidates, string $format): string
	{
		$parts = [];
		$seen  = [];
		foreach ($candidates as $w) {
			$params    = $base + ['w' => $w, 'fm' => $format];
			$delivered = ImageDimensionCalculator::calculateFromImageData($image, $params)['width'];
			$delivered = $delivered > 0 ? $delivered : $w;
			if (isset($seen[$delivered])) {
				continue;
			}
			$seen[$delivered] = true;
			$parts[]          = $this->media->imagePath($target, $params, $options) . " {$delivered}w";
		}

		return implode(', ', $parts);
	}

	/**
	 * The image a dark color scheme should show, from `dark` (another object
	 * or id, resolved with the same collection/property as the light image)
	 * or `darkProperty` (a second image property on the same object). Null
	 * when neither is given or the image is missing, empty or a GIF — the
	 * caller then renders the light-only markup it always has.
	 *
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 *
	 * @return array{0: string|array<string,mixed>, 1: array<string,mixed>, 2: array<string,mixed>}|null
	 */
	private function darkVariant(string|array $idOrObject, array $options): ?array
	{
		$target      = $idOrObject;
		$darkOptions = $options;
		if (!in_array($options['dark'] ?? null, [null, '', []], true)) {
			$target = $options['dark'];
		} elseif (is_string($options['darkProperty'] ?? null) && $options['darkProperty'] !== '') {
			$darkOptions['property'] = $options['darkProperty'];
		} else {
			return null;
		}

		if (!is_string($target) && !is_array($target)) {
			return null;
		}

		$image = $this->imageData($target, $darkOptions);
		if ($image === [] || (int)($image['size'] ?? 0) === 0 || PictureCandidates::nativeFormat($image) === 'gif') {
			return null;
		}

		return [$target, $image, $darkOptions];
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
