<?php

declare(strict_types=1);

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Interfaces\DriverInterface;
use TotalCMS\Domain\ImageWorks\Data\TextWatermarkStyle;
use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;

// The background plate used to be sized from each driver's font metrics, and
// Imagick's put the tails of g, j, p, q and y below the plate.

/** Whether a row of the drawn mark is all plate, with no text in it. */
function textMarkRowIsPlate(\GdImage $image, int $y): bool
{
	for ($x = 0; $x < imagesx($image); $x += 2) {
		$p = imagecolorat($image, $x, $y);
		if (abs((($p >> 16) & 0xFF) - 0x0b) + abs((($p >> 8) & 0xFF) - 0x0f) + abs(($p & 0xFF) - 0x1a) > 40) {
			return false;
		}
	}

	return true;
}

it('keeps the whole text on its plate, with even padding', function (DriverInterface $driver): void {
	$factory = (new ReflectionClass(TextWatermarkFactory::class))->newInstanceWithoutConstructor();
	$mark    = $factory->draw(new TextWatermarkStyle([
		'marktext' => "Loring gjpqy\nSecond line g", 'marktextsize' => 120, 'marktextbg' => '0b0f1a', 'marktextpad' => 20,
	]), $driver);

	$gd = imagecreatefromstring((string)$mark->toPng());
	$h  = imagesy($gd);

	// The padding rows are plate; the rows just inside them hold text.
	expect(textMarkRowIsPlate($gd, 2))->toBeTrue()
		->and(textMarkRowIsPlate($gd, $h - 3))->toBeTrue()
		->and(textMarkRowIsPlate($gd, 24))->toBeFalse()
		->and(textMarkRowIsPlate($gd, $h - 25))->toBeFalse();
})->with([
	'gd'      => [fn (): DriverInterface => new GdDriver()],
	'imagick' => [function (): DriverInterface {
		if (!extension_loaded('imagick')) {
			test()->markTestSkipped('Imagick is not installed');
		}

		return new ImagickDriver();
	}],
]);
