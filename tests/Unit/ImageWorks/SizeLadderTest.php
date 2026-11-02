<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Service\SizeLadder;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Support\Config;

function sizeLadderConfig(array $imageworks): Config
{
	$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->imageworks = $imageworks;

	return $config;
}

function sizeLadderImage(int $width = 2000, int $height = 1000): ImageData
{
	return new ImageData(['name' => 'photo.jpg', 'width' => $width, 'height' => $height]);
}

function sizeLadder(): SizeLadder
{
	$ladder = SizeLadder::fromConfig(sizeLadderConfig([
		'sizeStep' => 50,
		'picture'  => ['widths' => [480, 768, 1024, 1440, 1920]],
		'presets'  => ['thumb' => ['w' => 320, 'h' => 180], 'broken' => 'not-an-array'],
	]));
	expect($ladder)->toBeInstanceOf(SizeLadder::class);

	return $ladder;
}

describe('SizeLadder', function (): void {
	it('rounds a width up to the next 50 px', function (): void {
		expect(sizeLadder()->snap(['w' => 333], sizeLadderImage()))->toBe(['w' => 350]);
		expect(sizeLadder()->snap(['w' => 1], sizeLadderImage()))->toBe(['w' => 50]);
		expect(sizeLadder()->snap(['w' => 400], sizeLadderImage()))->toBe(['w' => 400]);
	});

	it('leaves picture widths and preset sizes exact', function (): void {
		expect(sizeLadder()->snap(['w' => 768], sizeLadderImage()))->toBe(['w' => 768]);
		expect(sizeLadder()->snap(['w' => 320], sizeLadderImage()))->toBe(['w' => 320]);
		expect(sizeLadder()->snap(['h' => 180], sizeLadderImage()))->toBe(['h' => 180]);
	});

	it('caps a width that would round past the source at the source', function (): void {
		expect(sizeLadder()->snap(['w' => 1990], sizeLadderImage(2000)))->toBe(['w' => 2000]);
	});

	it('leaves a width at or past the source for the generator to clamp', function (): void {
		expect(sizeLadder()->snap(['w' => 5000], sizeLadderImage(2000)))->toBe(['w' => 5000]);
	});

	it('scales the height with the width, keeping the aspect ratio', function (): void {
		// 430x242 is 16:9; 450x253 still is.
		expect(sizeLadder()->snap(['w' => 430, 'h' => 242], sizeLadderImage()))->toBe(['w' => 450, 'h' => 253]);
	});

	it('rounds a height on its own when no width is asked for', function (): void {
		expect(sizeLadder()->snap(['h' => 210], sizeLadderImage()))->toBe(['h' => 250]);
	});

	it('rounds without a cap when the source size is unknown', function (): void {
		expect(sizeLadder()->snap(['w' => 333], sizeLadderImage(0, 0)))->toBe(['w' => 350]);
	});

	it('honors a custom step', function (): void {
		$ladder = SizeLadder::fromConfig(sizeLadderConfig(['sizeStep' => 100]));
		expect($ladder?->snap(['w' => 333], sizeLadderImage()))->toBe(['w' => 400]);
	});

	it('is off by default, at 0 or 1, and for a negative step', function (): void {
		expect(SizeLadder::fromConfig(sizeLadderConfig([])))->toBeNull();
		expect(SizeLadder::fromConfig(sizeLadderConfig(['sizeStep' => 0])))->toBeNull();
		expect(SizeLadder::fromConfig(sizeLadderConfig(['sizeStep' => 1])))->toBeNull();
		expect(SizeLadder::fromConfig(sizeLadderConfig(['sizeStep' => 2])))->toBeInstanceOf(SizeLadder::class);
		expect(SizeLadder::fromConfig(sizeLadderConfig(['sizeStep' => -50])))->toBeNull();
		expect(SizeLadder::fromConfig(sizeLadderConfig(['sizeStep' => '20'])))->toBeInstanceOf(SizeLadder::class);
	});
});
