<?php

declare(strict_types=1);

namespace TestVendor\ImageFilters;

use Intervention\Image\Interfaces\ImageInterface;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\ImageWorks\Filter\FilterContext;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilter;

class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		// A recipe filter
		$context->addImageFilter('test-vendor-warm', [
			'label' => 'Warm',
			'steps' => [['sepia', 0.3], ['saturate', 1.2]],
		]);

		// A class filter
		$context->addImageFilter('test-vendor-grain', new class implements ImageFilter {
			public function label(): string
			{
				return 'Grain';
			}

			public function apply(ImageInterface $image, FilterContext $context): ImageInterface
			{
				return $image;
			}

			public function version(): string
			{
				return '1';
			}
		});

		// Refused by the registry: no vendor prefix. The two above still register.
		$context->addImageFilter('warm', ['label' => 'Unprefixed', 'steps' => [['sepia', 0.3]]]);
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
