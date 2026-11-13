<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

/**
 * A filter as the registry holds it: the implementation plus where it came from.
 */
final readonly class RegisteredFilter
{
	/**
	 * @param string $owner 'core', 'config', or the extension id
	 * @param bool   $legacy        True for greyscale, which keeps Glide's output and carry no filtv
	 * @param bool   $pixelLimited  True when `filterMaxPixels` applies (recipes and duotone; not extension classes)
	 */
	public function __construct(
		public string $name,
		public ImageFilter $filter,
		public string $group,
		public string $owner,
		public bool $legacy,
		public bool $pixelLimited,
	) {
	}

	/**
	 * Whether `filterMaxPixels` holds this filter back on a driver. On GD every
	 * pixel-limited filter is a pass in PHP over each pixel. Imagick runs the
	 * color steps natively, so only recipes with a step that is still a PHP
	 * pass there (FilterStep::PHP_PASS_OPS: vignette, grain, leak) need the limit.
	 */
	public function isPixelLimitedOn(string $driver): bool
	{
		if (!$this->pixelLimited || $this->legacy) {
			return false;
		}

		return $driver === 'gd' || ($this->filter instanceof FilterRecipe && $this->filter->hasStep(...FilterStep::PHP_PASS_OPS));
	}

	/**
	 * A filter class an extension registered: code Total CMS does not own,
	 * so the manipulator runs it on a copy and keeps the copy only on success.
	 */
	public function isExtensionClass(): bool
	{
		return !$this->filter instanceof FilterRecipe && !in_array($this->owner, ['core', 'config'], true);
	}
}
