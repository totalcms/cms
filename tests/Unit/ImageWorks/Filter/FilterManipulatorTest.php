<?php

declare(strict_types=1);

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Tests\Fakes\RecordingLogger;
use TotalCMS\Domain\ImageWorks\Exception\FilterTooLargeException;
use TotalCMS\Domain\ImageWorks\Filter\BuiltInFilters;
use TotalCMS\Domain\ImageWorks\Filter\DuotoneFilter;
use TotalCMS\Domain\ImageWorks\Filter\FilterContext;
use TotalCMS\Domain\ImageWorks\Filter\FilterManipulator;
use TotalCMS\Domain\ImageWorks\Filter\FilterRecipe;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilter;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilterRegistry;

// FilterManipulator on both drivers: the strength blend, animations, the
// pixel limit and the rule that a failed filter leaves the image untouched.

/** @param array<string,mixed> $params */
function runFilterManipulator(ImageInterface $image, array $params, int $maxPixels = 25000000, ?ImageFilterRegistry $registry = null, ?RecordingLogger $logger = null): ImageInterface
{
	$manipulator = new FilterManipulator($registry ?? imageFilterRegistry(), [], $maxPixels, $logger);
	$manipulator->setParams($params);

	return $manipulator->run($image);
}

/** A 3-frame 4x4 GIF (red, blue, green), encoded and read back as the pipeline would. */
function filterAnimation(string $driver): ImageInterface
{
	$manager   = $driver === 'imagick' ? ImageManager::imagick() : ImageManager::gd();
	$colors    = [[255, 0, 0, 255], [0, 0, 255, 255], [0, 128, 0, 255]];
	$animation = $manager->animate(function ($animation) use ($driver, $colors): void {
		foreach ($colors as $color) {
			$animation->add(filterTestImage($driver, 4, 4, $color)->toPng()->toString(), 0.1);
		}
	});

	return $manager->read($animation->toGif()->toString());
}

/** @return array{int,int,int,int} */
function filterPixel(ImageInterface $image, int $x, int $y, int $frame = 0): array
{
	$rgba = $image->pickColor($x, $y, $frame)->toArray();

	return [(int)$rgba[0], (int)$rgba[1], (int)$rgba[2], (int)$rgba[3]];
}

/** @param array<int,int> $actual @param array<int,int> $expected */
function expectNear(array $actual, array $expected, int $tolerance, string $label): void
{
	foreach ($expected as $i => $value) {
		expect(abs($actual[$i] - $value))->toBeLessThanOrEqual($tolerance, "{$label} channel {$i}: got {$actual[$i]}, expected {$value}");
	}
}

dataset('manipulatorDrivers', ['gd', 'imagick']);

describe('strength blend', function (): void {
	it('keeps transparency at part strength', function (string $driver, string $filter): void {
		skipUnlessFilterDriver($driver);
		$pixels = [[255, 0, 0, 255], [0, 0, 255, 128], [0, 200, 0, 0]];

		$full = runFilterManipulator(filterTestImage($driver, 3, 1, $pixels), ['filt' => $filter]);
		$half = runFilterManipulator(filterTestImage($driver, 3, 1, $pixels), ['filt' => $filter, 'filtamt' => 50]);

		expect(filterPixel($half, 0, 0)[3])->toBe(255);
		expect(abs(filterPixel($half, 1, 0)[3] - 128))->toBeLessThanOrEqual(3, 'half-alpha pixel keeps its alpha');
		expect(filterPixel($half, 2, 0)[3])->toBeLessThanOrEqual(2, 'transparent pixel stays transparent');

		// The opaque pixel is halfway between the original and the full filter.
		$f = filterPixel($full, 0, 0);
		expectNear(filterPixel($half, 0, 0), [(int)round((255 + $f[0]) / 2), (int)round($f[1] / 2), (int)round($f[2] / 2)], 3, "{$driver} {$filter}");
	})->with('manipulatorDrivers')->with(['ember', 'greyscale']);

	it('filters and blends every frame of an animation', function (string $driver, int $amount): void {
		skipUnlessFilterDriver($driver);
		$image    = filterAnimation($driver);
		$original = [filterPixel($image, 1, 1, 0), filterPixel($image, 1, 1, 1), filterPixel($image, 1, 1, 2)];
		$params   = $amount < 100 ? ['filt' => 'noir', 'filtamt' => $amount] : ['filt' => 'noir'];

		$result = runFilterManipulator($image, $params);

		expect(count($result))->toBe(3);
		$noir = FilterRecipe::fromDefinition('noir', BuiltInFilters::all()['noir']);
		foreach ($original as $frame => $rgba) {
			$single   = $noir->apply(filterTestImage($driver, 4, 4, $rgba), filterRecipeContext($driver));
			$filtered = filterPixel($single, 1, 1);
			$t        = $amount / 100;
			$expected = array_map(fn (int $c): int => (int)round($rgba[$c] + ($filtered[$c] - $rgba[$c]) * $t), [0, 1, 2]);

			expectNear(filterPixel($result, 1, 1, $frame), $expected, 3, "{$driver} frame {$frame} at {$amount}");
		}
	})->with('manipulatorDrivers')->with([100, 50]);
});

describe('pixel limit', function (): void {
	it('counts every frame of an animation on GD', function (): void {
		// 3 frames of 4x4 is 48 pixels, over a limit of 20.
		expect(fn () => runFilterManipulator(filterAnimation('gd'), ['filt' => 'pop'], 20))
			->toThrow(FilterTooLargeException::class);
	});

	it('applies on Imagick only to filters with a vignette step', function (): void {
		skipUnlessFilterDriver('imagick');

		expect(fn () => runFilterManipulator(filterTestImage('imagick', 4, 4, [9, 9, 9, 255]), ['filt' => 'vignette'], 10))
			->toThrow(FilterTooLargeException::class);
		expect(fn () => runFilterManipulator(filterTestImage('imagick', 4, 4, [9, 9, 9, 255]), ['filt' => 'pop,noir'], 10))
			->toThrow(FilterTooLargeException::class);

		$image = runFilterManipulator(filterTestImage('imagick', 4, 4, [9, 9, 9, 255]), ['filt' => 'pop,lagoon'], 10);
		expect($image->width())->toBe(4);
	});

	it('applies on Imagick to recipes with a grain or leak step, not to color-only ones', function (): void {
		skipUnlessFilterDriver('imagick');

		foreach (['grain', 'leak', 'pop,grain'] as $filt) {
			expect(fn () => runFilterManipulator(filterTestImage('imagick', 4, 4, [9, 9, 9, 255]), ['filt' => $filt], 10))
				->toThrow(FilterTooLargeException::class);
		}

		$image = runFilterManipulator(filterTestImage('imagick', 4, 4, [9, 9, 9, 255]), ['filt' => 'pop,warm,cool'], 10);
		expect($image->width())->toBe(4);
	});

	it('still applies on GD to recipe filters without a vignette', function (): void {
		expect(fn () => runFilterManipulator(filterTestImage('gd', 4, 4, [9, 9, 9, 255]), ['filt' => 'pop'], 10))
			->toThrow(FilterTooLargeException::class);
	});

	it('is unlimited at zero or below', function (int $limit): void {
		$image = runFilterManipulator(filterTestImage('gd', 4, 4, [9, 9, 9, 255]), ['filt' => 'pop'], $limit);
		expect($image->width())->toBe(4);
	})->with([0, -1]);

	it('does not refuse a filter at zero strength', function (): void {
		$image = runFilterManipulator(filterTestImage('gd', 4, 4, [200, 40, 40, 255]), ['filt' => 'pop', 'filtamt' => 0], 10);
		expect(filterPixel($image, 1, 1))->toBe([200, 40, 40, 255]);
	});
});

describe('a failed filter leaves the image untouched', function (): void {
	it('on a duotone with a color the renderer cannot read', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$image = filterTestImage($driver, 2, 1, [200, 40, 40, 255]);

		expect(fn () => (new DuotoneFilter())->apply($image, new FilterContext($driver, [], ['fff', '000'])))
			->toThrow(LogicException::class);
		expectNear(filterPixel($image, 0, 0), [200, 40, 40, 255], 0, $driver);
	})->with('manipulatorDrivers');

	it('on an extension class filter that changes the image and then throws', function (string $driver): void {
		skipUnlessFilterDriver($driver);
		$smash = new class implements ImageFilter {
			public function label(): string
			{
				return 'Smash';
			}

			public function apply(ImageInterface $image, FilterContext $context): ImageInterface
			{
				$image->fill('000000');

				throw new RuntimeException('smashed');
			}

			public function version(): string
			{
				return '1';
			}
		};
		$registry = imageFilterRegistry();
		$registry->registerExtensionFilter('acme/pack', 'acme-smash', $smash);
		$logger = new RecordingLogger();

		$image = runFilterManipulator(filterTestImage($driver, 2, 1, [200, 40, 40, 255]), ['filt' => 'acme-smash'], registry: $registry, logger: $logger);

		expectNear(filterPixel($image, 0, 0), [200, 40, 40, 255], 0, $driver);
		expect($logger->records)->toHaveCount(1)
			->and($logger->records[0]['context']['error'])->toBe('smashed');
	})->with('manipulatorDrivers');
});

describe('Imagick color spaces', function (): void {
	it('filters a CMYK JPEG like GD does', function (): void {
		skipUnlessFilterDriver('imagick');
		$cmyk = new Imagick();
		$cmyk->newImage(8, 8, 'rgb(200,50,50)');
		$cmyk->setImageFormat('jpg');
		$cmyk->transformImageColorspace(Imagick::COLORSPACE_CMYK);
		$blob = $cmyk->getImageBlob();

		$lagoon  = FilterRecipe::fromDefinition('lagoon', BuiltInFilters::all()['lagoon']);
		$gd      = $lagoon->apply(ImageManager::gd()->read($blob), filterRecipeContext('gd'));
		$imagick = $lagoon->apply(ImageManager::imagick()->read($blob), filterRecipeContext('imagick'));

		expectNear(filterPixel($imagick, 4, 4), array_slice(filterPixel($gd, 4, 4), 0, 3), 6, 'imagick vs gd');
	});
});
