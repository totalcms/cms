<?php

namespace TotalCMS\Domain\ImageWorks\Data;

use TotalCMS\Domain\ImageWorks\Service\TextWatermarkFactory;

/**
 * Simple watermark data object with standard Glide watermark properties.
 */
final class Watermark
{
	public function __construct(
		public readonly ?string $mark      = null,
		public readonly ?string $markpos   = null,
		public readonly ?string $markw     = null,
		public readonly ?string $markh     = null,
		public readonly ?string $markx     = null,
		public readonly ?string $marky     = null,
		public readonly ?string $markfit   = null,
		public readonly ?string $markpad   = null,
		public readonly ?string $markalpha = null,
		public readonly string $path       = TextWatermarkFactory::WATERMARK_DIR,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		$params = [
			'mark'      => $this->mark,
			'markpos'   => $this->markpos,
			'markw'     => $this->markw,
			'markh'     => $this->markh,
			'markx'     => $this->markx,
			'marky'     => $this->marky,
			'markfit'   => $this->markfit,
			'markpad'   => $this->markpad,
			'markalpha' => $this->markalpha,
		];

		return array_filter($params);
	}

	/**
	 * Check if this watermark has any data.
	 */
	public function isEmpty(): bool
	{
		return $this->mark === null;
	}

	/**
	 * Get positioning parameters for the watermark.
	 *
	 * @return array<string,mixed>
	 */
	public function getPositioningParams(): array
	{
		return $this->toArray();
	}

	/**
	 * Get the watermark path prefix.
	 *
	 * @param string $galleryWatermarkPath
	 *
	 * @return string
	 */
	public function getPathPrefix(string $galleryWatermarkPath): string
	{
		// Text watermarks always use .watermarks
		// Image watermarks use the gallery watermark path if the mark starts with mark-
		if ($this->mark !== null && str_starts_with($this->mark, 'text_watermark_')) {
			return '.watermarks';
		}

		if ($this->mark !== null && str_starts_with($this->mark, 'mark-')) {
			return $galleryWatermarkPath;
		}

		return '.watermarks';
	}

	/**
	 * Create an image watermark from parameters.
	 *
	 * @param array<string,mixed> $params
	 *
	 * @return self|null
	 */
	public static function createImageWatermark(array $params): ?self
	{
		return self::fromImageParams($params);
	}

	/**
	 * Create a text watermark from parameters.
	 *
	 * @param array<string,mixed> $params
	 * @param string $textWatermarkPath
	 *
	 * @return self
	 */
	public static function createTextWatermark(array $params, string $textWatermarkPath): self
	{
		return new self(
			mark: $textWatermarkPath,
			markpos: $params['marktextpos'] ?? 'bottom-left',
			markw: $params['marktextw'] ?? '100w',
			markh: $params['marktexth'] ?? null,
			markx: $params['marktextx'] ?? null,
			marky: $params['marktexty'] ?? null,
			markfit: $params['marktextfit'] ?? null,
			markpad: $params['marktextpad'] ?? null,
			markalpha: isset($params['marktextalpha']) ? (string)$params['marktextalpha'] : null,
		);
	}

	/**
	 * Get parameters to remove from the request.
	 *
	 * @return array<string>
	 */
	public static function getParametersToRemove(): array
	{
		return [
			// Image watermark parameters
			'mark',
			'markw',
			'markh',
			'markx',
			'marky',
			'markpos',
			'markfit',
			'markpad',
			'markalpha',
			// Text watermark parameters
			'marktext',
			'marktextsize',
			'marktextcolor',
			'marktextfont',
			'marktextbg',
			'marktextpad',
			'marktextangle',
			'marktextalpha',
			'marktextpos',
			'marktextw',
			'marktexth',
			'marktextx',
			'marktexty',
			'marktextfit',
		];
	}
}
