<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

use Closure;
use LogicException;

/**
 * The math of the nonlinear steps (overlay, vignette, gradient-map, grain,
 * leak), shared by both drivers. Overlay and gradient-map reduce to
 * per-channel lookup tables; the vignette reduces to a strength per pixel;
 * grain and leak map whole rows of pixels, so both drivers run the same code.
 */
final class NonlinearSteps
{
	/** Grain at amount 1 moves a midtone pixel by up to this many levels either way. */
	public const GRAIN_LEVELS = 60.0;

	/** A leak fades out over this distance from its corner or edge (0-1 image space). */
	public const LEAK_REACH = 0.6;

	/** One grain cell per this many pixels of the image's short side (see grainCell()). */
	public const GRAIN_CELL_PIXELS = 600;

	/**
	 * Where each leak position sits in normalized (u, v) space. A null axis is
	 * one the distance ignores: an edge lights the whole side.
	 */
	private const LEAK_ANCHORS = [
		'top-left'     => [0.0, 0.0],
		'top-right'    => [1.0, 0.0],
		'bottom-left'  => [0.0, 1.0],
		'bottom-right' => [1.0, 1.0],
		'top'          => [null, 0.0],
		'bottom'       => [null, 1.0],
		'left'         => [0.0, null],
		'right'        => [1.0, null],
	];

	/**
	 * Per-channel lookup tables for an overlay step: out = in + (blend(in, color) - in) * opacity.
	 *
	 * @return array{list<int>,list<int>,list<int>} R, G and B tables, 256 entries each
	 */
	public static function overlayTables(FilterStep $step): array
	{
		$color   = self::rgb((string) $step->args[0]);
		$mode    = (string) $step->args[1];
		$opacity = (float) $step->args[2];

		$tables = [[], [], []];
		foreach ($color as $c => $channel) {
			$source = $channel / 255;
			for ($v = 0; $v < 256; $v++) {
				$base          = $v / 255;
				$out           = $base + (BlendMode::blend($mode, $base, $source) - $base) * $opacity;
				$tables[$c][]  = self::byte($out * 255);
			}
		}

		return $tables;
	}

	/**
	 * Per-channel lookup tables for a gradient-map step, indexed by luminance 0-255.
	 *
	 * @return array{list<int>,list<int>,list<int>}
	 */
	public static function gradientTables(FilterStep $step): array
	{
		$stops = array_map(static fn (mixed $hex): array => self::rgb((string) $hex), $step->args);
		$last  = count($stops) - 1;

		$tables = [[], [], []];
		for ($l = 0; $l < 256; $l++) {
			$pos = $l / 255 * $last;
			$i   = min((int) floor($pos), $last - 1);
			$f   = $pos - $i;
			for ($c = 0; $c < 3; $c++) {
				$tables[$c][] = self::byte($stops[$i][$c] + ($stops[$i + 1][$c] - $stops[$i][$c]) * $f);
			}
		}

		return $tables;
	}

	/**
	 * Vignette strength k for every pixel of row $y. out = in * (1 - k) + color * k.
	 *
	 * @return list<float>
	 */
	public static function vignetteRow(int $y, int $width, int $height, float $strength): array
	{
		$cx = ($width - 1) / 2;
		$cy = ($height - 1) / 2;
		$dy = $cy > 0 ? (($y - $cy) / $cy) ** 2 : 0.0;

		$row = [];
		for ($x = 0; $x < $width; $x++) {
			$dx = $cx > 0 ? (($x - $cx) / $cx) ** 2 : 0.0;
			$d  = sqrt($dx + $dy) / M_SQRT2;
			$t  = ($d - 0.35) / 0.65;
			$t  = $t < 0.0 ? 0.0 : ($t > 1.0 ? 1.0 : $t);
			$row[] = $strength * $t * $t * (3 - 2 * $t);
		}

		return $row;
	}

	/**
	 * The row function for a step computed in PHP over whole rows (grain, leak).
	 * Both renderers call it once per row with the row's index and the image
	 * size, and a reader that returns the row as flat R, G, B bytes (Imagick's
	 * export shape). It returns the new bytes, or null for a row it leaves as
	 * it was, in which case it never calls the reader. Colors are read here,
	 * before the first pixel changes.
	 *
	 * @return Closure(int, int, int, Closure():list<int>): ?list<int>
	 */
	public static function rowFunction(FilterStep $step): Closure
	{
		switch ($step->op) {
			case 'grain':
				$amount = (float) $step->args[0];

				return static fn (int $y, int $width, int $height, Closure $read): ?array => $amount > 0.0 ? self::grainRow($y, $width, $height, $read(), $amount) : null;
			case 'leak':
				$tables   = self::screenTables((string) $step->args[0]);
				$position = (string) $step->args[1];
				$amount   = (float) $step->args[2];
				self::leakAnchor($position);

				return static function (int $y, int $width, int $height, Closure $read) use ($tables, $position, $amount): ?array {
					$mask = self::leakMaskRow($y, $width, $height, $position, $amount);

					return $mask === null ? null : self::leakRow($read(), $mask, $tables);
				};
			default:
				throw new LogicException(sprintf('"%s" is not a row step', $step->op));
		}
	}

	/**
	 * Monochrome film grain over one row of flat R, G, B bytes. Each pixel moves
	 * all three channels by the same amount: amount x GRAIN_LEVELS x n x w, where
	 * n in [-1, 1] comes from grainNoiseRow() (a hash of position alone, so a
	 * render is repeatable and both drivers draw the same pattern), and
	 * w = 4l(1 - l) on the pixel's Rec. 709 luma l, so the grain sits in the
	 * midtones and leaves pure black and white alone.
	 *
	 * @param list<int> $rgb
	 *
	 * @return list<int>
	 */
	private static function grainRow(int $y, int $width, int $height, array $rgb, float $amount): array
	{
		$scale = $amount * self::GRAIN_LEVELS * 4;
		$noise = self::grainNoiseRow($y, $width, $height);
		$count = count($rgb);
		$out   = [];

		for ($i = 0, $x = 0; $i + 2 < $count; $i += 3, $x++) {
			$r = $rgb[$i];
			$g = $rgb[$i + 1];
			$b = $rgb[$i + 2];
			$l = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
			$d = $scale * ($noise[$x] ?? 0.0) * $l * (1 - $l);

			$v     = (int) round($r + $d);
			$out[] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
			$v     = (int) round($g + $d);
			$out[] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
			$v     = (int) round($b + $d);
			$out[] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
		}

		return $out;
	}

	/**
	 * Grain cell size in pixels: 1 up to a 900px short side, then one more
	 * pixel per GRAIN_CELL_PIXELS, so the grain looks the same at any output size.
	 */
	public static function grainCell(int $width, int $height): int
	{
		return max(1, (int) round(min($width, $height) / self::GRAIN_CELL_PIXELS));
	}

	/**
	 * Grain noise n in [-1, 1] for each pixel of row $y. Every grain cell
	 * (floor(x/s), floor(y/s)) gets a value from a 32-bit hash of its position,
	 * at its top-left corner, and a pixel blends the four corner values around
	 * it (bilinear): hard cells showed as square blocks from a cell size of 3
	 * or so. At cell size 1 every pixel is its own cell value.
	 *
	 * @return list<float>
	 */
	public static function grainNoiseRow(int $y, int $width, int $height): array
	{
		$s     = self::grainCell($width, $height);
		$cy    = intdiv($y, $s);
		$cells = intdiv(max($width, 1) - 1, $s) + 2;
		$top   = self::grainLattice($cy, $cells);

		$row = [];
		if ($s === 1) {
			for ($x = 0; $x < $width; $x++) {
				$row[] = $top[intdiv($x, $s)];
			}

			return $row;
		}

		$bottom = self::grainLattice($cy + 1, $cells);
		$u      = ($y - $cy * $s) / $s;
		for ($x = 0; $x < $width; $x++) {
			$cx    = intdiv($x, $s);
			$t     = ($x - $cx * $s) / $s;
			$a     = $top[$cx] + ($top[$cx + 1] - $top[$cx]) * $t;
			$b     = $bottom[$cx] + ($bottom[$cx + 1] - $bottom[$cx]) * $t;
			$row[] = $a + ($b - $a) * $u;
		}

		return $row;
	}

	/**
	 * Noise values in [-1, 1] for $cells grain cells of cell row $cy.
	 *
	 * @return list<float>
	 */
	private static function grainLattice(int $cy, int $cells): array
	{
		$seed = self::hash32(($cy & 0xFFFFFFFF) ^ 0x9E3779B9);
		$row  = [];
		for ($cx = 0; $cx < $cells; $cx++) {
			$row[] = self::hash32(($cx & 0xFFFFFFFF) ^ $seed) / 2147483647.5 - 1.0;
		}

		return $row;
	}

	/**
	 * Light leak strength m for every pixel of row $y, or null when the row is
	 * out of reach. With u = x/(w-1) and v = y/(h-1), d is the distance from the
	 * corner (divided by sqrt 2, so the far corner is 1) or from the edge, and
	 * m = amount x (1 - smoothstep(0, LEAK_REACH, d)).
	 *
	 * @return list<float>|null
	 */
	private static function leakMaskRow(int $y, int $width, int $height, string $position, float $amount): ?array
	{
		[$au, $av] = self::leakAnchor($position);
		$norm      = $au !== null && $av !== null ? M_SQRT2 : 1.0;
		$v         = $height > 1 ? $y / ($height - 1) : 0.0;
		$dv        = $av === null ? 0.0 : ($v - $av) ** 2;
		$reach     = (self::LEAK_REACH * $norm) ** 2;

		if ($amount <= 0.0 || $dv >= $reach) {
			return null;
		}

		$row = [];
		for ($x = 0; $x < $width; $x++) {
			$du = $au === null ? 0.0 : (($width > 1 ? $x / ($width - 1) : 0.0) - $au) ** 2;
			$t  = sqrt($du + $dv) / $norm / self::LEAK_REACH;
			$t  = $t > 1.0 ? 1.0 : $t;
			$row[] = $amount * (1 - $t * $t * (3 - 2 * $t));
		}

		return $row;
	}

	/**
	 * Mixes each pixel toward screen(pixel, color) by its mask strength:
	 * out = in + (screen(in, color) - in) x m.
	 *
	 * @param list<int>                                 $rgb
	 * @param list<float>                               $mask
	 * @param array{list<float>,list<float>,list<float>} $tables screen(in, color) per channel, from screenTables()
	 *
	 * @return list<int>
	 */
	private static function leakRow(array $rgb, array $mask, array $tables): array
	{
		[$rt, $gt, $bt] = $tables;
		$count          = count($rgb);
		$out            = [];

		// Screen never darkens and never passes 255, so no clamp is needed.
		for ($i = 0, $x = 0; $i + 2 < $count; $i += 3, $x++) {
			$r = $rgb[$i];
			$g = $rgb[$i + 1];
			$b = $rgb[$i + 2];
			$m = $mask[$x] ?? 0.0;
			if ($m > 0.0) {
				$r = (int) round($r + ($rt[$r] - $r) * $m);
				$g = (int) round($g + ($gt[$g] - $g) * $m);
				$b = (int) round($b + ($bt[$b] - $b) * $m);
			}
			$out[] = $r;
			$out[] = $g;
			$out[] = $b;
		}

		return $out;
	}

	/**
	 * screen(in, color) in 0-255 for every input byte, per channel, from BlendMode.
	 *
	 * @return array{list<float>,list<float>,list<float>}
	 */
	private static function screenTables(string $hex): array
	{
		$tables = [[], [], []];
		foreach (self::rgb($hex) as $c => $channel) {
			for ($v = 0; $v < 256; $v++) {
				$tables[$c][] = BlendMode::blend('screen', $v / 255, $channel / 255) * 255;
			}
		}

		return $tables;
	}

	/**
	 * @return array{int,int,int}
	 */
	public static function rgb(string $hex): array
	{
		if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
			throw new LogicException(sprintf('"%s" is not a 6-digit hex color', $hex));
		}

		return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
	}

	/**
	 * @return array{?float,?float}
	 */
	private static function leakAnchor(string $position): array
	{
		return self::LEAK_ANCHORS[$position] ?? throw new LogicException(sprintf('Unknown leak position "%s"', $position));
	}

	/**
	 * The "lowbias32" integer hash (Chris Wellons): a well-mixed bijection on
	 * 32-bit values. PHP ints are 64-bit and a full product would overflow to a
	 * float, so each multiply is done in two 16-bit halves, kept to 32 bits.
	 */
	private static function hash32(int $x): int
	{
		$x ^= $x >> 16;
		$x  = ($x * 0x352D + ((($x * 0x7FEB) & 0xFFFF) << 16)) & 0xFFFFFFFF;
		$x ^= $x >> 15;
		$x  = ($x * 0xA68B + ((($x * 0x846C) & 0xFFFF) << 16)) & 0xFFFFFFFF;

		return $x ^ ($x >> 16);
	}

	private static function byte(float $value): int
	{
		$v = (int) round($value);

		return $v < 0 ? 0 : ($v > 255 ? 255 : $v);
	}
}
