<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Filter;

/**
 * A 3x4 color matrix: three rows (R, G, B), each with weights for R, G, B
 * and an offset in 0-1 units. The factories are the W3C Filter Effects
 * Module Level 1 shorthand matrices, applied directly to sRGB values, so a
 * chain of linear steps folds into one matrix and one pass over the pixels.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") a set of named W3C matrix factories, one method each
 */
final readonly class ColorMatrix
{
	/**
	 * @param list<list<float>> $rows
	 */
	private function __construct(private array $rows)
	{
	}

	public static function identity(): self
	{
		return new self([
			[1.0, 0.0, 0.0, 0.0],
			[0.0, 1.0, 0.0, 0.0],
			[0.0, 0.0, 1.0, 0.0],
		]);
	}

	public static function brightness(float $factor): self
	{
		return self::slopeIntercept($factor, 0.0);
	}

	public static function contrast(float $factor): self
	{
		return self::slopeIntercept($factor, 0.5 - 0.5 * $factor);
	}

	public static function fade(float $amount): self
	{
		return self::slopeIntercept(1 - $amount, $amount);
	}

	public static function saturate(float $s): self
	{
		return new self([
			[0.213 + 0.787 * $s, 0.715 - 0.715 * $s, 0.072 - 0.072 * $s, 0.0],
			[0.213 - 0.213 * $s, 0.715 + 0.285 * $s, 0.072 - 0.072 * $s, 0.0],
			[0.213 - 0.213 * $s, 0.715 - 0.715 * $s, 0.072 + 0.928 * $s, 0.0],
		]);
	}

	public static function grayscale(float $amount): self
	{
		$k = 1 - $amount;

		return new self([
			[0.2126 + 0.7874 * $k, 0.7152 - 0.7152 * $k, 0.0722 - 0.0722 * $k, 0.0],
			[0.2126 - 0.2126 * $k, 0.7152 + 0.2848 * $k, 0.0722 - 0.0722 * $k, 0.0],
			[0.2126 - 0.2126 * $k, 0.7152 - 0.7152 * $k, 0.0722 + 0.9278 * $k, 0.0],
		]);
	}

	public static function sepia(float $amount): self
	{
		$k = 1 - $amount;

		return new self([
			[0.393 + 0.607 * $k, 0.769 - 0.769 * $k, 0.189 - 0.189 * $k, 0.0],
			[0.349 - 0.349 * $k, 0.686 + 0.314 * $k, 0.168 - 0.168 * $k, 0.0],
			[0.272 - 0.272 * $k, 0.534 - 0.534 * $k, 0.131 + 0.869 * $k, 0.0],
		]);
	}

	public static function hueRotate(float $degrees): self
	{
		$rad = deg2rad($degrees);
		$cos = cos($rad);
		$sin = sin($rad);

		return new self([
			[0.213 + $cos * 0.787 - $sin * 0.213, 0.715 - $cos * 0.715 - $sin * 0.715, 0.072 - $cos * 0.072 + $sin * 0.928, 0.0],
			[0.213 - $cos * 0.213 + $sin * 0.143, 0.715 + $cos * 0.285 + $sin * 0.140, 0.072 - $cos * 0.072 - $sin * 0.283, 0.0],
			[0.213 - $cos * 0.213 - $sin * 0.787, 0.715 - $cos * 0.715 + $sin * 0.715, 0.072 + $cos * 0.928 + $sin * 0.072, 0.0],
		]);
	}

	/**
	 * Apply this matrix, then $next. Unlike applying them one after the other
	 * on pixels, the combined matrix does not clamp between the two.
	 */
	public function then(self $next): self
	{
		$rows = [];
		foreach ($next->rows as $nextRow) {
			$row = [];
			for ($col = 0; $col < 3; $col++) {
				$row[] = $nextRow[0] * $this->rows[0][$col]
					+ $nextRow[1] * $this->rows[1][$col]
					+ $nextRow[2] * $this->rows[2][$col];
			}
			$row[] = $nextRow[0] * $this->rows[0][3]
				+ $nextRow[1] * $this->rows[1][3]
				+ $nextRow[2] * $this->rows[2][3]
				+ $nextRow[3];
			$rows[] = $row;
		}

		return new self($rows);
	}

	/**
	 * @return array{int,int,int}
	 */
	public function applyTo(int $r, int $g, int $b): array
	{
		$out = [];
		foreach ($this->rows as $row) {
			$value = $row[0] * $r + $row[1] * $g + $row[2] * $b + $row[3] * 255;
			$out[] = (int) max(0, min(255, round($value)));
		}

		return [$out[0], $out[1], $out[2]];
	}

	/**
	 * @return list<list<float>>
	 */
	public function rows(): array
	{
		return $this->rows;
	}

	private static function slopeIntercept(float $slope, float $intercept): self
	{
		return new self([
			[$slope, 0.0, 0.0, $intercept],
			[0.0, $slope, 0.0, $intercept],
			[0.0, 0.0, $slope, $intercept],
		]);
	}
}
