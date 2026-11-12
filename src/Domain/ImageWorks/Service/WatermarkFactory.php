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

		if (($params['markpos'] ?? '') === 'tile') {
			return $this->tiledImageWatermark($params);
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
	 * The logo repeated over the whole image, like a tiled text mark. The
	 * pattern is drawn once into WATERMARK_DIR under a name made from the logo,
	 * its modified time and the tile count, so a replaced logo gets a new
	 * pattern (and, through version(), a new render cache). Null when the logo
	 * is missing or GD cannot read it, as Glide skips a mark it cannot find.
	 *
	 * @param array<string,mixed> $params watermark parameters
	 */
	private function tiledImageWatermark(array $params): ?Watermark
	{
		// Glide reads a logo through a filesystem rooted at the gallery; this
		// reads through the data root, so `..` would reach any image on the site
		// and stamp it, tiled, onto a public one.
		$mark = (string)$params['mark'];
		if (str_contains($mark, '..') || str_starts_with($mark, '/')) {
			return null;
		}

		$logoPath = $this->watermarkPath() . '/' . $mark;
		$tiles    = WatermarkPatternBuilder::tiles($params['markrepeat'] ?? null);

		try {
			$logoVersion = $this->filesystem->flysystem()->lastModified($logoPath);
		} catch (\Throwable) {
			return null;
		}

		$pattern = 'image_watermark_' . substr(hash('sha256', $mark . '|' . $logoVersion . '|' . $tiles), 0, 16) . '.png';
		if (!$this->filesystem->fileExists(TextWatermarkFactory::WATERMARK_DIR . '/' . $pattern) && !$this->drawImagePattern($logoPath, $tiles, $pattern)) {
			return null;
		}

		// The pattern covers the whole image and is cropped to its shape.
		return new Watermark(
			mark      : $pattern,
			markpos   : 'center',
			markw     : '100w',
			markh     : '100h',
			markfit   : 'crop',
			markalpha : (string)($params['markalpha'] ?? self::DEFAULT_TILE_ALPHA),
			path      : TextWatermarkFactory::WATERMARK_DIR,
		);
	}

	/** Tile the logo into a pattern PNG; false when the logo is not an image GD reads. */
	private function drawImagePattern(string $logoPath, int $tiles, string $pattern): bool
	{
		if (!function_exists('imagecreatefromstring')) {
			return false;
		}

		$logo = @imagecreatefromstring($this->filesystem->read($logoPath));
		if ($logo === false) {
			return false;
		}

		ob_start();
		$saved   = imagepng(WatermarkPatternBuilder::build($logo, $tiles));
		$content = (string)ob_get_clean();
		if (!$saved || $content === '') {
			return false;
		}

		$this->filesystem->write(TextWatermarkFactory::WATERMARK_DIR . '/' . $pattern, $content);

		return true;
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
