<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

/**
 * Splits a recipe's steps into the units a renderer executes: each run of
 * consecutive linear steps folded into one ColorMatrix, and every nonlinear
 * step on its own. Both drivers walk this list, so they cannot disagree on order.
 */
final class FilterRuns
{
	/**
	 * @param  list<FilterStep>              $steps
	 *
	 * @return list<ColorMatrix|FilterStep>
	 */
	public static function split(array $steps): array
	{
		$runs = [];
		$run  = null;

		foreach ($steps as $step) {
			if ($step->isLinear()) {
				$matrix = LinearSteps::matrix($step);
				$run    = $run?->then($matrix) ?? $matrix;
				continue;
			}
			if ($run instanceof ColorMatrix) {
				$runs[] = $run;
				$run    = null;
			}
			$runs[] = $step;
		}
		if ($run instanceof ColorMatrix) {
			$runs[] = $run;
		}

		return $runs;
	}
}
