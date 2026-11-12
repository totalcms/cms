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
function textMarkRowIsPlate(GdImage $image, int $y): bool
{
	for ($x = 0; $x < imagesx($image); $x += 2) {
		$p = imagecolorat($image, $x, $y);
		if (abs((($p >> 16) & 0xFF) - 0x0B) + abs((($p >> 8) & 0xFF) - 0x0F) + abs(($p & 0xFF) - 0x1A) > 40) {
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

// Wrapping (marktextw + marktextsize): the text breaks at word boundaries to
// the box, and steps down in size when it needs more than marktextlines
// lines. Capitals only, so every line is a clear band of rows with plate
// between them.

/**
 * The bands of rows that hold text, as [first row, last row].
 *
 * @return list<array{int,int}>
 */
function textMarkLines(GdImage $image): array
{
	$bands = [];
	$start = null;
	for ($y = 0; $y < imagesy($image); $y++) {
		$ink = !textMarkRowIsPlate($image, $y);
		if ($ink && $start === null) {
			$start = $y;
		} elseif (!$ink && $start !== null) {
			$bands[] = [$start, $y - 1];
			$start   = null;
		}
	}

	if ($start !== null) {
		$bands[] = [$start, imagesy($image) - 1];
	}

	return $bands;
}

/** The first column with text in it, within a band of rows. */
function textMarkFirstInk(GdImage $image, array $band): int
{
	for ($x = 0; $x < imagesx($image); $x++) {
		for ($y = $band[0]; $y <= $band[1]; $y++) {
			$p = imagecolorat($image, $x, $y);
			if ((($p >> 16) & 0xFF) > 128) {
				return $x;
			}
		}
	}

	return -1;
}

/** @param array<string,mixed> $params */
function textMarkDraw(DriverInterface $driver, array $params, ?int $wrapWidth = null): GdImage
{
	$factory = (new ReflectionClass(TextWatermarkFactory::class))->newInstanceWithoutConstructor();
	$mark    = $factory->draw(new TextWatermarkStyle($params + ['marktextbg' => '0b0f1a', 'marktextpad' => 10]), $driver, $wrapWidth);

	return imagecreatefromstring((string)$mark->toPng());
}

describe('wrapped text marks', function (): void {
	it('wraps a long title at its size instead of shrinking it', function (DriverInterface $driver): void {
		$mark = textMarkDraw($driver, ['marktext' => 'MEET THE ROASTER A DAY ON THE LORING', 'marktextsize' => 60], 600);

		expect(count(textMarkLines($mark)))->toBeGreaterThanOrEqual(2)
			->and(imagesx($mark))->toBeLessThanOrEqual(600);
		// Still drawn at 60px: a line of capitals is about two-thirds of that tall.
		expect(textMarkLines($mark)[0][1] - textMarkLines($mark)[0][0])->toBeGreaterThan(35);
	})->with('text mark drivers');

	it('steps the size down to keep within the line cap', function (DriverInterface $driver): void {
		$text  = 'MEET THE ROASTER A DAY ON THE LORING WITH OUR HEAD ROASTER FROM FIRST CRACK TO THE CUPPING TABLE';
		$three = textMarkDraw($driver, ['marktext' => $text, 'marktextsize' => 60], 600);
		$two   = textMarkDraw($driver, ['marktext' => $text, 'marktextsize' => 60, 'marktextlines' => 2], 600);

		expect(count(textMarkLines($three)))->toBeLessThanOrEqual(3)
			->and(count(textMarkLines($two)))->toBe(2)
			->and(imagesx($two))->toBeLessThanOrEqual(600);
		// Two lines needed a smaller size than three did.
		expect(textMarkLines($two)[0][1] - textMarkLines($two)[0][0])
			->toBeLessThan(textMarkLines($three)[0][1] - textMarkLines($three)[0][0]);
	})->with('text mark drivers');

	it('still breaks at a newline', function (DriverInterface $driver): void {
		$mark = textMarkDraw($driver, ['marktext' => "SHORT\nTEXT", 'marktextsize' => 60], 2000);

		expect(textMarkLines($mark))->toHaveCount(2);
	})->with('text mark drivers');

	it('leaves a title that fits on one line as it was', function (DriverInterface $driver): void {
		$wrapped = textMarkDraw($driver, ['marktext' => 'MEET THE ROASTER', 'marktextsize' => 60], 2000);
		$plain   = textMarkDraw($driver, ['marktext' => 'MEET THE ROASTER', 'marktextsize' => 60]);

		expect([imagesx($wrapped), imagesy($wrapped)])->toBe([imagesx($plain), imagesy($plain)]);
	})->with('text mark drivers');

	it('leaves clear space between one line\'s tails and the capitals below', function (DriverInterface $driver): void {
		$mark  = textMarkDraw($driver, ['marktext' => "Roasting gjpqy\nTHE LORING", 'marktextsize' => 60], 2000);
		$lines = textMarkLines($mark);

		// At a 1.0 line height the tails of g and y ran into the next line's capitals.
		expect($lines)->toHaveCount(2)
			->and($lines[1][0] - $lines[0][1])->toBeGreaterThanOrEqual(6);
	})->with('text mark drivers');

	it('spaces lines by the font size, whatever the driver measures', function (DriverInterface $driver): void {
		// Two lines of capitals only, so each line's ink starts at its cap height.
		$mark  = textMarkDraw($driver, ['marktext' => "TOTAL CMS\nTOTAL CMS", 'marktextsize' => 60], 2000);
		$lines = textMarkLines($mark);

		// About 1.25 times the size from line to line. Imagick measures a font
		// by its capitals and GD by its full height, so a multiple of either
		// gave one driver far tighter lines than the other.
		expect($lines)->toHaveCount(2)
			->and($lines[1][0] - $lines[0][0])->toBeGreaterThanOrEqual(68)
			->and($lines[1][0] - $lines[0][0])->toBeLessThanOrEqual(82);
	})->with('text mark drivers');

	it('centers the lines when asked', function (DriverInterface $driver): void {
		$text   = "MEET THE ROASTER ON\nTHE LORING";
		$left   = textMarkDraw($driver, ['marktext' => $text, 'marktextsize' => 60], 2000);
		$center = textMarkDraw($driver, ['marktext' => $text, 'marktextsize' => 60, 'marktextalign' => 'center'], 2000);

		// The short second line starts at the padding when left aligned, well in when centered.
		expect(textMarkFirstInk($left, textMarkLines($left)[1]))->toBeLessThan(25)
			->and(textMarkFirstInk($center, textMarkLines($center)[1]))->toBeGreaterThan(100);
	})->with('text mark drivers');
});

dataset('text mark drivers', [
	'gd'      => [fn (): DriverInterface => new GdDriver()],
	'imagick' => [function (): DriverInterface {
		if (!extension_loaded('imagick')) {
			test()->markTestSkipped('Imagick is not installed');
		}

		return new ImagickDriver();
	}],
]);
