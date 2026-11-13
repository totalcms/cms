<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Filter\ColorMatrix;

// Reference values are the W3C Filter Effects Level 1 shorthand matrices
// applied to sRGB values, the same math the CSS filter functions use.

/**
 * @param array{int,int,int} $actual
 * @param array{int,int,int} $expected
 */
function expectChannelsNear(array $actual, array $expected, int $tolerance = 1): void
{
	foreach ($expected as $i => $value) {
		expect(abs($actual[$i] - $value))->toBeLessThanOrEqual($tolerance, "channel {$i}: got {$actual[$i]}, expected {$value}");
	}
}

describe('ColorMatrix', function (): void {
	it('leaves a color alone with the identity matrix', function (): void {
		expect(ColorMatrix::identity()->applyTo(12, 99, 250))->toBe([12, 99, 250]);
	});

	it('desaturates fully', function (): void {
		expectChannelsNear(ColorMatrix::saturate(0)->applyTo(255, 0, 0), [54, 54, 54]);
	});

	it('applies sepia', function (): void {
		expectChannelsNear(ColorMatrix::sepia(1)->applyTo(255, 255, 255), [255, 255, 239]);
	});

	it('applies contrast around mid gray', function (): void {
		expectChannelsNear(ColorMatrix::contrast(2)->applyTo(64, 128, 192), [1, 129, 255]);
	});

	it('scales brightness', function (): void {
		expectChannelsNear(ColorMatrix::brightness(1.5)->applyTo(100, 100, 100), [150, 150, 150]);
	});

	it('rotates hue', function (): void {
		expectChannelsNear(ColorMatrix::hueRotate(180)->applyTo(255, 0, 0), [0, 109, 109]);
	});

	it('fades blacks up and leaves white alone', function (): void {
		expectChannelsNear(ColorMatrix::fade(0.2)->applyTo(0, 0, 0), [51, 51, 51]);
		expectChannelsNear(ColorMatrix::fade(0.2)->applyTo(255, 255, 255), [255, 255, 255]);
	});

	it('applies grayscale to luminance', function (): void {
		expectChannelsNear(ColorMatrix::grayscale(1)->applyTo(255, 0, 0), [54, 54, 54]);
		expect(ColorMatrix::grayscale(0)->applyTo(10, 20, 30))->toBe([10, 20, 30]);
	});

	it('clamps to 0-255', function (): void {
		expect(ColorMatrix::brightness(10)->applyTo(200, 100, 0))->toBe([255, 255, 0]);
	});

	it('chains two matrices into the same result as applying them in turn', function (): void {
		$first    = ColorMatrix::brightness(1.5);
		$second   = ColorMatrix::contrast(2);
		$stepwise = $second->applyTo(...$first->applyTo(100, 80, 60));

		expectChannelsNear($first->then($second)->applyTo(100, 80, 60), $stepwise);
	});

	it('exposes 3 rows of 4 floats', function (): void {
		$rows = ColorMatrix::identity()->rows();

		expect($rows)->toHaveCount(3);
		foreach ($rows as $row) {
			expect($row)->toHaveCount(4);
		}
		expect($rows[0])->toBe([1.0, 0.0, 0.0, 0.0]);
	});
});
