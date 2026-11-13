<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use Intervention\Image\Interfaces\ImageInterface;

/**
 * Applies the steps of a FilterRecipe to an image on one driver.
 */
interface RecipeRenderer
{
	/**
	 * @param list<FilterStep> $steps
	 */
	public function apply(ImageInterface $image, array $steps, FilterContext $context): ImageInterface;
}
