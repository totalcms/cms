<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use Intervention\Image\Interfaces\ImageInterface;

/**
 * Maps the image's luminance onto a gradient between two colors. The colors
 * come from the render context (`duo`), which ImageWorks resolves from the
 * `duo` parameter; without them it is plain black to white.
 */
final readonly class DuotoneFilter implements ImageFilter
{
	public function label(): string
	{
		return 'imageworks.filter.duotone';
	}

	public function apply(ImageInterface $image, FilterContext $context): ImageInterface
	{
		[$shadow, $highlight] = $context->duo ?? ['000000', 'ffffff'];
		$step = new FilterStep('gradient-map', [$shadow, $highlight]);

		return RecipeRenderers::for($image)->apply($image, [$step], $context);
	}

	public function version(): string
	{
		return '1';
	}
}
