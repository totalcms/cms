<?php

namespace TotalCMS\Utils\Color\Colors;

use TotalCMS\Utils\Color\Color;
use TotalCMS\Utils\Color\ColorFactory;
use TotalCMS\Utils\Color\ColorInterface;
use TotalCMS\Utils\Color\Converters\Hsl as HslConverter;
use TotalCMS\Utils\Color\Util;

class Hsl extends Color implements ColorInterface
{
	/* #region Constructor */

	public function __construct(
		public readonly float $hue        = 0,
		public readonly float $saturation = 0,
		public readonly float $lightness  = 0,
		public readonly float $opacity    = 100,
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
			'hsl',
			'hsla',
		];
	}

	/* #endregion */

	/* #region Public Methods */

	public function change(
		\Stringable|string|int|float|null $hue        = null,
		\Stringable|string|int|float|null $saturation = null,
		\Stringable|string|int|float|null $lightness  = null,
		\Stringable|string|int|float|null $opacity    = null,
		?Hsl $fallback   = null,
		?bool $throw      = null,
	): Hsl {
		$changeThrow = $throw ?? true;

		/** @var Hsl $result */
		$result = ColorFactory::newHsl(
			value    : [
				Util::changeCoordinate($this->hue, $hue, false, $changeThrow, true),
				Util::changeCoordinate($this->saturation, $saturation, false, $changeThrow),
				Util::changeCoordinate($this->lightness, $lightness, false, $changeThrow),
				Util::changeCoordinate($this->opacity, $opacity, false, $changeThrow),
			],
			from     : $this::space(),
			fallback : $fallback,
			throw    : $throw,
		);

		return $result;
	}

	public function stringify(
		?bool $legacy    = null,
		?bool $alpha     = null,
		?int $precision = null,
	): string {
		return HslConverter::stringify(
			hue        : $this->hue,
			saturation : $this->saturation,
			lightness  : $this->lightness,
			opacity    : $this->opacity,
			legacy     : $legacy,
			alpha      : $alpha,
			precision  : $precision,
		);
	}

	/* #endregion */
}
