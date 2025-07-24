<?php

namespace TotalCMS\Domain\ImageWorks\Data;

/**
 * Result object containing processed watermark data.
 * 
 * Encapsulates the results of watermark processing, including
 * watermark objects and cleaned parameters.
 */
final class WatermarkResult
{
	/**
	 * @param array<string,mixed> $cleanedParams
	 */
	public function __construct(
		public readonly ?Watermark $imageWatermark,
		public readonly ?Watermark $textWatermark,
		public readonly array $cleanedParams,
	) {
	}

	/**
	 * Check if there are any watermarks.
	 */
	public function hasWatermarks(): bool
	{
		return $this->imageWatermark !== null || $this->textWatermark !== null;
	}

	/**
	 * Check if there's an image watermark.
	 */
	public function hasImageWatermark(): bool
	{
		return $this->imageWatermark !== null;
	}

	/**
	 * Check if there's a text watermark.
	 */
	public function hasTextWatermark(): bool
	{
		return $this->textWatermark !== null;
	}

	/**
	 * Check if sequential processing is needed (both image and text watermarks).
	 */
	public function needsSequentialProcessing(): bool
	{
		return $this->imageWatermark !== null && $this->textWatermark !== null;
	}

	/**
	 * Get the primary watermark (first to be applied).
	 * 
	 * @return Watermark|null
	 */
	public function getPrimaryWatermark(): ?Watermark
	{
		if ($this->needsSequentialProcessing()) {
			return $this->imageWatermark; // Image watermark goes first
		}

		return $this->imageWatermark ?? $this->textWatermark;
	}

	/**
	 * Get the secondary watermark (second to be applied).
	 * 
	 * @return Watermark|null
	 */
	public function getSecondaryWatermark(): ?Watermark
	{
		if ($this->needsSequentialProcessing()) {
			return $this->textWatermark; // Text watermark goes second
		}

		return null;
	}

	/**
	 * Get parameters for the primary watermark pass.
	 *
	 * @return array<string,mixed>
	 */
	public function getPrimaryPassParams(): array
	{
		$primaryWatermark = $this->getPrimaryWatermark();
		if ($primaryWatermark === null) {
			return $this->cleanedParams;
		}

		return array_merge($this->cleanedParams, $primaryWatermark->getPositioningParams());
	}

	/**
	 * Get parameters for the secondary watermark pass.
	 *
	 * @return array<string,mixed>
	 */
	public function getSecondaryPassParams(): array
	{
		$secondaryWatermark = $this->getSecondaryWatermark();
		if ($secondaryWatermark === null) {
			return [];
		}

		return $secondaryWatermark->getPositioningParams();
	}

	/**
	 * Get the watermark path prefix for Glide configuration.
	 */
	public function getWatermarkPathPrefix(string $galleryWatermarkPath): string
	{
		$primaryWatermark = $this->getPrimaryWatermark();
		if ($primaryWatermark === null) {
			return '.watermarks'; // Default fallback
		}

		return $primaryWatermark->getPathPrefix($galleryWatermarkPath);
	}
}