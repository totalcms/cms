<?php

namespace TotalCMS\Domain\Property\Data;

use matthieumastadenis\couleur\ColorFactory;
use matthieumastadenis\couleur\colors\Hsl;
use matthieumastadenis\couleur\colors\OkLch;
use matthieumastadenis\couleur\colors\Rgb;
use matthieumastadenis\couleur\ColorSpace;

use function matthieumastadenis\couleur\utils\okLch\oklchChange;
use function matthieumastadenis\couleur\utils\okLch\oklchToHex;

/**
 * Color property data.
 */
class ColorData extends PropertyData implements \Stringable
{
	public string $hex;
	/** @var array<string,float> */
	public array $oklch;

	/** @param string|array<string,mixed> $color */
	public function __construct(string|array $color = '', public array $settings = [])
	{
		if (is_string($color)) {
			// Handle empty string with default black color
			if ($color === '') {
				$this->hex   = '#000000';
				$this->oklch = self::hexToOklch($this->hex);

				return;
			}

			// If it's an OKLCH string, preserve the color space by extracting OKLCH directly
			if (str_starts_with($color, 'oklch')) {
				$this->oklch = $this->oklchStringToArray($color);
				$this->hex   = self::oklchToHex($this->oklch);

				return;
			}

			// For other color formats, convert to hex then derive OKLCH
			$this->hex   = $this->stringToHex($color);
			$this->oklch = self::hexToOklch($this->hex);

			return;
		}

		// If OKLCH values are provided, use them to generate hex (preserving color space)
		if (isset($color['oklch']) && is_array($color['oklch'])) {
			$this->oklch = $color['oklch'];
			$this->hex   = self::oklchToHex($this->oklch);

			return;
		}

		// Fallback to hex-first approach
		$this->hex   = $color['hex'] ?? '#000000';
		$this->oklch = self::hexToOklch($this->hex);
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

	/**
	 * Extract OKLCH values from string to array format, preserving color space.
	 * Supports both modern (space-separated) and legacy (comma-separated) syntax.
	 *
	 * @return array<string,float>
	 */
	private function oklchStringToArray(string $color): array
	{
		// Remove oklch() wrapper and trim
		$values = preg_replace('/oklch\s*\(\s*|\s*\)/', '', $color);
		if (!is_string($values)) {
			return ['l' => 0, 'c' => 0, 'h' => 0]; // fallback
		}

		// Handle both modern (space/percentage) and legacy (comma) syntax
		// Modern: "54.319% 0.098 153.311"
		// Legacy: "54.319, 0.098, 153.311"
		$parts = str_contains($values, ',') ? explode(',', $values) : preg_split('/\s+/', trim($values));

		if (!is_array($parts) || count($parts) < 3) {
			return ['l' => 0, 'c' => 0, 'h' => 0]; // fallback
		}

		// Parse values, handling percentages
		$lightness = (float)str_replace('%', '', trim($parts[0]));
		$chroma    = (float)trim($parts[1]);
		$hue       = (float)trim($parts[2]);

		// Keep lightness in 0-100 range for external library compatibility
		// Don't convert percentages to 0-1 range - the library expects 0-100

		return [
			'l' => $lightness,
			'c' => $chroma,
			'h' => $hue,
		];
	}

	private function oklchStringToHex(string $color): string
	{
		$oklchArray = $this->oklchStringToArray($color);

		return self::oklchToHex($oklchArray);
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
			return $this->oklchStringToHex($color);
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
		return sprintf('oklch(%s%% %s %s)', $this->oklch['l'], $this->oklch['c'], $this->oklch['h']);
	}
}
