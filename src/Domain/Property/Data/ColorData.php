<?php

namespace TotalCMS\Domain\Property\Data;

use matthieumastadenis\couleur\ColorFactory;
use matthieumastadenis\couleur\ColorSpace;

/**
 * Color property data.
 */
class ColorData extends PropertyData
{
	public string $hex;
	/** @var array<string,float> */
	public array $oklch;

	/** @param string|array<string,mixed> $color */
	public function __construct(string|array $color, public array $settings = [])
	{
		if (is_string($color)) {
			$this->hex   = self::stringToHex($color);
			$this->oklch = self::hexToOklch($this->hex);

			return;
		}

		$this->hex   = $color['hex'] ?? '#000000';
		$this->oklch = $color['oklch'] ?? self::hexToOklch($this->hex);
	}

	private static function rgbToHex(string $color): ?string
	{
		$rgb = preg_replace('/[^0-9,]/', '', $color);
		if (is_string($rgb)) {
			$rgb = explode(',', $rgb);
			$hex = sprintf('#%02x%02x%02x', ...$rgb);

			return $hex;
		}

		return null;
	}

	private static function hslToHex(string $color): ?string
	{
		$hsl = preg_replace('/[^0-9,]/', '', $color);
		if (is_string($hsl)) {
			$hsl = explode(',', $hsl);
			$rgb = ColorFactory::newRgb($hsl, ColorSpace::Hsl);
			if ($rgb === null) {
				return '#000000'; // black
			}
			$coordinates = $rgb->coordinates();
			$hex         = sprintf('#%02x%02x%02x', ...$coordinates);

			return $hex;
		}

		return null;
	}

	private static function oklchStringToHex(string $color): ?string
	{
		$oklch = preg_replace('/[^0-9,]/', '', $color);
		if (is_string($oklch)) {
			$oklch = explode(',', $oklch);
			$rgb   = ColorFactory::newRgb($oklch, ColorSpace::OkLch);
			if ($rgb === null) {
				return '#000000'; // black
			}
			$coordinates = $rgb->coordinates();
			$hex         = sprintf('#%02x%02x%02x', ...$coordinates);

			return $hex;
		}

		return null;
	}

	private static function stringToHex(string $color): string
	{
		if (preg_match('/^#?([a-f0-9]{3}|[a-f0-9]{6})$/i', $color)) {
			return $color;
		}
		if (str_starts_with($color, 'rgb')) {
			$hex = self::rgbToHex($color);
			if ($hex !== null) {
				return $hex;
			}
		}
		if (str_starts_with($color, 'hsl')) {
			$hex = self::hslToHex($color);
			if ($hex !== null) {
				return $hex;
			}
		}
		if (str_starts_with($color, 'oklch')) {
			$hex = self::oklchStringToHex($color);
			if ($hex !== null) {
				return $hex;
			}
		}
		throw new \InvalidArgumentException('Invalid color format');
	}

	/** @param array<string,float> $oklch */
	public static function oklchToHex(array $oklch): string
	{
		// Convert OKLCH to RGB first to avoid ColorFactory hex issues
		$rgb = ColorFactory::newRgb([$oklch['l'], $oklch['c'], $oklch['h']], ColorSpace::OkLch);
		if ($rgb === null) {
			return '#000000'; // black
		}
		$coordinates = $rgb->coordinates();

		// Manually format hex to avoid ColorFactory stringify issues
		$r = max(0, min(255, round($coordinates[0])));
		$g = max(0, min(255, round($coordinates[1])));
		$b = max(0, min(255, round($coordinates[2])));

		return sprintf('#%02x%02x%02x', $r, $g, $b);
	}

	/**
	 * @param array<string,float> $oklch
	 * @param array<string,mixed> $change
	 *
	 * @return array<string,float>
	 */
	public static function oklchChange(array $oklch, array $change): array
	{
		$oklchColor = ColorFactory::newOkLch([$oklch['l'], $oklch['c'], $oklch['h']], ColorSpace::OkLch);
		if ($oklchColor === null) {
			return ['l' => 0, 'c' => 0, 'h' => 0]; // black
		}

		// ColorFactory library doesn't handle hue wrapping - only change hue if specified
		$updatedHue = $oklch['h'];
		if ($change['h'] !== null) {
			$updatedHue = self::changeHue($oklch['h'], $change['h']);
		}

		$oklchColor = $oklchColor->change(
			lightness: $change['l'] ?? null,
			chroma: $change['c'] ?? null,
			hue: null
		);
		$coordinates = array_map(fn ($c) => round($c, 3), $oklchColor->coordinates());

		return [
			'l' => $coordinates[0],
			'c' => $coordinates[1],
			'h' => $updatedHue,
		];
	}

	private static function changeHue(int|float $hue, string $formula): float
	{
		// possible formulas: "+10", "-20", "*2", "/3"

		$formula   = trim($formula);
		$operation = substr($formula, 0, 1);
		$value     = floatval(substr($formula, 1));

		$hue = match ($operation) {
			'+'     => $hue + $value,
			'-'     => $hue - $value,
			'*'     => $hue * $value,
			'/'     => $hue / $value,
			default => $hue,
		};
		if ($hue < 0 || $hue >= 360) {
			$hue = fmod($hue, 360);
			if ($hue < 0) {
				$hue += 360;
			}
		}

		return round($hue, 3);
	}

	/** @return array<string,float> */
	public static function hexToOklch(string $hex): array
	{
		$oklch = ColorFactory::newOkLch($hex, ColorSpace::HexRgb);
		if ($oklch === null) {
			return ['l' => 0, 'c' => 0, 'h' => 0]; // black
		}
		$coordinates = array_map(fn ($c) => round($c, 3), $oklch->coordinates());

		return [
			'l' => $coordinates[0],
			'c' => $coordinates[1],
			'h' => $coordinates[2],
		];
	}

	/** @return array<string,int> */
	public static function hexToRgb(string $hex): array
	{
		$rgb = ColorFactory::newRgb($hex, ColorSpace::HexRgb);
		if ($rgb === null) {
			return ['r' => 0, 'g' => 0, 'b' => 0]; // black
		}
		$coordinates = $rgb->coordinates();

		return [
			'r' => $coordinates[0],
			'g' => $coordinates[1],
			'b' => $coordinates[2],
		];
	}

	/** @return array<string,float> */
	public static function hexToHsl(string $hex): array
	{
		$hsl = ColorFactory::newHsl($hex, ColorSpace::HexRgb);
		if ($hsl === null) {
			return ['h' => 0, 's' => 0, 'l' => 0]; // black
		}
		$coordinates = array_map(fn ($c) => round($c, 3), $hsl->coordinates());

		return [
			'h' => $coordinates[0],
			's' => $coordinates[1],
			'l' => $coordinates[2],
		];
	}

	/** @return array<string,mixed> */
	public function transform(): array
	{
		return [
			'hex'   => $this->hex,
			'oklch' => $this->oklch,
		];
	}

	public function __toString(): string
	{
		return sprintf('oklch(%0.3f%% %0.3f %0.3f)', $this->oklch['l'], $this->oklch['c'], $this->oklch['h']);
	}
}
