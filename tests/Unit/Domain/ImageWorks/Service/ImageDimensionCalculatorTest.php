<?php

namespace Tests\Unit\Domain\ImageWorks\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ImageWorks\Service\ImageDimensionCalculator;

class ImageDimensionCalculatorTest extends TestCase
{
	public function testReturnsOriginalDimensionsWhenNoParametersProvided(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, []);

		$this->assertEquals(['width' => 1920, 'height' => 1080], $result);
	}

	public function testReturnsZeroWhenOriginalDimensionsAreZero(): void
	{
		$result = ImageDimensionCalculator::calculate(0, 0, ['w' => 500]);

		$this->assertEquals(['width' => 0, 'height' => 0], $result);
	}

	public function testDownscalesWidthMaintainingAspectRatio(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 1500]);

		$this->assertEquals(['width' => 1500, 'height' => 844], $result);
	}

	public function testDownscalesHeightMaintainingAspectRatio(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['h' => 500]);

		$this->assertEquals(['width' => 889, 'height' => 500], $result);
	}

	public function testNeverUpscalesWithWidthOnly(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 3000]);

		$this->assertEquals(['width' => 1920, 'height' => 1080], $result);
	}

	public function testNeverUpscalesWithHeightOnly(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['h' => 2000]);

		$this->assertEquals(['width' => 1920, 'height' => 1080], $result);
	}

	public function testFitsWithinBoundsMaintainingAspectRatio(): void
	{
		// 1920x1080 image should fit within 800x600 bounds
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 800, 'h' => 600]);

		// Should scale down to fit width (800) resulting in 800x450
		$this->assertEquals(['width' => 800, 'height' => 450], $result);
	}

	public function testFitsWithinBoundsWhenHeightIsLimiting(): void
	{
		// 1920x1080 image should fit within 2000x400 bounds
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 2000, 'h' => 400]);

		// Height is limiting factor: 400 / 1080 = 0.37, so 1920 * 0.37 = 711
		$this->assertEquals(['width' => 711, 'height' => 400], $result);
	}

	public function testNeverUpscalesWithBothDimensions(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 3000, 'h' => 2000]);

		$this->assertEquals(['width' => 1920, 'height' => 1080], $result);
	}

	public function testCropModeReturnsExactDimensions(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 500, 'h' => 500, 'fit' => 'crop']);

		$this->assertEquals(['width' => 500, 'height' => 500], $result);
	}

	public function testCropFocalpointModeReturnsExactDimensions(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 800, 'h' => 600, 'fit' => 'crop-focalpoint']);

		$this->assertEquals(['width' => 800, 'height' => 600], $result);
	}

	public function testCropCenterModeReturnsExactDimensions(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 1000, 'h' => 1000, 'fit' => 'crop-center']);

		$this->assertEquals(['width' => 1000, 'height' => 1000], $result);
	}

	public function testCropModeNeverUpscales(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 3000, 'h' => 2000, 'fit' => 'crop']);

		// Even with crop, should not exceed original dimensions
		$this->assertEquals(['width' => 1920, 'height' => 1080], $result);
	}

	public function testStretchModeReturnsExactDimensions(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 500, 'h' => 800, 'fit' => 'stretch']);

		$this->assertEquals(['width' => 500, 'height' => 800], $result);
	}

	public function testStretchModeNeverUpscales(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 3000, 'h' => 2000, 'fit' => 'stretch']);

		// Even with stretch, should not exceed original dimensions
		$this->assertEquals(['width' => 1920, 'height' => 1080], $result);
	}

	public function testPortraitImageWithWidthConstraint(): void
	{
		// 1080x1920 portrait image
		$result = ImageDimensionCalculator::calculate(1080, 1920, ['w' => 500]);

		$this->assertEquals(['width' => 500, 'height' => 889], $result);
	}

	public function testPortraitImageWithHeightConstraint(): void
	{
		// 1080x1920 portrait image
		$result = ImageDimensionCalculator::calculate(1080, 1920, ['h' => 1000]);

		$this->assertEquals(['width' => 563, 'height' => 1000], $result);
	}

	public function testSquareImageMaintainsAspectRatio(): void
	{
		$result = ImageDimensionCalculator::calculate(1000, 1000, ['w' => 500]);

		$this->assertEquals(['width' => 500, 'height' => 500], $result);
	}

	public function testCalculateFromImageDataArray(): void
	{
		$imageData = [
			'name'   => 'test.jpg',
			'width'  => 1920,
			'height' => 1080,
		];

		$result = ImageDimensionCalculator::calculateFromImageData($imageData, ['w' => 800]);

		$this->assertEquals(['width' => 800, 'height' => 450], $result);
	}

	public function testCalculateFromImageDataWithMissingDimensions(): void
	{
		$imageData = [
			'name' => 'test.jpg',
		];

		$result = ImageDimensionCalculator::calculateFromImageData($imageData, ['w' => 800]);

		$this->assertEquals(['width' => 0, 'height' => 0], $result);
	}

	public function testVerySmallImage(): void
	{
		// 100x100 image requested at 1920x1080 should stay 100x100
		$result = ImageDimensionCalculator::calculate(100, 100, ['w' => 1920, 'h' => 1080]);

		$this->assertEquals(['width' => 100, 'height' => 100], $result);
	}

	public function testRoundingWorksCorrectly(): void
	{
		// Test that rounding works as expected
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 333]);

		// 333 / 1920 = 0.173... so 1080 * 0.173 = 187.3 -> rounds to 187
		$this->assertEquals(['width' => 333, 'height' => 187], $result);
	}

	public function testExtremeAspectRatioWide(): void
	{
		// Very wide image 3840x400
		$result = ImageDimensionCalculator::calculate(3840, 400, ['w' => 1920]);

		$this->assertEquals(['width' => 1920, 'height' => 200], $result);
	}

	public function testExtremeAspectRatioTall(): void
	{
		// Very tall image 400x3840
		$result = ImageDimensionCalculator::calculate(400, 3840, ['h' => 1920]);

		$this->assertEquals(['width' => 200, 'height' => 1920], $result);
	}

	public function testCropWithOnlyWidth(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['w' => 500, 'fit' => 'crop']);

		// With crop and only width, height should be original (clamped)
		$this->assertEquals(['width' => 500, 'height' => 1080], $result);
	}

	public function testCropWithOnlyHeight(): void
	{
		$result = ImageDimensionCalculator::calculate(1920, 1080, ['h' => 500, 'fit' => 'crop']);

		// With crop and only height, width should be original (clamped)
		$this->assertEquals(['width' => 1920, 'height' => 500], $result);
	}
}
