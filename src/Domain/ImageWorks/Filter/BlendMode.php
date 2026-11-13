<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use InvalidArgumentException;

/**
 * Per-channel blend formulas from W3C Compositing and Blending Level 1.
 * Channels are floats in 0-1; `$base` is the image, `$source` the overlay color.
 */
final class BlendMode
{
	public static function blend(string $mode, float $base, float $source): float
	{
		return match ($mode) {
			'normal'     => $source,
			'multiply'   => $base * $source,
			'screen'     => self::screen($base, $source),
			'overlay'    => self::hardLight($source, $base),
			'soft-light' => self::softLight($base, $source),
			default      => throw new InvalidArgumentException(sprintf('Unknown blend mode "%s"', $mode)),
		};
	}

	private static function screen(float $base, float $source): float
	{
		return $base + $source - $base * $source;
	}

	private static function hardLight(float $base, float $source): float
	{
		return $source <= 0.5
			? $base * 2 * $source
			: self::screen($base, 2 * $source - 1);
	}

	private static function softLight(float $base, float $source): float
	{
		if ($source <= 0.5) {
			return $base - (1 - 2 * $source) * $base * (1 - $base);
		}

		$d = $base <= 0.25 ? ((16 * $base - 12) * $base + 4) * $base : sqrt($base);

		return $base + (2 * $source - 1) * ($d - $base);
	}
}
