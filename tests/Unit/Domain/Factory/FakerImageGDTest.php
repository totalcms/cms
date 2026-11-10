<?php

declare(strict_types=1);

use TotalCMS\Domain\Factory\Faker\FakerImageGD;

/**
 * imageShapes() takes one background color, which draws the shapes in random
 * grays, or a `|`-joined palette, which draws them in the starter's colors.
 * The built-in starters use the palette form for their placeholder art.
 */

beforeEach(function (): void {
	$this->dir = sys_get_temp_dir() . '/faker-gd-' . uniqid();
	mkdir($this->dir);
});

afterEach(function (): void {
	array_map(unlink(...), glob($this->dir . '/*') ?: []);
	rmdir($this->dir);
});

/** Every distinct color in the image, as lowercase hex. @return list<string> */
function shapeColors(string $path): array
{
	$image  = imagecreatefrompng($path);
	$colors = [];
	for ($x = 0; $x < imagesx($image); $x += 2) {
		for ($y = 0; $y < imagesy($image); $y += 2) {
			$colors['#' . sprintf('%06x', imagecolorat($image, $x, $y))] = true;
		}
	}

	return array_keys($colors);
}

test('a palette paints the background with its first color and the shapes with the rest', function (): void {
	$path   = FakerImageGD::imageShapes($this->dir, 160, 100, 'f3e7dc|e07a5f|3d405b|f2cc8f');
	$colors = shapeColors($path);

	expect($colors)->toContain('#f3e7dc');
	// The scale-down blends edges, so check the solid fills are there rather
	// than that nothing else is.
	expect(array_intersect(['#e07a5f', '#3d405b', '#f2cc8f'], $colors))->not->toBeEmpty();
});

test('the shapes stay in the palette, with no grays added', function (): void {
	$colors = shapeColors(FakerImageGD::imageShapes($this->dir, 160, 100, 'ffffff|ff0000'));

	// Only white, red, and the blends between them on the shapes' edges.
	foreach ($colors as $hex) {
		[$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
		expect($r)->toBe(255);
		expect($g)->toBe($b);
	}
});

test('a single color still draws gray shapes, as before', function (): void {
	$path = FakerImageGD::imageShapes($this->dir, 160, 100, 'f8f8f8');

	expect(imagesx(imagecreatefrompng($path)))->toBe(160);
	foreach (shapeColors($path) as $hex) {
		[$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
		expect($r === $g && $g === $b)->toBeTrue();
	}
});
