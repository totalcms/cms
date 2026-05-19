<?php

namespace TotalCMS\Utils\Color\Colors;

use TotalCMS\Utils\Color\Color;
use TotalCMS\Utils\Color\ColorFactory;
use TotalCMS\Utils\Color\ColorInterface;
use TotalCMS\Utils\Color\Converters\OkLab as OkLabConverter;
use TotalCMS\Utils\Color\Util;

class OkLab extends Color implements ColorInterface
{
	/* #region Constructor */

	public function __construct(
		public readonly float $lightness = 0,
		public readonly float $a         = 0,
		public readonly float $b         = 0,
		public readonly float $opacity   = 100,
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
			'oklab',
			'ok-lab',
			'ok_lab',
		];
	}

	/* #endregion */

	/* #region Public Methods */

	public function change(
		\Stringable|string|int|float|null $lightness  = null,
		\Stringable|string|int|float|null $a          = null,
		\Stringable|string|int|float|null $b          = null,
		\Stringable|string|int|float|null $opacity    = null,
		?OkLab $fallback   = null,
		?bool $throw      = null,
	): OkLab {
		$changeThrow = $throw ?? true;

		/** @var OkLab $result */
		$result = ColorFactory::newOkLab(
			value    : [
				Util::changeCoordinate($this->lightness, $lightness, false, $changeThrow),
				Util::changeCoordinate($this->a, $a, false, $changeThrow),
				Util::changeCoordinate($this->b, $b, false, $changeThrow),
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
		return OkLabConverter::stringify(
			lightness : $this->lightness,
			a         : $this->a,
			b         : $this->b,
			opacity   : $this->opacity,
			legacy    : $legacy,
			alpha     : $alpha,
			precision : $precision,
		);
	}

	/* #endregion */
}
