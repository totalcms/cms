<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use LogicException;

/**
 * Maps a linear FilterStep to its ColorMatrix.
 */
final class LinearSteps
{
	public static function matrix(FilterStep $step): ColorMatrix
	{
		$value = (float) ($step->args[0] ?? 0.0);

		return match ($step->op) {
			'brightness' => ColorMatrix::brightness($value),
			'contrast'   => ColorMatrix::contrast($value),
			'saturate'   => ColorMatrix::saturate($value),
			'grayscale'  => ColorMatrix::grayscale($value),
			'sepia'      => ColorMatrix::sepia($value),
			'hue-rotate' => ColorMatrix::hueRotate($value),
			'fade'       => ColorMatrix::fade($value),
			default      => throw new LogicException(sprintf('"%s" is not a linear step', $step->op)),
		};
	}
}
