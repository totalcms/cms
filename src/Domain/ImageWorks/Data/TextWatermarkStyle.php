<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Data;

use TotalCMS\Domain\ImageWorks\Service\WatermarkPatternBuilder;

/**
 * How a text watermark is drawn, read from the `marktext*` params.
 *
 * Outline and shadow are set as a percentage of the font size, so they keep
 * their look however far Glide scales the mark to fit the image.
 */
final readonly class TextWatermarkStyle
{
	/** Font size the mark is drawn at; Glide scales the result to fit. */
	public const DEFAULT_SIZE = 500;
	/** Wrapped text: lines allowed before the font size steps down. */
	public const DEFAULT_LINES = 3;
	public const ALIGNMENTS    = ['left', 'center', 'right'];

	/** Marks across the longer side of the image in tiled mode. */
	public const DEFAULT_TILES = WatermarkPatternBuilder::DEFAULT_TILES;
	/** Tiled marks run diagonally unless an angle is set. */
	public const DEFAULT_TILE_ANGLE = 30;

	public string $text;
	public int $fontSize;
	/** @var array{int<0,255>,int<0,255>,int<0,255>} */
	public array $color;
	public ?string $font;
	/** @var array{int<0,255>,int<0,255>,int<0,255>}|null */
	public ?array $background;
	public int $padding;
	public int $angle;
	/** Outline width in pixels at the drawn font size; 0 for none. */
	public int $stroke;
	/** @var array{int<0,255>,int<0,255>,int<0,255>} */
	public array $strokeColor;
	/** Shadow offset in pixels at the drawn font size; 0 for none. */
	public int $shadow;
	/** @var array{int<0,255>,int<0,255>,int<0,255>} */
	public array $shadowColor;
	/** Marks across the longer side when tiled; 0 for a single mark. */
	public int $tiles;
	/** Most lines wrapped text may take (see TextWatermarkFactory::draw). */
	public int $lines;
	/** How the lines of a block sit against each other: left, center or right. */
	public string $align;

	/** @var array<string,mixed> the `marktext*` params, to redraw at another size */
	private array $params;

	/** @param array<string,mixed> $params */
	public function __construct(array $params)
	{
		$text = (string)($params['marktext'] ?? '');
		if ($text === '') {
			throw new \InvalidArgumentException('Text watermark requires marktext parameter');
		}

		$this->params = array_filter(
			$params,
			fn (mixed $value, int|string $key): bool => str_starts_with((string)$key, 'marktext'),
			ARRAY_FILTER_USE_BOTH,
		);

		// A relative size (`6w`) is worked out against the image before it
		// gets here; one that was not, in a tiled pattern, draws at the default.
		$size           = $params['marktextsize'] ?? null;
		$this->text     = $text;
		$this->fontSize = is_numeric($size) ? max(1, (int)$size) : self::DEFAULT_SIZE;
		$this->color    = self::parseColor((string)($params['marktextcolor'] ?? 'ffffff'));
		$font           = (string)($params['marktextfont'] ?? '');
		$this->font     = $font === '' ? null : $font;

		$background       = (string)($params['marktextbg'] ?? '');
		$this->background = $background === '' ? null : self::parseColor($background);

		$this->tiles   = self::isTiled($params) ? WatermarkPatternBuilder::tiles($params['marktextrepeat'] ?? null) : 0;
		$this->padding = (int)($params['marktextpad'] ?? 10);
		$this->angle   = (int)($params['marktextangle'] ?? ($this->tiles > 0 ? self::DEFAULT_TILE_ANGLE : 0));

		$this->stroke      = $this->percentOfSize($params['marktextstroke'] ?? 0, $this->fontSize);
		$this->strokeColor = self::parseColor((string)($params['marktextstrokecolor'] ?? '000000'));
		$this->shadow      = $this->percentOfSize($params['marktextshadow'] ?? 0, $this->fontSize);
		$this->shadowColor = self::parseColor((string)($params['marktextshadowcolor'] ?? '000000'));

		$lines       = $params['marktextlines'] ?? null;
		$this->lines = is_numeric($lines) ? max(1, min(6, (int)$lines)) : self::DEFAULT_LINES;
		$align       = strtolower((string)($params['marktextalign'] ?? ''));
		$this->align = in_array($align, self::ALIGNMENTS, true) ? $align : 'left';
	}

	/** The same style at another font size, its outline and shadow scaled with it. */
	public function withFontSize(int $size): self
	{
		return new self(['marktextsize' => max(1, $size)] + $this->params);
	}

	/** @param array<string,mixed> $params */
	public static function isTiled(array $params): bool
	{
		return ($params['marktextpos'] ?? '') === 'tile';
	}

	/** File name of the rendered mark: the same style always lands on the same file. */
	public function cacheKey(): string
	{
		$key = hash('sha256', serialize(get_object_vars($this)));

		return 'text_watermark_' . substr($key, 0, 16);
	}

	/** Room the outline and shadow need beyond the text itself. */
	public function effectsMargin(): int
	{
		return $this->stroke + $this->shadow;
	}

	/**
	 * Parse a hex color, with or without `#`, 3 or 6 digits.
	 *
	 * @return array{int<0,255>,int<0,255>,int<0,255>}
	 */
	public static function parseColor(string $color): array
	{
		$color = ltrim($color, '#');

		if (strlen($color) === 3) {
			$color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
		}

		if (strlen($color) !== 6) {
			throw new \InvalidArgumentException('Invalid color format. Use hex format like "ffffff" or "#ffffff"');
		}

		return [
			self::channel(substr($color, 0, 2)),
			self::channel(substr($color, 2, 2)),
			self::channel(substr($color, 4, 2)),
		];
	}

	/** @return int<0,255> */
	private static function channel(string $hex): int
	{
		return max(0, min(255, (int)hexdec($hex)));
	}

	private function percentOfSize(mixed $percent, int $fontSize): int
	{
		$percent = is_numeric($percent) ? min(25.0, max(0.0, (float)$percent)) : 0.0;

		return (int)round($fontSize * $percent / 100);
	}
}
