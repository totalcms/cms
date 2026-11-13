<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

/**
 * One validated step of a filter recipe. Built by FilterRecipe, which has
 * already checked the arguments and normalized hex colors to 6 lowercase digits.
 */
final readonly class FilterStep
{
	public const OPS = [
		'brightness',
		'contrast',
		'saturate',
		'grayscale',
		'sepia',
		'hue-rotate',
		'fade',
		'overlay',
		'vignette',
		'gradient-map',
		'grain',
		'leak',
	];

	/**
	 * Ops computed in PHP pixel by pixel on both drivers: Imagick has no native
	 * call that matches their math, so `filterMaxPixels` applies to them there too.
	 */
	public const PHP_PASS_OPS = [
		'vignette',
		'grain',
		'leak',
	];

	/** Ops that are a per-pixel linear color matrix and can be folded into one ColorMatrix. */
	private const LINEAR_OPS = [
		'brightness',
		'contrast',
		'saturate',
		'grayscale',
		'sepia',
		'hue-rotate',
		'fade',
	];

	/**
	 * @param list<float|string> $args
	 */
	public function __construct(
		public string $op,
		public array $args,
	) {
	}

	public function isLinear(): bool
	{
		return in_array($this->op, self::LINEAR_OPS, true);
	}
}
