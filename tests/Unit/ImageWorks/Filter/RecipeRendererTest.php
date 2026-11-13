<?php

declare(strict_types=1);

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use TotalCMS\Domain\ImageWorks\Filter\BlendMode;
use TotalCMS\Domain\ImageWorks\Filter\ColorMatrix;
use TotalCMS\Domain\ImageWorks\Filter\NonlinearSteps;
use TotalCMS\Domain\Media\Service\ImagickSupport;

const RECIPE_PATCHES = [[255, 0, 0, 255], [64, 128, 192, 255], [100, 100, 100, 255], [255, 255, 255, 255]];

describe('recipe renderers, linear steps', function (): void {
	dataset('filterDrivers', ['gd', 'imagick']);

	it('matches ColorMatrix on each patch', function (string $driver, array $steps, ColorMatrix $matrix): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 4, 1, RECIPE_PATCHES);

		$result = filterRecipeFor($steps)->apply($image, filterRecipeContext($driver));

		foreach (RECIPE_PATCHES as $x => [$r, $g, $b]) {
			$expected = $matrix->applyTo($r, $g, $b);
			$actual   = $result->pickColor($x, 0)->toArray();
			foreach ($expected as $i => $value) {
				expect(abs($actual[$i] - $value))->toBeLessThanOrEqual(3, "driver {$driver} patch {$x} channel {$i}: got {$actual[$i]}, expected {$value}");
			}
			expect($actual[3])->toBe(255);
		}
	})->with('filterDrivers')->with([
		'saturate 0'     => [[['saturate', 0]], ColorMatrix::saturate(0)],
		'contrast 2'     => [[['contrast', 2]], ColorMatrix::contrast(2)],
		'brightness 1.5' => [[['brightness', 1.5]], ColorMatrix::brightness(1.5)],
		'sepia 1'        => [[['sepia', 1]], ColorMatrix::sepia(1)],
	]);

	test('a transparent pixel stays transparent', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 2, 1, [[200, 100, 50, 0], [200, 100, 50, 128]]);

		$result = filterRecipeFor([['sepia', 1], ['contrast', 1.2]])->apply($image, filterRecipeContext($driver));

		expect(abs($result->pickColor(0, 0)->toArray()[3] - 0))->toBeLessThanOrEqual(2);
		expect(abs($result->pickColor(1, 0)->toArray()[3] - 128))->toBeLessThanOrEqual(2);
	})->with('filterDrivers');

	test('drivers agree', function (): void {
		if (!ImagickSupport::isUsable()) {
			test()->markTestSkipped('Imagick is not usable on this host');
		}
		$pixels = [];
		for ($i = 0; $i < 64; $i++) {
			$pixels[] = [($i % 8) * 36, intdiv($i, 8) * 36, ($i * 4) % 256, 255];
		}
		$steps = [['saturate', 1.3], ['hue-rotate', 30], ['fade', 0.1]];

		$gd      = filterRecipeFor($steps)->apply(filterTestImage('gd', 8, 8, $pixels), filterRecipeContext('gd'));
		$imagick = filterRecipeFor($steps)->apply(filterTestImage('imagick', 8, 8, $pixels), filterRecipeContext('imagick'));

		for ($i = 0; $i < 64; $i++) {
			$a = $gd->pickColor($i % 8, intdiv($i, 8))->toArray();
			$b = $imagick->pickColor($i % 8, intdiv($i, 8))->toArray();
			foreach ([0, 1, 2] as $c) {
				expect(abs($a[$c] - $b[$c]))->toBeLessThanOrEqual(3, "pixel {$i} channel {$c}: gd {$a[$c]} imagick {$b[$c]}");
			}
		}
	});
});

function filterGradientPixels(): array
{
	$pixels = [];
	for ($i = 0; $i < 64; $i++) {
		$pixels[] = [($i % 8) * 36, intdiv($i, 8) * 36, ($i * 4) % 256, 255];
	}

	return $pixels;
}

function expectFilterPixel(array $actual, array $expected, int $tolerance, string $label): void
{
	foreach ($expected as $i => $value) {
		expect(abs($actual[$i] - $value))->toBeLessThanOrEqual($tolerance, "{$label} channel {$i}: got {$actual[$i]}, expected {$value}");
	}
}

describe('recipe renderers, overlay', function (): void {
	dataset('overlayDrivers', ['gd', 'imagick']);

	it('blends the color into the image', function (string $driver, array $steps, int $grey, array $expected, int $tolerance): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 1, 1, [$grey, $grey, $grey, 255]);

		$result = filterRecipeFor($steps)->apply($image, filterRecipeContext($driver));

		expectFilterPixel($result->pickColor(0, 0)->toArray(), $expected, $tolerance, $driver);
	})->with('overlayDrivers')->with([
		'multiply'            => [[['overlay', 'ff0000', 'multiply', 1.0]], 128, [128, 0, 0], 3],
		'screen'              => [[['overlay', 'ff0000', 'screen', 1.0]], 128, [255, 128, 128], 3],
		'soft-light'          => [[['overlay', 'ffffff', 'soft-light', 1.0]], 64, [128, 128, 128], 4],
		'multiply at half'    => [[['overlay', 'ff0000', 'multiply', 0.5]], 128, [128, 64, 64], 3],
		'overlay on dark'     => [[['overlay', '808080', 'overlay', 1.0]], 64, [64, 64, 64], 3],
		'normal at half'      => [[['overlay', 'ffffff', 'normal', 0.5]], 0, [128, 128, 128], 3],
	]);

	test('a transparent pixel stays transparent', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 2, 1, [[200, 100, 50, 0], [200, 100, 50, 128]]);

		$result = filterRecipeFor([['overlay', 'ff9a3c', 'soft-light', 0.5]])->apply($image, filterRecipeContext($driver));

		expect(abs($result->pickColor(0, 0)->toArray()[3] - 0))->toBeLessThanOrEqual(2);
		expect(abs($result->pickColor(1, 0)->toArray()[3] - 128))->toBeLessThanOrEqual(2);
	})->with('overlayDrivers');
});

describe('BlendMode', function (): void {
	it('follows the W3C formulas', function (string $mode, float $base, float $source, float $expected): void {
		expect(BlendMode::blend($mode, $base, $source))->toEqualWithDelta($expected, 0.0001);
	})->with([
		['normal', 0.3, 0.8, 0.8],
		['multiply', 0.5, 0.5, 0.25],
		['screen', 0.5, 0.5, 0.75],
		['overlay', 0.25, 0.6, 0.30],
		['overlay', 0.75, 0.6, 0.80],
		['soft-light', 0.5, 0.0, 0.25],
		['soft-light', 0.25, 1.0, 0.5],
		['soft-light', 0.5, 0.5, 0.5],
	]);
});

describe('recipe renderers, vignette', function (): void {
	dataset('vignetteDrivers', ['gd', 'imagick']);

	it('leaves the center and darkens the corner', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 101, 101, [255, 255, 255, 255]);

		$result = filterRecipeFor([['vignette', 0.5]])->apply($image, filterRecipeContext($driver));

		expectFilterPixel($result->pickColor(50, 50)->toArray(), [255, 255, 255], 1, "{$driver} center");
		expectFilterPixel($result->pickColor(0, 0)->toArray(), [128, 128, 128], 4, "{$driver} corner");
		expectFilterPixel($result->pickColor(100, 100)->toArray(), [128, 128, 128], 4, "{$driver} far corner");
	})->with('vignetteDrivers');

	it('blends toward the given color', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 11, 11, [0, 0, 0, 255]);

		$result = filterRecipeFor([['vignette', 1.0, 'ff0000']])->apply($image, filterRecipeContext($driver));

		expectFilterPixel($result->pickColor(0, 0)->toArray(), [255, 0, 0], 2, $driver);
	})->with('vignetteDrivers');

	test('a transparent pixel stays transparent', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 2, 1, [[200, 100, 50, 0], [200, 100, 50, 128]]);

		$result = filterRecipeFor([['vignette', 0.8]])->apply($image, filterRecipeContext($driver));

		expect(abs($result->pickColor(0, 0)->toArray()[3] - 0))->toBeLessThanOrEqual(2);
		expect(abs($result->pickColor(1, 0)->toArray()[3] - 128))->toBeLessThanOrEqual(2);
	})->with('vignetteDrivers');
});

describe('recipe renderers, gradient-map', function (): void {
	dataset('gradientDrivers', ['gd', 'imagick']);

	it('maps luminance through the stops', function (string $driver, array $stops, array $in, array $expected): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 1, 1, [...$in, 255]);

		$result = filterRecipeFor([['gradient-map', ...$stops]])->apply($image, filterRecipeContext($driver));

		expectFilterPixel($result->pickColor(0, 0)->toArray(), $expected, 3, $driver);
	})->with('gradientDrivers')->with([
		'black to white on grey' => [['000000', 'ffffff'], [128, 128, 128], [128, 128, 128]],
		'duo on black'           => [['1b1f3b', 'f4a261'], [0, 0, 0], [27, 31, 59]],
		'duo on white'           => [['1b1f3b', 'f4a261'], [255, 255, 255], [244, 162, 97]],
		'three stops midpoint'   => [['000000', 'ff0000', 'ffffff'], [128, 128, 128], [255, 0, 0]],
	]);

	test('a transparent pixel stays transparent', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 2, 1, [[200, 100, 50, 0], [200, 100, 50, 128]]);

		$result = filterRecipeFor([['gradient-map', '1b1f3b', 'f4a261']])->apply($image, filterRecipeContext($driver));

		expect(abs($result->pickColor(0, 0)->toArray()[3] - 0))->toBeLessThanOrEqual(2);
		expect(abs($result->pickColor(1, 0)->toArray()[3] - 128))->toBeLessThanOrEqual(2);
	})->with('gradientDrivers');
});

describe('recipe renderers, mixed recipes', function (): void {
	test('the ember recipe renders the same on both drivers', function (): void {
		if (!ImagickSupport::isUsable()) {
			test()->markTestSkipped('Imagick is not usable on this host');
		}
		$steps = [['sepia', 0.25], ['saturate', 1.3], ['overlay', 'ff9a3c', 'soft-light', 0.25], ['vignette', 0.3]];

		$gd      = filterRecipeFor($steps)->apply(filterTestImage('gd', 8, 8, filterGradientPixels()), filterRecipeContext('gd'));
		$imagick = filterRecipeFor($steps)->apply(filterTestImage('imagick', 8, 8, filterGradientPixels()), filterRecipeContext('imagick'));

		for ($i = 0; $i < 64; $i++) {
			expectFilterPixel(
				$gd->pickColor($i % 8, intdiv($i, 8))->toArray(),
				$imagick->pickColor($i % 8, intdiv($i, 8))->toArray(),
				4,
				"pixel {$i}",
			);
		}
	});

	test('linear runs on either side of a nonlinear step stay in order', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$steps = [['brightness', 0.5], ['overlay', 'ffffff', 'normal', 1.0], ['brightness', 0.5]];

		$result = filterRecipeFor($steps)->apply(filterTestImage($driver, 1, 1, [100, 100, 100, 255]), filterRecipeContext($driver));

		expectFilterPixel($result->pickColor(0, 0)->toArray(), [128, 128, 128], 2, $driver);
	})->with(['gd', 'imagick']);
});

/**
 * Every pixel of an image as [r, g, b, a], row by row.
 *
 * @return list<list<int>>
 */
function filterAllPixels(ImageInterface $image): array
{
	$pixels = [];
	for ($y = 0; $y < $image->height(); $y++) {
		for ($x = 0; $x < $image->width(); $x++) {
			$pixels[] = $image->pickColor($x, $y)->toArray();
		}
	}

	return $pixels;
}

/**
 * A 16 x 16 image covering dark, mid and light values in every channel.
 *
 * @return list<list<int>>
 */
function filterWidePixels(): array
{
	$pixels = [];
	for ($i = 0; $i < 256; $i++) {
		$pixels[] = [$i, ($i % 16) * 17, 255 - $i, 255];
	}

	return $pixels;
}

describe('recipe renderers, grain', function (): void {
	dataset('grainDrivers', ['gd', 'imagick']);

	test('renders the same pattern every time', function (string $driver): void {
		skipUnlessFilterDriver($driver);

		$first  = filterRecipeFor([['grain', 0.6]])->apply(filterTestImage($driver, 16, 16, filterWidePixels()), filterRecipeContext($driver));
		$second = filterRecipeFor([['grain', 0.6]])->apply(filterTestImage($driver, 16, 16, filterWidePixels()), filterRecipeContext($driver));

		expect(filterAllPixels($second))->toBe(filterAllPixels($first));
		expect(filterAllPixels($first))->not->toBe(filterWidePixels());
	})->with('grainDrivers');

	test('drivers agree', function (): void {
		skipUnlessFilterDriver('imagick');

		$gd      = filterAllPixels(filterRecipeFor([['grain', 0.6]])->apply(filterTestImage('gd', 16, 16, filterWidePixels()), filterRecipeContext('gd')));
		$imagick = filterAllPixels(filterRecipeFor([['grain', 0.6]])->apply(filterTestImage('imagick', 16, 16, filterWidePixels()), filterRecipeContext('imagick')));

		foreach ($gd as $i => $pixel) {
			expectFilterPixel(array_slice($imagick[$i], 0, 3), array_slice($pixel, 0, 3), 1, "pixel {$i}");
		}
	});

	test('pure black and pure white are unchanged', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$pixels = [];
		for ($i = 0; $i < 64; $i++) {
			$pixels[] = $i % 2 === 0 ? [0, 0, 0, 255] : [255, 255, 255, 255];
		}

		$result = filterRecipeFor([['grain', 1.0]])->apply(filterTestImage($driver, 8, 8, $pixels), filterRecipeContext($driver));

		expect(filterAllPixels($result))->toBe($pixels);
	})->with('grainDrivers');

	test('a mid-grey patch keeps its mean, with a monochrome spread', function (string $driver): void {
		skipUnlessFilterDriver($driver);

		$result = filterRecipeFor([['grain', 0.35]])->apply(filterTestImage($driver, 64, 64, [128, 128, 128, 255]), filterRecipeContext($driver));

		$values = [];
		foreach (filterAllPixels($result) as [$r, $g, $b]) {
			expect($g)->toBe($r);
			expect($b)->toBe($r);
			$values[] = $r;
		}
		$mean     = array_sum($values) / count($values);
		$variance = array_sum(array_map(static fn (int $v): float => ($v - $mean) ** 2, $values)) / count($values);

		expect(abs($mean - 128))->toBeLessThanOrEqual(2, "{$driver} mean {$mean}");
		expect(sqrt($variance))->toBeGreaterThan(3.0, "{$driver} spread");
	})->with('grainDrivers');

	test('a transparent pixel stays transparent', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 2, 1, [[128, 128, 128, 0], [128, 128, 128, 128]]);

		$result = filterRecipeFor([['grain', 1.0]])->apply($image, filterRecipeContext($driver));

		expect(abs($result->pickColor(0, 0)->toArray()[3] - 0))->toBeLessThanOrEqual(2);
		expect(abs($result->pickColor(1, 0)->toArray()[3] - 128))->toBeLessThanOrEqual(2);
	})->with('grainDrivers');
});

describe('grain cells', function (): void {
	it('grow with the short side of the image', function (int $width, int $height, int $cell): void {
		expect(NonlinearSteps::grainCell($width, $height))->toBe($cell);
	})->with([
		'thumbnail'    => [144, 144, 1],
		'600 short'    => [900, 600, 1],
		'1067 short'   => [1600, 1067, 2],
		'1200 short'   => [1200, 1800, 2],
		'1800 short'   => [2700, 1800, 3],
		'2400 short'   => [3600, 2400, 4],
		'a thin strip' => [5000, 10, 1],
	]);

	it('draws the same pattern, scaled, at twice the size', function (): void {
		$small = NonlinearSteps::grainNoiseRow(0, 600, 600);
		$large = NonlinearSteps::grainNoiseRow(0, 1200, 1200);

		for ($x = 0; $x < 50; $x++) {
			// Cell corners carry the cell's own value; the pixel between two is their blend
			expect($large[2 * $x])->toBe($small[$x]);
			expect($large[2 * $x + 1])->toEqualWithDelta(($small[$x] + $small[$x + 1]) / 2, 1e-9);
		}
		// Row 1 at the large size is halfway between cell rows 0 and 1
		$smallNext = NonlinearSteps::grainNoiseRow(1, 600, 600);
		$largeMid  = NonlinearSteps::grainNoiseRow(1, 1200, 1200);
		expect($largeMid[0])->toEqualWithDelta(($small[0] + $smallNext[0]) / 2, 1e-9);
	});

	it('stays within -1 to 1 and is not one value', function (): void {
		$row = NonlinearSteps::grainNoiseRow(7, 2000, 1500);

		expect(min($row))->toBeGreaterThanOrEqual(-1.0)->toBeLessThan(-0.5);
		expect(max($row))->toBeLessThanOrEqual(1.0)->toBeGreaterThan(0.5);
	});

	test('drivers agree on a large image, where the cells are 2 pixels', function (): void {
		skipUnlessFilterDriver('imagick');
		$gd      = filterRecipeFor([['grain', 0.5]])->apply(ImageManager::gd()->create(1000, 920)->fill('808080'), filterRecipeContext('gd'));
		$imagick = filterRecipeFor([['grain', 0.5]])->apply(ImageManager::imagick()->create(1000, 920)->fill('808080'), filterRecipeContext('imagick'));

		$spread = [];
		for ($y = 400; $y < 430; $y++) {
			for ($x = 500; $x < 530; $x++) {
				$a = $gd->pickColor($x, $y)->toArray();
				expectFilterPixel(array_slice($imagick->pickColor($x, $y)->toArray(), 0, 3), array_slice($a, 0, 3), 1, "pixel {$x},{$y}");
				$spread[] = $a[0];
			}
		}
		expect(max($spread) - min($spread))->toBeGreaterThan(20);
	});
});

describe('recipe renderers, leak', function (): void {
	dataset('leakDrivers', ['gd', 'imagick']);

	it('screens the color into the anchor corner and leaves the opposite one', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 21, 21, [100, 100, 100, 255]);

		$result = filterRecipeFor([['leak', 'ff7a3c', 'top-right', 1.0]])->apply($image, filterRecipeContext($driver));

		// screen(100, c) = 100 + c - 100c/255
		expectFilterPixel($result->pickColor(20, 0)->toArray(), [255, 174, 136], 1, "{$driver} anchor");
		expect(array_slice($result->pickColor(0, 20)->toArray(), 0, 3))->toBe([100, 100, 100]);
		// Halfway along the top edge sits between the two
		$mid = $result->pickColor(10, 0)->toArray();
		expect($mid[0])->toBeGreaterThan(100)->toBeLessThan(255);
	})->with('leakDrivers');

	it('scales with the amount', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 21, 21, [100, 100, 100, 255]);

		$result = filterRecipeFor([['leak', 'ff7a3c', 'bottom-left', 0.5]])->apply($image, filterRecipeContext($driver));

		expectFilterPixel($result->pickColor(0, 20)->toArray(), [178, 137, 118], 1, "{$driver} anchor");
		expect(array_slice($result->pickColor(20, 0)->toArray(), 0, 3))->toBe([100, 100, 100]);
	})->with('leakDrivers');

	it('lights a whole edge', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 21, 21, [100, 100, 100, 255]);

		$result = filterRecipeFor([['leak', 'ffffff', 'left', 1.0]])->apply($image, filterRecipeContext($driver));

		foreach ([0, 10, 20] as $y) {
			expectFilterPixel($result->pickColor(0, $y)->toArray(), [255, 255, 255], 1, "{$driver} left edge y {$y}");
			expect(array_slice($result->pickColor(20, $y)->toArray(), 0, 3))->toBe([100, 100, 100]);
		}
	})->with('leakDrivers');

	test('drivers agree', function (): void {
		skipUnlessFilterDriver('imagick');
		$steps = [['leak', 'ff7a3c', 'top-right', 0.55]];

		$gd      = filterAllPixels(filterRecipeFor($steps)->apply(filterTestImage('gd', 16, 16, filterWidePixels()), filterRecipeContext('gd')));
		$imagick = filterAllPixels(filterRecipeFor($steps)->apply(filterTestImage('imagick', 16, 16, filterWidePixels()), filterRecipeContext('imagick')));

		foreach ($gd as $i => $pixel) {
			expectFilterPixel(array_slice($imagick[$i], 0, 3), array_slice($pixel, 0, 3), 1, "pixel {$i}");
		}
	});

	test('a transparent pixel stays transparent', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 2, 1, [[200, 100, 50, 0], [200, 100, 50, 128]]);

		$result = filterRecipeFor([['leak', 'ff7a3c', 'left', 1.0]])->apply($image, filterRecipeContext($driver));

		expect(abs($result->pickColor(0, 0)->toArray()[3] - 0))->toBeLessThanOrEqual(2);
		expect(abs($result->pickColor(1, 0)->toArray()[3] - 128))->toBeLessThanOrEqual(2);
	})->with('leakDrivers');
});
