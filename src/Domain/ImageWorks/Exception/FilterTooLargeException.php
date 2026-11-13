<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Exception;

/**
 * A recipe filter asked to run on an output larger than `filterMaxPixels`
 * while GD is the driver. Nothing is drawn and nothing is cached.
 */
final class FilterTooLargeException extends \RuntimeException
{
	public static function forSize(int $width, int $height, int $maxPixels): self
	{
		return new self(sprintf(
			'ImageWorks filter refused: %dx%d is more than the %d pixels GD may filter. Ask for a smaller size.',
			$width,
			$height,
			$maxPixels,
		));
	}
}
