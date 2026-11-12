<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Service;

/**
 * A tiled watermark, text or logo: one mark repeated over a square, in rows
 * offset by half a mark like brickwork, so no strip of the image is left
 * clear to crop out. Glide then scales the square to cover the image and
 * crops it to the image's shape (`markw=100w`, `markh=100h`, `markfit=crop`),
 * so the longer side always carries `$tiles` marks whatever the image's size.
 */
final class WatermarkPatternBuilder
{
	/** Pattern side in pixels: sharp on a 2,400px image, small to keep. */
	public const SIZE = 2400;
	/** Marks across the longer side when no count is set. */
	public const DEFAULT_TILES = 4;
	/** More than this and each mark is too small to read. */
	public const MAX_TILES = 12;
	/** Share of each cell the mark fills; the rest is the gap. */
	private const FILL = 0.7;

	public static function build(\GdImage $mark, int $tiles): \GdImage
	{
		$tiles   = max(1, $tiles);
		$pattern = imagecreatetruecolor(self::SIZE, self::SIZE);
		if ($pattern === false) {
			throw new \RuntimeException('Failed to create the watermark pattern');
		}

		imagealphablending($pattern, false);
		imagesavealpha($pattern, true);
		$clear = imagecolorallocatealpha($pattern, 0, 0, 0, 127);
		if ($clear === false) {
			throw new \RuntimeException('Failed to allocate transparent color');
		}
		imagefill($pattern, 0, 0, $clear);
		imagealphablending($pattern, true);

		$cell  = self::SIZE / $tiles;
		$scale = min($cell * self::FILL / imagesx($mark), $cell * self::FILL / imagesy($mark));
		$w     = max(1, (int)round(imagesx($mark) * $scale));
		$h     = max(1, (int)round(imagesy($mark) * $scale));

		// A column either side, so the offset rows reach both edges.
		for ($row = 0; $row < $tiles; $row++) {
			$shift = $row % 2 === 1 ? $cell / 2 : 0.0;
			for ($col = -1; $col <= $tiles; $col++) {
				$x = (int)round($col * $cell + $shift + ($cell - $w) / 2);
				$y = (int)round($row * $cell + ($cell - $h) / 2);
				imagecopyresampled($pattern, $mark, $x, $y, 0, 0, $w, $h, imagesx($mark), imagesy($mark));
			}
		}

		return $pattern;
	}

	/** The tile count a request asks for, held to 1–MAX_TILES. */
	public static function tiles(mixed $repeat): int
	{
		$tiles = is_numeric($repeat) ? (int)$repeat : self::DEFAULT_TILES;

		return min(self::MAX_TILES, max(1, $tiles));
	}
}
