<?php

namespace TotalCMS\Utils\Color\Colors;

use TotalCMS\Utils\Color\Color;
use TotalCMS\Utils\Color\ColorFactory;
use TotalCMS\Utils\Color\ColorInterface;
use TotalCMS\Utils\Color\Converters\LinRgb as LinRgbConverter;
use TotalCMS\Utils\Color\Util;

class LinRgb extends Color implements ColorInterface
{
	/* #region Constructor */

	public function __construct(
		public readonly float $red     = 0,
		public readonly float $green   = 0,
		public readonly float $blue    = 0,
		public readonly float $opacity = 1,
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
			'srgb-linear',
			'linrgb',
			'lin-rgb',
			'lin_rgb',
			'linsrgb',
			'lin-srgb',
			'lin_srgb',
		];
	}

	/* #endregion */

	/* #region Public Methods */

	public function change(
		\Stringable|string|int|float|null $red       = null,
		\Stringable|string|int|float|null $green     = null,
		\Stringable|string|int|float|null $blue      = null,
		\Stringable|string|int|float|null $opacity   = null,
		?LinRgb $fallback  = null,
		?bool $throw     = null,
	): LinRgb {
		$changeThrow = $throw ?? true;

		/** @var LinRgb $result */
		$result = ColorFactory::newLinRgb(
			value    : [
				Util::changeCoordinate($this->red, $red, false, $changeThrow),
				Util::changeCoordinate($this->green, $green, false, $changeThrow),
				Util::changeCoordinate($this->blue, $blue, false, $changeThrow),
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
		return LinRgbConverter::stringify(
			red       : $this->red,
			green     : $this->green,
			blue      : $this->blue,
			opacity   : $this->opacity,
			alpha     : $alpha,
			precision : $precision,
		);
	}

	/* #endregion */
}
