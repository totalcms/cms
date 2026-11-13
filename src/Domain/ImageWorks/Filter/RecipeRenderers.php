<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use Imagick;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Picks the recipe renderer that matches the image's driver.
 */
final class RecipeRenderers
{
	public static function for(ImageInterface $image): RecipeRenderer
	{
		return $image->core()->native() instanceof Imagick
			? new ImagickRecipeRenderer()
			: new GdRecipeRenderer();
	}
}
