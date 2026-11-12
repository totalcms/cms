<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Domain\ImageWorks\Data\ImageLocation;
use TotalCMS\Domain\ImageWorks\Data\TextWatermarkStyle;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Turns the mark layers (see MarkLayers) into what MarkLayersManipulator
 * draws: each file found and stamped with its version, each tiled mark
 * drawn into its pattern, each record's image looked up. A layer whose file
 * is missing is left out, as Glide left out a mark it could not find.
 */
readonly class WatermarkFactory
{
	/** A text mark with no size set spans this much of the image width. */
	public const DEFAULT_TEXT_WIDTH = '40w';
	/** Tiled marks cover the whole image, so they default to faint. */
	public const DEFAULT_TILE_ALPHA = 30;

	/** A fill is half see-through unless you set its opacity. */
	public const DEFAULT_FILL_ALPHA = 50;

	/** Where Glide placed a mark with no position. */
	private const DEFAULT_POSITION = 'bottom-right';
	/** A text mark with no position sits in the middle. */
	private const DEFAULT_TEXT_POSITION = 'center';

	/** The keys that place and size a mark on the image. */
	private const PLACEMENT = ['markw', 'markh', 'markx', 'marky', 'markpad', 'markfit', 'markpos', 'markalpha'];

	public function __construct(
		private TextWatermarkFactory $textWatermarkFactory,
		private Config $config,
		private StorageAdapterInterface $filesystem,
		// Required, not optional: PHP-DI's autowiring skips optional
		// parameters, so a `= null` here was always null in the app.
		private ImageSourceResolver $sources,
		private LoggerFactory $loggerFactory,
	) {
	}

	public function watermarkPath(?string $watermarkGalleryId = null): string
	{
		$objectID = $watermarkGalleryId ?? $this->config->imageworks['watermarksGallery'];

		return sprintf('gallery/%s/gallery', $objectID);
	}

	/** The manipulator that draws prepared layers, reading files from the data root. */
	public function manipulator(): MarkLayersManipulator
	{
		return new MarkLayersManipulator(
			$this->filesystem->flysystem(),
			$this->textWatermarkFactory,
			$this->loggerFactory->channelLogger(LogChannel::ImageGenerator),
		);
	}

	/**
	 * The layers ready to draw, in order.
	 *
	 * @param list<array<string,mixed>> $layers from MarkLayers
	 * @param array<string> $palette the image's colors, for `palette0`-`palette4` fills
	 *
	 * @return list<array<string,int|float|string>>
	 */
	public function prepare(array $layers, array $palette = []): array
	{
		$prepared = [];

		foreach ($layers as $layer) {
			$ready = match ($layer['kind'] ?? null) {
				MarkLayers::LOGO  => $this->logoLayer($layer),
				MarkLayers::IMAGE => $this->imageLayer($layer),
				MarkLayers::TEXT  => $this->textLayer($layer),
				MarkLayers::FILL  => $this->fillLayer($layer, $palette),
				default           => null,
			};

			if ($ready !== null) {
				$prepared[] = $ready;
			}
		}

		return $prepared;
	}

	/**
	 * A logo from the watermarks gallery.
	 *
	 * @param array<string,mixed> $layer
	 *
	 * @return array<string,int|float|string>|null
	 */
	private function logoLayer(array $layer): ?array
	{
		$mark = (string)$layer['mark'];
		// Glide read a logo through a filesystem rooted at the gallery; this
		// reads through the data root, so `..` would reach any file on the site.
		if (str_contains($mark, '..') || str_starts_with($mark, '/')) {
			return null;
		}

		$path = $this->watermarkPath() . '/' . $mark;

		if (($layer['markpos'] ?? '') === 'tile') {
			return $this->tiledLayer($path, $layer['markrepeat'] ?? null, $layer['markalpha'] ?? null);
		}

		return $this->fileLayer($path, $layer, self::DEFAULT_POSITION);
	}

	/**
	 * An image on a record: `collection/id/property`, or a gallery image as
	 * `collection/id/property/name` (`first` and `last` work too). With
	 * `markshape: circle` it is cut square around its focal point, then round.
	 *
	 * @param array<string,mixed> $layer
	 *
	 * @return array<string,int|float|string>|null
	 */
	private function imageLayer(array $layer): ?array
	{
		$target = (string)$layer['markimage'];
		$parts  = explode('/', $target);
		if (str_contains($target, '..') || count($parts) < 3 || count($parts) > 4 || in_array('', $parts, true)) {
			return null;
		}

		[$collection, $id, $property] = $parts;
		$imageData                    = $this->sources->markImage($collection, $id, $property, $parts[3] ?? null);
		if ($imageData === null) {
			return null;
		}

		$path = (new ImageLocation($collection, $id, $property))->path($imageData->name);

		if (($layer['markpos'] ?? '') === 'tile') {
			return $this->tiledLayer($path, $layer['markrepeat'] ?? null, $layer['markalpha'] ?? null);
		}

		$circle = ($layer['markshape'] ?? '') === 'circle';
		if ($circle) {
			$layer['markh'] ??= $layer['markw'];
			$layer['markfit'] ??= 'crop';
		}

		$ready = $this->fileLayer($path, $layer, self::DEFAULT_POSITION);
		if ($ready === null) {
			return null;
		}

		$fit = (string)($layer['markfit'] ?? '');
		if ($fit === 'crop' || $fit === 'crop-focalpoint') {
			$ready['focalfit'] = GlideFactory::cropFocalpoint('crop-focalpoint', $imageData->focalpoint);
		}

		if ($circle) {
			$ready['markshape'] = 'circle';
		}

		return $ready;
	}

	/**
	 * A text layer. A tiled one becomes its pattern file; any other is drawn
	 * by the manipulator, at the width it asks for:
	 * - `marktextw` when set (`40w` is 40% of the image width);
	 * - with only `marktextsize`, that fixed size, shrunk to 90% of the image
	 *   when it would be wider;
	 * - with neither, DEFAULT_TEXT_WIDTH, so the mark keeps its proportion on
	 *   a thumbnail and on the original alike.
	 *
	 * @param array<string,mixed> $layer
	 *
	 * @return array<string,int|float|string>|null
	 */
	private function textLayer(array $layer): ?array
	{
		if (TextWatermarkStyle::isTiled($layer)) {
			try {
				$pattern = $this->textWatermarkFactory->generateTextWatermark($layer);
			} catch (\Throwable) {
				return null;
			}

			return $this->tiledFile(TextWatermarkFactory::WATERMARK_DIR . '/' . $pattern, '', $layer['marktextalpha'] ?? null);
		}

		$text = array_filter(
			$layer,
			fn (mixed $value, string $key): bool => str_starts_with($key, 'marktext') && is_scalar($value),
			ARRAY_FILTER_USE_BOTH,
		);

		$placement = array_filter([
			'markpos'   => $layer['marktextpos'] ?? self::DEFAULT_TEXT_POSITION,
			'markw'     => $layer['marktextw'] ?? (isset($layer['marktextsize']) ? null : self::DEFAULT_TEXT_WIDTH),
			'markh'     => $layer['marktexth'] ?? null,
			'markx'     => $layer['marktextx'] ?? null,
			'marky'     => $layer['marktexty'] ?? null,
			'markfit'   => $layer['marktextfit'] ?? null,
			'markpad'   => $layer['marktextpad'] ?? null,
			'markalpha' => $layer['marktextalpha'] ?? null,
		], fn (mixed $value): bool => $value !== null && $value !== '');

		if (!isset($layer['marktextw']) && isset($layer['marktextsize'])) {
			$placement['fitwidth'] = 1;
		}

		return $text + $placement;
	}

	/**
	 * A color over the image or a band of it, flat or fading out from one
	 * edge. The color is hex, or one of the image's own palette colors.
	 *
	 * @param array<string,mixed> $layer
	 * @param array<string> $palette
	 *
	 * @return array<string,int|float|string>|null
	 */
	private function fillLayer(array $layer, array $palette): ?array
	{
		$color = (string)$layer['markfill'];
		if (str_starts_with($color, GlideFactory::PALETTE)) {
			if ($palette === []) {
				return null;
			}

			$color = GlideFactory::updateBackgroundColor($color, $palette);
		}

		try {
			$rgb = TextWatermarkStyle::parseColor($color);
		} catch (\InvalidArgumentException) {
			return null;
		}

		$ready = ['fill' => sprintf('%02x%02x%02x', ...$rgb), 'markpos' => 'center', 'markalpha' => self::DEFAULT_FILL_ALPHA];
		foreach (self::PLACEMENT as $key) {
			$value = $layer[$key] ?? null;
			if ((is_string($value) && $value !== '') || is_int($value) || is_float($value)) {
				$ready[$key] = $value;
			}
		}

		$gradient = $layer['markgradient'] ?? null;
		if (in_array($gradient, ['top', 'bottom', 'left', 'right'], true)) {
			$ready['gradient'] = $gradient;
		}

		return $ready;
	}

	/**
	 * A mark read from a file, with its placement keys and version.
	 *
	 * @param array<string,mixed> $layer
	 *
	 * @return array<string,int|float|string>|null
	 */
	private function fileLayer(string $path, array $layer, string $position): ?array
	{
		$version = $this->version($path);
		if ($version === null) {
			return null;
		}

		$ready = ['src' => $path, 'v' => $version, 'markpos' => $position];
		foreach (self::PLACEMENT as $key) {
			$value = $layer[$key] ?? null;
			if ((is_string($value) && $value !== '') || is_int($value) || is_float($value)) {
				$ready[$key] = $value;
			}
		}

		return $ready;
	}

	/**
	 * A logo or record image repeated over the whole image. The pattern is
	 * drawn once into WATERMARK_DIR under a name made from the file, its
	 * version and the tile count, so a replaced file gets a new pattern.
	 *
	 * @return array<string,int|float|string>|null
	 */
	private function tiledLayer(string $path, mixed $repeat, mixed $alpha): ?array
	{
		$version = $this->version($path);
		if ($version === null) {
			return null;
		}

		$tiles   = WatermarkPatternBuilder::tiles($repeat);
		$pattern = TextWatermarkFactory::WATERMARK_DIR . '/image_watermark_' . substr(hash('sha256', $path . '|' . $version . '|' . $tiles), 0, 16) . '.png';

		if (!$this->filesystem->fileExists($pattern) && !$this->drawImagePattern($path, $tiles, $pattern)) {
			return null;
		}

		return $this->tiledFile($pattern, $version, $alpha);
	}

	/**
	 * A pattern covers the whole image and is cropped to its shape.
	 *
	 * @return array<string,int|float|string>
	 */
	private function tiledFile(string $pattern, string $version, mixed $alpha): array
	{
		return [
			'src'       => $pattern,
			'v'         => $version,
			'markpos'   => 'center',
			'markw'     => '100w',
			'markh'     => '100h',
			'markfit'   => 'crop',
			'markalpha' => is_numeric($alpha) ? (int)$alpha : self::DEFAULT_TILE_ALPHA,
		];
	}

	/** Tile the image into a pattern PNG; false when it is not an image GD reads. */
	private function drawImagePattern(string $path, int $tiles, string $pattern): bool
	{
		if (!function_exists('imagecreatefromstring')) {
			return false;
		}

		$logo = @imagecreatefromstring($this->filesystem->read($path));
		if ($logo === false) {
			return false;
		}

		ob_start();
		$saved   = imagepng(WatermarkPatternBuilder::build($logo, $tiles));
		$content = (string)ob_get_clean();
		if (!$saved || $content === '') {
			return false;
		}

		$this->filesystem->write($pattern, $content);

		return true;
	}

	/**
	 * A file's modified time, or null when it cannot be read. Files are named
	 * by name only; this is what tells a new logo.png from the old one, and
	 * it goes into the render's cache key.
	 */
	private function version(string $path): ?string
	{
		try {
			return (string)$this->filesystem->flysystem()->lastModified($path);
		} catch (\Throwable) {
			return null;
		}
	}
}
