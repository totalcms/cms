<?php

namespace TotalCMS\Utils\Color\Converters;

use TotalCMS\Utils\Color\ColorSpace;
use TotalCMS\Utils\Color\Util;

abstract class HexRgb
{
	/**
	 * @return array<int, string>
	 */
	public static function clean(
		mixed $value,
		bool $throw = true,
	): array {
		$values = [];

		if (\is_array($value)) {
			$values = $value;
		} elseif (\is_string($value)) {
			$value  = \trim($value, '#');
			$values = (\strlen($value) > 3)
				? \str_split($value, 2)
				: \array_map(
					callback : fn ($v): string => $v . $v,
					array    : \str_split($value),
				)
			;
		}

		return [
			Util::cleanHexValue($values[0] ?? '00', 2, true),
			Util::cleanHexValue($values[1] ?? '00', 2, true),
			Util::cleanHexValue($values[2] ?? '00', 2, true),
			Util::cleanHexValue($values[3] ?? 'ff', 2, true),
		];
	}

	/**
	 * @param  array<int, string>|null $fallback
	 *
	 * @return array<int, string>|null
	 */
	public static function from(
		mixed $value,
		ColorSpace|\Stringable|string|null $from     = null,
		?array $fallback = null,
		?bool $throw    = null,
	): ?array {
		/** @var array<int, string>|null $result */
		$result = Util::to(
			value    : $value,
			to       : ColorSpace::HexRgb,
			from     : $from,
			fallback : $fallback,
			throw    : $throw,
		);

		return $result;
	}

	public static function stringify(
		string $red,
		string $green,
		string $blue,
		string $opacity   = 'FF',
		?bool $alpha     = null,
		bool $short     = true,
		?bool $uppercase = null,
		bool $sharp     = true,
	): string {
		$red   = Util::cleanHexValue($red);
		$green = Util::cleanHexValue($green);
		$blue  = Util::cleanHexValue($blue);
		$value = $red . $green . $blue;

		if ($alpha ?? (\strtoupper($opacity) !== 'FF')) {
			$value .= $opacity;
		}

		$value = match ($uppercase) {
			true    => \strtoupper($value),
			false   => \strtolower($value),
			default => $value,
		};

		$initials = [];

		foreach (\str_split($value, 2) as $v) {
			if ($v[0] === $v[1]) {
				$initials[] = $v[0];
			} else {
				$short = false;
				break;
			}
		}

		if ($short) {
			$value = \implode('', $initials);
		}

		return $sharp
			? "#$value"
			: $value
		;
	}

	public static function verify(
		mixed $value,
	): bool {
		return \is_string($value) && \preg_match(
			pattern : '/^#?[0-9A-Fa-f]{3,8}$/',
			subject : $value,
		);
	}

	/**
	 * @return array<int, float|int>
	 */
	public static function toHsl(
		string $red     = '00',
		string $green   = '00',
		string $blue    = '00',
		string $opacity = 'FF',
	): array {
		return Rgb::toHsl(...self::toRgb($red, $green, $blue, $opacity));
	}

	/**
	 * @return array<int, float|int>
	 */
	public static function toLinRgb(
		string $red     = '00',
		string $green   = '00',
		string $blue    = '00',
		string $opacity = 'FF',
	): array {
		return Rgb::toLinRgb(...self::toRgb($red, $green, $blue, $opacity));
	}

	/**
	 * @return array<int, float|int>
	 */
	public static function toOkLab(
		string $red     = '00',
		string $green   = '00',
		string $blue    = '00',
		string $opacity = 'FF',
	): array {
		return XyzD65::toOkLab(...self::toXyzD65($red, $green, $blue, $opacity));
	}

	/**
	 * @return array<int, float|int>
	 */
	public static function toOkLch(
		string $red     = '00',
		string $green   = '00',
		string $blue    = '00',
		string $opacity = 'FF',
	): array {
		return OkLab::toOkLch(...self::toOkLab($red, $green, $blue, $opacity));
	}

	/**
	 * @return array<int, float>
	 */
	public static function toRgb(
		string $red     = '00',
		string $green   = '00',
		string $blue    = '00',
		string $opacity = 'FF',
	): array {
		return [
			Util::hexToDec($red),
			Util::hexToDec($green),
			Util::hexToDec($blue),
			Util::hexToDec($opacity),
		];
	}

	/**
	 * @return array<int, float|int>
	 */
	public static function toXyzD65(
		string $red     = '00',
		string $green   = '00',
		string $blue    = '00',
		string $opacity = 'FF',
	): array {
		return LinRgb::toXyzD65(...self::toLinRgb($red, $green, $blue, $opacity));
	}
}
