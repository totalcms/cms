<?php

namespace TotalCMS\Utils\Color\Colors;

use TotalCMS\Utils\Color\Color;
use TotalCMS\Utils\Color\ColorFactory;
use TotalCMS\Utils\Color\ColorInterface;
use TotalCMS\Utils\Color\Converters\HexRgb as HexRgbConverter;
use TotalCMS\Utils\Color\Util;

class HexRgb extends Color implements ColorInterface
{
	/* #region Constructor */

	public function __construct(
		public readonly string $red     = '00',
		public readonly string $green   = '00',
		public readonly string $blue    = '00',
		public readonly string $opacity = 'FF',
	) {
	}

	/* #endregion */

	/* #region Public Static Methods */

	/**
	 * @return array<int, string>
	 */
	public static function aliases(): array
	{
		return [
			'hex',
			'hexrgb',
			'hex-rgb',
			'hex_rgb',
			'hexadecimal',
		];
	}

	/* #endregion */

	/* #region Public Methods */

	public function change(
		\Stringable|string|null $red       = null,
		\Stringable|string|null $green     = null,
		\Stringable|string|null $blue      = null,
		\Stringable|string|null $opacity   = null,
		?HexRgb $fallback  = null,
		?bool $throw     = null,
	): HexRgb {
		$changeThrow = $throw ?? true;

		/** @var HexRgb $result */
		$result = ColorFactory::newHexRgb(
			value    : [
				Util::changeCoordinate($this->red, $red, true, $changeThrow),
				Util::changeCoordinate($this->green, $green, true, $changeThrow),
				Util::changeCoordinate($this->blue, $blue, true, $changeThrow),
				Util::changeCoordinate($this->opacity, $opacity, true, $changeThrow),
			],
			from     : $this::space(),
			fallback : $fallback,
			throw    : $throw,
		);

		return $result;
	}

	public function stringify(
		?bool $alpha     = null,
		bool $short     = true,
		?bool $uppercase = null,
		bool $sharp     = true,
	): string {
		return HexRgbConverter::stringify(
			red       : $this->red,
			green     : $this->green,
			blue      : $this->blue,
			opacity   : $this->opacity,
			alpha     : $alpha,
			short     : $short,
			uppercase : $uppercase,
			sharp     : $sharp,
		);
	}

	/* #endregion */
}
