<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Support\Config;

/**
 * Rounds a public request's width and height up to a fixed set of sizes, so
 * one image has a few dozen cached variants instead of one per pixel. Without
 * it `w=1` … `w=1920` is 1,920 distinct renders per image per format, every
 * one of them valid.
 *
 * The rungs are the `picture.widths` srcset candidates plus every preset
 * width and height, so the URLs Total CMS builds itself keep their exact
 * size. Anything else rounds up to the next multiple of `sizeStep` pixels,
 * and never past the source, which the generator already clamps to.
 *
 * With both a width and a height, the width snaps and the height scales with
 * it, so a crop keeps the aspect ratio it asked for.
 *
 * Off unless `imageworks.sizeStep` is above 1: 0 (the default) and 1 both
 * serve exact sizes.
 */
final readonly class SizeLadder
{
	/** @param list<int> $rungs */
	public function __construct(private array $rungs, private int $step)
	{
	}

	/** Null when the site turned snapping off. */
	public static function fromConfig(Config $config): ?self
	{
		$imageworks = $config->imageworks;
		$step       = (int)($imageworks['sizeStep'] ?? 0);

		// A step of 1 rounds every size to itself: the same as off.
		if ($step <= 1) {
			return null;
		}

		$rungs = is_array($imageworks['picture']['widths'] ?? null) ? $imageworks['picture']['widths'] : [];
		foreach (is_array($imageworks['presets'] ?? null) ? $imageworks['presets'] : [] as $preset) {
			$rungs[] = is_array($preset) ? ($preset['w'] ?? 0) : 0;
			$rungs[] = is_array($preset) ? ($preset['h'] ?? 0) : 0;
		}

		$rungs = array_values(array_unique(array_filter(array_map(intval(...), $rungs), fn (int $rung): bool => $rung > 0)));

		return new self($rungs, $step);
	}

	/**
	 * @param array<string,mixed> $params
	 *
	 * @return array<string,mixed>
	 */
	public function snap(array $params, ImageData $image): array
	{
		$width  = (int)($params['w'] ?? 0);
		$height = (int)($params['h'] ?? 0);

		if ($width > 0) {
			$snapped = $this->rung($width, $image->width);
			if ($height > 0 && $snapped !== $width) {
				$params['h'] = max(1, (int)round($height * $snapped / $width));
			}
			$params['w'] = $snapped;

			return $params;
		}

		if ($height > 0) {
			$params['h'] = $this->rung($height, $image->height);
		}

		return $params;
	}

	private function rung(int $size, int $source): int
	{
		// At or past the source the generator clamps it to the source anyway.
		if ($source > 0 && $size >= $source) {
			return $size;
		}

		if (in_array($size, $this->rungs, true)) {
			return $size;
		}

		$snapped = (int)(ceil($size / $this->step) * $this->step);

		return $source > 0 ? min($snapped, $source) : $snapped;
	}
}
