<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use Intervention\Image\Interfaces\ImageInterface;
use League\Glide\Manipulators\Filter;

/**
 * greyscale, rendered by Glide's own manipulator so the output and the old
 * cache files stay exactly as they were. It has no version.
 */
final readonly class LegacyGlideFilter implements ImageFilter
{
	public function __construct(private string $name)
	{
	}

	public function label(): string
	{
		return match ($this->name) {
			'greyscale' => 'imageworks.black_white',
			default     => 'imageworks.filter.' . $this->name,
		};
	}

	public function apply(ImageInterface $image, FilterContext $context): ImageInterface
	{
		$manipulator = new Filter();
		$manipulator->setParams(['filt' => $this->name]);

		return $manipulator->run($image);
	}

	public function version(): string
	{
		return '';
	}
}
