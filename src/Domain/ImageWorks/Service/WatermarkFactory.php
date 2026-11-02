<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Domain\ImageWorks\Data\TextWatermarkStyle;
use TotalCMS\Domain\ImageWorks\Data\Watermark;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

readonly class WatermarkFactory
{
	/** A text mark with no size set spans this much of the image width. */
	public const DEFAULT_TEXT_WIDTH = '40w';
	/** Tiled marks cover the whole image, so they default to faint. */
	public const DEFAULT_TILE_ALPHA = 30;

	public function __construct(
		private TextWatermarkFactory $textWatermarkFactory,
		private Config $config,
		private StorageAdapterInterface $filesystem,
	) {
	}

	public function watermarkPath(?string $watermarkGalleryId = null): string
	{
		$objectID = $watermarkGalleryId ?? $this->config->imageworks['watermarksGallery'];

		return sprintf('gallery/%s/gallery', $objectID);
	}

	/** @param array<string,mixed> $params watermark parameters */
	public function createImageWatermark(array $params = []): ?Watermark
	{
		if (!isset($params['mark']) || empty($params['mark'])) {
			return null;
		}

		return new Watermark(
			mark      : $params['mark'],
			markpos   : $params['markpos'] ?? 'bottom-right',
			markw     : $params['markw'] ?? '100w',
			markh     : $params['markh'] ?? null,
			markx     : $params['markx'] ?? null,
			marky     : $params['marky'] ?? null,
			markfit   : $params['markfit'] ?? null,
			markpad   : $params['markpad'] ?? null,
			markalpha : $params['markalpha'] ?? null,
			path      : $this->watermarkPath(),
		);
	}

	/**
	 * @param array<string,mixed> $params watermark parameters
	 * @param int $baseImageWidth width of the base image for auto-scaling check
	 */
	public function createTextWatermark(array $params = [], int $baseImageWidth = 0): ?Watermark
	{
		if (!isset($params['marktext']) || empty($params['marktext'])) {
			return null;
		}

		// Generate the text watermark image
		$textWatermarkPath = $this->textWatermarkFactory->generateTextWatermark($params);

		if (TextWatermarkStyle::isTiled($params)) {
			// The pattern covers the whole image and is cropped to its shape.
			return new Watermark(
				mark      : $textWatermarkPath,
				markpos   : 'center',
				markw     : '100w',
				markh     : '100h',
				markfit   : 'crop',
				markalpha : (string)($params['marktextalpha'] ?? self::DEFAULT_TILE_ALPHA),
				path      : TextWatermarkFactory::WATERMARK_DIR,
			);
		}

		return new Watermark(
			mark      : $textWatermarkPath,
			markpos   : $params['marktextpos'] ?? 'center',
			markw     : $this->textWidth($params, $textWatermarkPath, $baseImageWidth),
			markh     : $params['marktexth'] ?? null,
			markx     : $params['marktextx'] ?? null,
			marky     : $params['marktexty'] ?? null,
			markfit   : $params['marktextfit'] ?? null,
			markpad   : $params['marktextpad'] ?? null,
			markalpha : $params['marktextalpha'] ?? null,
			path      : TextWatermarkFactory::WATERMARK_DIR,
		);
	}

	/**
	 * How wide the text mark is drawn on the image:
	 * - `marktextw` when set (`40w` is 40% of the image width);
	 * - with only `marktextsize`, that fixed size, shrunk to 90% of the image
	 *   when it would be wider;
	 * - with neither, DEFAULT_TEXT_WIDTH, so the mark keeps its proportion on
	 *   a thumbnail and on the original alike.
	 *
	 * @param array<string,mixed> $params
	 */
	private function textWidth(array $params, string $textWatermarkPath, int $baseImageWidth): ?string
	{
		if (isset($params['marktextw'])) {
			return (string)$params['marktextw'];
		}

		if (!isset($params['marktextsize'])) {
			return self::DEFAULT_TEXT_WIDTH;
		}

		if ($baseImageWidth <= 0) {
			return null;
		}

		try {
			$watermarkSize = @getimagesizefromstring($this->filesystem->read(TextWatermarkFactory::WATERMARK_DIR . '/' . $textWatermarkPath));
			// Wider than the image: 90% leaves room for x/y offsets.
			if ($watermarkSize !== false && $watermarkSize[0] > $baseImageWidth) {
				return '90w';
			}
		} catch (\Exception) {
			// Silently fail - just don't auto-scale if we can't check dimensions
		}

		return null;
	}

	/**
	 * The image mark file's modified time, or null when it cannot be read. A
	 * logo is named by file name only; this is what tells a new logo.png
	 * from the old one.
	 */
	public function version(Watermark $imageMark): ?string
	{
		try {
			return (string)$this->filesystem->flysystem()->lastModified($imageMark->path . '/' . $imageMark->mark);
		} catch (\Throwable) {
			return null;
		}
	}
}
