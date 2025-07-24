<?php

namespace TotalCMS\Domain\ImageWorks\Data;

use TotalCMS\Domain\ImageWorks\Service\TextWatermark;

/**
 * Simple watermark data object with standard Glide watermark properties.
 */
final class Watermark
{
	public function __construct(
		public readonly ?string $mark = null,
		public readonly ?string $markpos = null,
		public readonly ?string $markw = null,
		public readonly ?string $markh = null,
		public readonly ?string $markx = null,
		public readonly ?string $marky = null,
		public readonly ?string $markfit = null,
		public readonly ?string $markpad = null,
	) {
	}

	/**
	 * Create a watermark from text parameters.
	 *
	 * @param array<string,mixed> $params Text watermark parameters
	 * @param TextWatermark $textWatermark Service to generate text watermark
	 * @return self
	 */
	public static function fromTextParams(array $params, TextWatermark $textWatermark): self
	{
		// Generate the text watermark image
		$textWatermarkPath = $textWatermark->generateTextWatermark($params);

		return new self(
			mark: $textWatermarkPath,
			markpos: $params['marktextpos'] ?? 'bottom-left',
			markw: $params['marktextw'] ?? '100w',
			markh: $params['marktexth'] ?? null,
			markx: $params['marktextx'] ?? null,
			marky: $params['marktexty'] ?? null,
			markfit: $params['marktextfit'] ?? null,
			markpad: $params['marktextpad'] ?? null,
		);
	}

	/**
	 * Create a watermark from image parameters.
	 *
	 * @param array<string,mixed> $params Image watermark parameters
	 * @return self|null
	 */
	public static function fromImageParams(array $params): ?self
	{
		if (!isset($params['mark']) || empty($params['mark'])) {
			return null;
		}

		return new self(
			mark: $params['mark'],
			markpos: $params['markpos'] ?? 'bottom-right',
			markw: $params['markw'] ?? '100w',
			markh: $params['markh'] ?? null,
			markx: $params['markx'] ?? null,
			marky: $params['marky'] ?? null,
			markfit: $params['markfit'] ?? null,
			markpad: $params['markpad'] ?? null,
		);
	}

	/**
	 * Convert watermark to array for Glide parameters.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		$params = [];

		if ($this->mark !== null) {
			$params['mark'] = $this->mark;
		}
		if ($this->markpos !== null) {
			$params['markpos'] = $this->markpos;
		}
		if ($this->markw !== null) {
			$params['markw'] = $this->markw;
		}
		if ($this->markh !== null) {
			$params['markh'] = $this->markh;
		}
		if ($this->markx !== null) {
			$params['markx'] = $this->markx;
		}
		if ($this->marky !== null) {
			$params['marky'] = $this->marky;
		}
		if ($this->markfit !== null) {
			$params['markfit'] = $this->markfit;
		}
		if ($this->markpad !== null) {
			$params['markpad'] = $this->markpad;
		}

		return $params;
	}

	/**
	 * Check if this watermark has any data.
	 */
	public function isEmpty(): bool
	{
		return $this->mark === null;
	}
}