<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use Intervention\Image\Interfaces\ImageInterface;

/**
 * A photo filter for ImageWorks, selected with the `filt` parameter.
 *
 * Extension authors implement this to ship a filter that needs more than a
 * declarative recipe. The filter name must start with the extension's vendor
 * (`acme-noir` for `acme/photo-pack`); core names never contain a hyphen.
 *
 * Contract:
 * - apply() receives the already-resized image, before any watermark or mark
 *   layers, and returns the filtered image (it may modify and return the same
 *   instance). Keep the alpha channel: a filtered transparent PNG must stay
 *   transparent.
 * - Work on both drivers named by FilterContext::$driver ('gd' and 'imagick');
 *   many hosts only have GD.
 * - A filter that throws is logged against the extension and skipped; the
 *   image renders as it was before the filter ran, even if apply() changed it.
 * - An animation reaches apply() with all its frames; filter each one.
 * - Strength (`filtamt`) is applied by ImageWorks, not by the filter.
 */
interface ImageFilter
{
	/**
	 * Human-readable name shown in the admin ImageWorks tool.
	 */
	public function label(): string;

	/**
	 * Apply the filter to the image and return the result.
	 */
	public function apply(ImageInterface $image, FilterContext $context): ImageInterface;

	/**
	 * A string that changes whenever the filter's output changes (a version
	 * number, or a hash of its settings). It is part of the cache key, so
	 * bumping it retires files rendered by the old look.
	 */
	public function version(): string;
}
