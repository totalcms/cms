<?php

declare(strict_types=1);

use TotalCMS\Domain\Seo\Service\IcoEncoder;

// `/favicon.ico` is the 32px PNG wrapped in an ICO container. Windows and every
// browser have read PNG-in-ICO since Vista, so no raster library is needed —
// only a 6-byte header, a 16-byte directory entry, then the PNG as is.
describe('IcoEncoder', function (): void {
	$png = static function (int $size): string {
		$image = imagecreatetruecolor($size, $size);
		ob_start();
		imagepng($image);

		return (string)ob_get_clean();
	};

	test('wraps a PNG in a one-image ICO container', function () use ($png): void {
		$source = $png(32);
		$ico    = IcoEncoder::fromPng($source);

		// ICONDIR: reserved 0, type 1 (icon), one image.
		expect(substr($ico, 0, 6))->toBe(pack('vvv', 0, 1, 1));
		// ICONDIRENTRY: width, height, colors, reserved, planes, bit depth, byte size, offset.
		expect(unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vsize/Voffset', substr($ico, 6, 16)))->toBe([
			'width' => 32, 'height' => 32, 'colors' => 0, 'reserved' => 0, 'planes' => 1, 'bits' => 32, 'size' => strlen($source), 'offset' => 22,
		]);
		expect(substr($ico, 22))->toBe($source);
	});

	test('a 256px image is recorded as 0, the ICO convention for 256', function () use ($png): void {
		$entry = unpack('Cwidth/Cheight', substr(IcoEncoder::fromPng($png(256)), 6, 2));
		expect($entry)->toBe(['width' => 0, 'height' => 0]);
	});

	test('refuses anything that is not a PNG', function (): void {
		expect(fn () => IcoEncoder::fromPng('GIF89a...'))->toThrow(InvalidArgumentException::class);
	});
});
