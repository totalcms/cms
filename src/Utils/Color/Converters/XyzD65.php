<?php

namespace TotalCMS\Utils\Color\Converters;

use TotalCMS\Utils\Color\ColorSpace;
use TotalCMS\Utils\Color\Constant;
use TotalCMS\Utils\Color\Exceptions\MissingColorValue;
use TotalCMS\Utils\Color\Util;

abstract class XyzD65
{
	/**
	 * @return array<int, float|int>
	 */
	public static function clean(
		mixed $value,
		?bool $throw = null,
	): array {
		$values  = Util::parseColorValue($value, 1);
		$x       =                       $values['x'] ?? $values[0] ?? null;
		$y       =                       $values['y'] ?? $values[1] ?? null;
		$z       =                       $values['z'] ?? $values[2] ?? null;
		$opacity = $values['opacity'] ?? $values['o'] ?? $values[3] ?? null;

		// @phpstan-ignore-next-line nullCoalesce.expr
		return match (true) {
			!$throw       => null,
			($x === null) => throw new MissingColorValue('x'),
			($y === null) => throw new MissingColorValue('y'),
			($z === null) => throw new MissingColorValue('z'),
			default       => null,
		} ?? [
			Util::cleanCoordinate($x ?? 0, 0, 1),
			Util::cleanCoordinate($y ?? 0, 0, 1),
			Util::cleanCoordinate($z ?? 0, 0, 1),
			Util::cleanCoordinate($opacity ?? 1, 0, 1),
		];
	}

	/**
	 * @param  array<int, float|int>|null $fallback
	 *
	 * @return array<int, float|int>|null
	 */
	public static function from(
		mixed $value,
		ColorSpace|\Stringable|string|null $from     = null,
		?array $fallback = null,
		?bool $throw    = null,
	): ?array {
		/** @var array<int, float|int>|null $result */
		$result = Util::to(
			value    : $value,
			to       : ColorSpace::XyzD65,
			from     : $from,
			fallback : $fallback,
			throw    : $throw,
		);

		return $result;
	}

	public static function stringify(
		float $x,
		float $y,
		float $z,
		float $opacity   = 1,
		?bool $alpha     = null,
		?int $precision = null,
	): string {
		$precision ??= Constant::PRECISION->value();
		$alpha ??= ($opacity !== (float)1);

		$value = 'color(xyz-d65 '
			. \round($x, $precision)
			. ' '
			. \round($y, $precision)
			. ' '
			. \round($z, $precision)
		;

		if (!$alpha) {
			return "$value)";
		}

		return $value
			. ' / '
			. $opacity * 100
			. '%)'
		;
	}

	public static function verify(
		mixed $value,
	): bool {
		return Util::isColorString($value, ColorSpace::XyzD65);
	}

	/**
	 * @return array<int, string>
	 */
	public static function toHexRgb(
		float $x       = 0,
		float $y       = 0,
		float $z       = 0,
		float $opacity = 1,
	): array {
		return Rgb::toHexRgb(...self::toRgb($x, $y, $z, $opacity));
	}

	/**
	 * @return array<int, float|int>
	 */
	public static function toHsl(
		float $x       = 0,
		float $y       = 0,
		float $z       = 0,
		float $opacity = 1,
	): array {
		return Rgb::toHsl(...self::toRgb($x, $y, $z, $opacity));
	}

	/**
	 * @return array<int, float|int>
	 */
	public static function toLinRgb(
		float $x       = 0,
		float $y       = 0,
		float $z       = 0,
		float $opacity = 1,
	): array {
		/** @var array<int, float|int> $result */
		$result = Util::push(
			value : $opacity,
			array : Util::multiplyMatrices(
				a : [
					[3.2409699419045226,  -1.537383177570094,   -0.4986107602930034],
					[-0.9692436362808796,   1.8759675015077202,   0.04155505740717559],
					[0.05563007969699366, -0.20397695888897652,  1.0569715142428786],
				],
				b : [$x, $y, $z],
			),
		);

		return $result;
	}

	/**
	 * @return array<int, float|int>
	 */
	public static function toOkLab(
		float $x       = 0,
		float $y       = 0,
		float $z       = 0,
		float $opacity = 1,
	): array {
		/** @var array<int, float|int> $okLab */
		$okLab = Util::push(
			value : $opacity * 100,
			array : Util::multiplyMatrices(
				a : [
					[0.2104542553,  0.7936177850, -0.0040720468],
					[1.9779984951, -2.4285922050,  0.4505937099],
					[0.0259040371,  0.7827717662, -0.8086757660],
				],
				b : \array_map(
					callback : function ($v): float {
						/** @var float|int $v */
						return $v ** (1 / 3);
					},
					array    : Util::multiplyMatrices(
						a : [
							[0.8190224432164319,   0.3619062562801221,  -0.12887378261216414],
							[0.0329836671980271,   0.9292868468965546,   0.03614466816999844],
							[0.048177199566046255, 0.26423952494422764,  0.6335478258136937],
						],
						b : [$x, $y, $z],
					),
				),
			),
		);

		// Multiply Lightness by 100 so it is compatible with CSS OkLab:
		$okLab[0] *= 100;

		return $okLab;
	}

	/**
	 * @return array<int, float|int>
	 */
	public static function toOkLch(
		float $x       = 0,
		float $y       = 0,
		float $z       = 0,
		float $opacity = 1,
	): array {
		return OkLab::toOkLch(...self::toOkLab($x, $y, $z, $opacity));
	}

	/**
	 * @return array<int, float|int>
	 */
	public static function toRgb(
		float $x       = 0,
		float $y       = 0,
		float $z       = 0,
		float $opacity = 1,
	): array {
		return LinRgb::toRgb(...self::toLinRgb($x, $y, $z, $opacity));
	}
}
