<?php

namespace TotalCMS\Domain\ImageWorks\Data;

/**
 * Watermark data class that represents both image and text watermarks.
 * 
 * Centralizes watermark configuration and provides a clean API for 
 * watermark operations in the ImageWorks system.
 */
final class Watermark
{
	public const TYPE_IMAGE = 'image';
	public const TYPE_TEXT = 'text';

	/**
	 * @param array<string,mixed> $positioning
	 * @param array<string,mixed> $metadata
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $source,
		public readonly array $positioning = [],
		public readonly array $metadata = [],
	) {
	}

	/**
	 * Create an image watermark from parameters.
	 *
	 * @param array<string,mixed> $params
	 * @return self|null
	 */
	public static function createImageWatermark(array $params): ?self
	{
		if (!isset($params['mark']) || empty($params['mark'])) {
			return null;
		}

		$positioning = [];
		$metadata = [];

		// Extract standard watermark positioning parameters
		$positioningParams = ['markpos', 'markw', 'markh', 'markx', 'marky', 'markfit', 'markpad'];
		foreach ($positioningParams as $param) {
			if (isset($params[$param])) {
				$positioning[$param] = $params[$param];
			}
		}

		// Set default positioning if not specified
		if (!isset($positioning['markpos'])) {
			$positioning['markpos'] = 'bottom-right'; // Default for image watermarks
		}
		if (!isset($positioning['markw'])) {
			$positioning['markw'] = '100w'; // Default width
		}

		return new self(
			type: self::TYPE_IMAGE,
			source: $params['mark'],
			positioning: $positioning,
			metadata: $metadata
		);
	}

	/**
	 * Create a text watermark from parameters.
	 *
	 * @param array<string,mixed> $params
	 * @param string $textWatermarkPath Generated text watermark image path
	 * @return self|null
	 */
	public static function createTextWatermark(array $params, string $textWatermarkPath): ?self
	{
		if (!isset($params['marktext']) || empty($params['marktext'])) {
			return null;
		}

		$positioning = [];
		$metadata = [];

		// Extract text-specific positioning parameters and map to standard parameters
		if (isset($params['marktextpos'])) {
			$positioning['markpos'] = $params['marktextpos'];
		} else {
			$positioning['markpos'] = 'bottom-left'; // Different default for text
		}

		if (isset($params['marktextw'])) {
			$positioning['markw'] = $params['marktextw'];
		} else {
			$positioning['markw'] = '100w'; // Default width
		}

		if (isset($params['marktexth'])) {
			$positioning['markh'] = $params['marktexth'];
		}

		if (isset($params['marktextx'])) {
			$positioning['markx'] = $params['marktextx'];
		}

		if (isset($params['marktexty'])) {
			$positioning['marky'] = $params['marktexty'];
		}

		if (isset($params['marktextfit'])) {
			$positioning['markfit'] = $params['marktextfit'];
		}

		if (isset($params['marktextpad'])) {
			$positioning['markpad'] = $params['marktextpad'];
		}

		// Store text-specific metadata
		$metadata = [
			'text' => $params['marktext'],
			'fontSize' => $params['marktextsize'] ?? 24,
			'color' => $params['marktextcolor'] ?? 'ffffff',
			'font' => $params['marktextfont'] ?? null,
			'backgroundColor' => $params['marktextbg'] ?? null,
			'padding' => $params['marktextpad'] ?? 10,
			'angle' => $params['marktextangle'] ?? 0,
			'opacity' => $params['marktextalpha'] ?? 100,
		];

		return new self(
			type: self::TYPE_TEXT,
			source: $textWatermarkPath,
			positioning: $positioning,
			metadata: $metadata
		);
	}

	/**
	 * Get positioning parameters as an array suitable for Glide.
	 *
	 * @return array<string,mixed>
	 */
	public function getPositioningParams(): array
	{
		return array_merge(['mark' => $this->source], $this->positioning);
	}

	/**
	 * Check if this is an image watermark.
	 */
	public function isImageWatermark(): bool
	{
		return $this->type === self::TYPE_IMAGE;
	}

	/**
	 * Check if this is a text watermark.
	 */
	public function isTextWatermark(): bool
	{
		return $this->type === self::TYPE_TEXT;
	}

	/**
	 * Get the watermark path prefix for Glide configuration.
	 */
	public function getPathPrefix(string $galleryWatermarkPath): string
	{
		return $this->isTextWatermark() ? '.watermarks' : $galleryWatermarkPath;
	}

	/**
	 * Get all parameters that should be removed from the main params array.
	 *
	 * @return array<string>
	 */
	public static function getParametersToRemove(): array
	{
		return [
			// Image watermark parameters
			'mark', 'markpos', 'markw', 'markh', 'markx', 'marky', 'markfit', 'markpad',
			// Text watermark parameters
			'marktext', 'marktextsize', 'marktextcolor', 'marktextfont', 'marktextbg',
			'marktextpad', 'marktextangle', 'marktextalpha',
			'marktextpos', 'marktextw', 'marktexth', 'marktextx', 'marktexty', 'marktextfit',
		];
	}
}