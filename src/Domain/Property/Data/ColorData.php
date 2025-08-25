<?php

namespace TotalCMS\Domain\Property\Data;

use matthieumastadenis\couleur\ColorFactory;
use matthieumastadenis\couleur\ColorSpace;
use \matthieumastadenis\couleur\colors\Rgb;
use \matthieumastadenis\couleur\colors\Hsl;
use \matthieumastadenis\couleur\colors\OkLch;

use function matthieumastadenis\couleur\utils\okLch\oklchChange;
use function matthieumastadenis\couleur\utils\okLch\oklchToHex;


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
			$this->hex   = $this->stringToHex($color);
			$this->oklch = self::hexToOklch($this->hex);

			return;
		}

		$this->hex   = $color['hex'] ?? '#000000';
		$this->oklch = $color['oklch'] ?? self::hexToOklch($this->hex);
	}

	private function rgbToHex(string $color): ?string
	{
		$rgb = preg_replace('/[^0-9,]/', '', $color);
		if (is_string($rgb)) {
			$rgb = explode(',', $rgb);

			return sprintf('#%02x%02x%02x', ...$rgb);
		}

		return null;
	}

	private function hslToHex(string $color): ?string
	{
		$hsl = preg_replace('/[^0-9,]/', '', $color);
		if (is_string($hsl)) {
			$hsl = explode(',', $hsl);
			$rgb = ColorFactory::newRgb($hsl, ColorSpace::Hsl);
			if (!$rgb instanceof Rgb) {
				return '#000000'; // black
			}
			$coordinates = $rgb->coordinates();

			return sprintf('#%02x%02x%02x', ...$coordinates);
		}

		return null;
	}

	private function oklchStringToHex(string $color): ?string
	{
		$oklch = preg_replace('/[^0-9,]/', '', $color);
		if (is_string($oklch)) {
			$oklch = explode(',', $oklch);
			$rgb   = ColorFactory::newRgb($oklch, ColorSpace::OkLch);
			if (!$rgb instanceof Rgb) {
				return '#000000'; // black
			}
			$coordinates = $rgb->coordinates();

			return sprintf('#%02x%02x%02x', ...$coordinates);
		}

		return null;
	}

	private function stringToHex(string $color): string
	{
		if (preg_match('/^#?([a-f0-9]{3}|[a-f0-9]{6})$/i', $color)) {
			return $color;
		}
		if (str_starts_with($color, 'rgb')) {
			$hex = $this->rgbToHex($color);
			if ($hex !== null) {
				return $hex;
			}
		}
		if (str_starts_with($color, 'hsl')) {
			$hex = $this->hslToHex($color);
			if ($hex !== null) {
				return $hex;
			}
		}
		if (str_starts_with($color, 'oklch')) {
			$hex = $this->oklchStringToHex($color);
			if ($hex !== null) {
				return $hex;
			}
		}
		throw new \InvalidArgumentException('Invalid color format');
	}

	/** @param array<string,float> $oklch */
	public static function oklchToHex(array $oklch): string
	{
		return oklchToHex($oklch);
	}

	/**
	 * @param array<string,float> $oklch
	 * @param array<string,mixed> $change
	 *
	 * @return array<string,float>
	 */
	public static function oklchChange(array $oklch, array $change): array
	{
		return oklchChange($oklch, $change);
	}

	/** @return array<string,float> */
	public static function hexToOklch(string $hex): array
	{
		$oklch = ColorFactory::newOkLch($hex, ColorSpace::HexRgb);
		if (!$oklch instanceof OkLch) {
			return ['l' => 0, 'c' => 0, 'h' => 0]; // black
		}
		$coordinates = array_map(fn ($c): float => round($c, 3), $oklch->coordinates());

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
		if (!$rgb instanceof Rgb) {
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
		if (!$hsl instanceof Hsl) {
			return ['h' => 0, 's' => 0, 'l' => 0]; // black
		}
		$coordinates = array_map(fn ($c): float => round($c, 3), $hsl->coordinates());

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
