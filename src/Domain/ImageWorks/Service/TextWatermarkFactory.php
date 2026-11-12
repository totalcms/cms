<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use Intervention\Image\Colors\Rgb\Color;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Geometry\Point;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\Font;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Data\TextWatermarkStyle;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/**
 * Draws text watermarks for ImageWorks with Intervention Image.
 *
 * A text layer is drawn onto a transparent canvas of the image's own driver
 * (GD or Imagick) at render time and placed like a logo (see
 * MarkLayersManipulator). Only a tiled mark is kept as a file: the whole
 * pattern of marks (see WatermarkPatternBuilder), drawn once into
 * WATERMARK_DIR under a name made from its settings.
 */
readonly class TextWatermarkFactory
{
	public const WATERMARK_DIR = '.system/watermarks';

	/** Outline directions: enough that a thick outline has no gaps. */
	private const STROKE_STEPS = 24;
	/** Shadow opacity, 0 clear to 255 opaque. */
	private const SHADOW_OPACITY = 115;
	/** Line to line, as a multiple of the font size: 1.25 is CSS's `line-height: 1.25`. */
	private const LINE_HEIGHT = 1.25;
	/** Intervention's GD driver scales line spacing by this; it is divided back out. */
	private const GD_LEADING = 0.8;
	/** Wrapped text steps down this share of its size at a time... */
	private const STEP_DOWN = 0.9;
	/** ...and stops at this size, in pixels, whatever still overflows. */
	private const MIN_SIZE = 8;

	private LoggerInterface $logger;

	public function __construct(
		private StorageAdapterInterface $filesystem,
		private Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->channelLogger(LogChannel::TextWatermark);
	}

	/**
	 * The text watermark as a PNG in WATERMARK_DIR: the pattern of marks in
	 * tiled mode, otherwise the one mark. Each style is drawn once.
	 *
	 * @param array<string,mixed> $params Text watermark parameters
	 *
	 * @return string File name of the watermark image in WATERMARK_DIR
	 */
	public function generateTextWatermark(array $params): string
	{
		$style    = new TextWatermarkStyle($params);
		$filename = $style->cacheKey() . '.png';

		if ($this->filesystem->fileExists(self::WATERMARK_DIR . '/' . $filename)) {
			return $filename;
		}

		if (!function_exists('imagecreatetruecolor')) {
			throw new \RuntimeException('GD is not available on this PHP installation. Impossible to generate text watermark.');
		}

		$native = $this->draw($style, new GdDriver())->core()->native();
		if (!$native instanceof \GdImage) {
			throw new \RuntimeException('Failed to draw text watermark');
		}

		$this->save($style->tiles > 0 ? WatermarkPatternBuilder::build($native, $style->tiles) : $native, $filename);

		return $filename;
	}

	/**
	 * The text drawn once, on a transparent canvas (or its background
	 * color) of the given driver, with its outline, shadow and angle.
	 *
	 * With a wrap width, the whole mark (plate and padding included) stays
	 * that wide: the text breaks at word boundaries, and when it still needs
	 * more than `$style->lines` lines, or a word is wider than the box, the
	 * font size steps down until it fits. `\n` in the text always breaks.
	 */
	public function draw(TextWatermarkStyle $style, DriverInterface $driver, ?int $wrapWidth = null): ImageInterface
	{
		$fontPath = $this->getFontPath($style->font);
		if ($fontPath === null) {
			throw new \RuntimeException('No font found for the text watermark');
		}

		// Only depot fonts create temporary files that need cleanup
		$isTemporaryFont = $style->font !== null && str_contains($fontPath, sys_get_temp_dir());

		try {
			return $this->drawWithFont($style, $driver, $fontPath, $wrapWidth);
		} finally {
			if ($isTemporaryFont && file_exists($fontPath)) {
				unlink($fontPath);
			}
		}
	}

	private function drawWithFont(TextWatermarkStyle $style, DriverInterface $driver, string $fontPath, ?int $wrapWidth): ImageInterface
	{
		if ($wrapWidth !== null) {
			$style = $this->fitted($style, $driver, $fontPath, $wrapWidth);
		}

		$font = $this->font($fontPath, $style, $wrapWidth, $driver);

		[$textWidth, $textHeight] = $this->textSize($style->text, $font, $driver);

		// Draw with room to spare, then trim to the drawn pixels: the font
		// metrics each driver reports put descenders (g, y) in different
		// places, and Imagick's cut them off the plate. Trimming gives the
		// same box on both, and even padding on all four sides.
		$margin = $style->effectsMargin() + $style->fontSize;
		$ink    = $driver->createImage(
			max(10, $textWidth + ($margin * 2)),
			max(10, $textHeight + ($margin * 2)),
		);

		// The point Intervention aligns the block on: its left edge, middle or right edge.
		$left = $margin + match ($style->align) {
			'center' => $textWidth / 2,
			'right'  => $textWidth,
			default  => 0,
		};

		if ($style->shadow > 0) {
			$this->text($ink, $style->text, $font, new Color(...[...$style->shadowColor, self::SHADOW_OPACITY]), $left + $style->shadow, $margin + $style->shadow);
		}

		$strokeColor = new Color(...$style->strokeColor);
		foreach ($this->strokeOffsets($style->stroke) as [$dx, $dy]) {
			$this->text($ink, $style->text, $font, $strokeColor, $left + $dx, $margin + $dy);
		}

		$this->text($ink, $style->text, $font, new Color(...$style->color), $left, $margin);
		$ink = $this->trimmed($ink);

		$padding = max(0, $style->padding);
		$canvas  = $driver->createImage($ink->width() + ($padding * 2), $ink->height() + ($padding * 2));
		if ($style->background !== null) {
			$canvas->fill(new Color(...$style->background));
		}

		$canvas->place($ink, 'top-left', $padding, $padding);

		// Positive angles turn counter-clockwise, as GD's text angle did.
		return $style->angle === 0 ? $canvas : $canvas->rotate($style->angle, new Color(0, 0, 0, 0));
	}

	/**
	 * The style at the largest size, from its own down, whose text wraps
	 * into at most `lines` lines with every line inside the box.
	 */
	private function fitted(TextWatermarkStyle $style, DriverInterface $driver, string $fontPath, int $wrapWidth): TextWatermarkStyle
	{
		$processor = $driver->fontProcessor();
		$size      = $style->fontSize;

		while (true) {
			$candidate = $size === $style->fontSize ? $style : $style->withFontSize($size);
			$limit     = $this->textWrapWidth($candidate, $wrapWidth);
			$font      = $this->font($fontPath, $candidate, $wrapWidth, $driver);
			$block     = $processor->textBlock($candidate->text, $font, new Point(0, 0));

			$widest = 0;
			foreach ($block as $line) {
				$widest = max($widest, $processor->boxSize((string)$line, $font)->width());
			}

			if (($block->count() <= $candidate->lines && $widest <= $limit) || $size <= self::MIN_SIZE) {
				return $candidate;
			}

			$size = max(self::MIN_SIZE, (int)floor($size * self::STEP_DOWN));
		}
	}

	/**
	 * Intervention spaces lines by a multiple of the height it measures for
	 * "Hy", and the drivers measure it differently: GD the full height, tails
	 * included, Imagick only the capitals (Poppins: 69px against 50px at 72px).
	 * So the multiple is worked out from the measured height, which spaces
	 * lines LINE_HEIGHT times the font size on either driver.
	 */
	private function lineHeight(Font $font, int $fontSize, DriverInterface $driver): float
	{
		$measured = max(1, $driver->fontProcessor()->typographicalSize($font));
		$multiple = self::LINE_HEIGHT * $fontSize / $measured;

		return $driver instanceof GdDriver ? $multiple / self::GD_LEADING : $multiple;
	}

	/** The font for a style; with a wrap width, it wraps inside the plate's padding. */
	private function font(string $fontPath, TextWatermarkStyle $style, ?int $wrapWidth, DriverInterface $driver): Font
	{
		$font = new Font($fontPath);
		$font->setSize($style->fontSize);
		$font->setAlignment($style->align);
		$font->setValignment('top');
		$font->setLineHeight($this->lineHeight($font, $style->fontSize, $driver));

		if ($wrapWidth !== null) {
			$font->setWrapWidth($this->textWrapWidth($style, $wrapWidth));
		}

		return $font;
	}

	/** The width the text itself may take: the box less padding and effects on both sides. */
	private function textWrapWidth(TextWatermarkStyle $style, int $wrapWidth): int
	{
		return max(1, $wrapWidth - (2 * (max(0, $style->padding) + $style->effectsMargin())));
	}

	/**
	 * The image cut to its drawn pixels; as it was when nothing is drawn.
	 * The canvas must have a clear margin, so its top-left pixel is empty.
	 */
	private function trimmed(ImageInterface $image): ImageInterface
	{
		$native = $image->core()->native();

		if ($native instanceof \Imagick) {
			$native->trimImage(0);
			$native->setImagePage(0, 0, 0, 0);

			return $image;
		}

		if (!$native instanceof \GdImage) {
			return $image;
		}

		// GD's own crop drops the alpha channel, so it only measures here:
		// the ink box, then the box from (0,0) to the ink's far corner, which
		// together give the offset Intervention's alpha-safe crop needs.
		$ink = imagecropauto($native, IMG_CROP_TRANSPARENT);
		if ($ink === false) {
			return $image;
		}

		$clear = imagecolorat($native, 0, 0);
		if ($clear === false) {
			return $image;
		}

		imagealphablending($native, false);
		imagesetpixel($native, 0, 0, 0);
		$corner = imagecropauto($native, IMG_CROP_TRANSPARENT);
		imagesetpixel($native, 0, 0, $clear);

		if ($corner === false) {
			return $image;
		}

		$width  = imagesx($ink);
		$height = imagesy($ink);

		return $image->crop($width, $height, imagesx($corner) - $width, imagesy($corner) - $height, new Color(0, 0, 0, 0));
	}

	private function text(ImageInterface $canvas, string $text, Font $font, Color $color, float $x, float $y): void
	{
		$font->setColor($color);
		$canvas->text($text, (int)round($x), (int)round($y), $font);
	}

	/**
	 * Width of the longest line and height of the block, from the font's own
	 * metrics, once the text is broken into lines (at `\n`, and at the
	 * font's wrap width when it has one).
	 *
	 * @return array{int,int}
	 */
	private function textSize(string $text, Font $font, DriverInterface $driver): array
	{
		$processor = $driver->fontProcessor();
		$lines     = $processor->textBlock($text, $font, new Point(0, 0));
		$width     = 0;

		foreach ($lines as $line) {
			$box   = $processor->boxSize((string)$line, $font);
			$width = max($width, $box->width() + max(0, $box->pivot()->x()));
		}

		$height = $processor->typographicalSize($font) + ($processor->leading($font) * ($lines->count() - 1));

		return [(int)ceil($width), (int)ceil($height)];
	}

	/**
	 * Where to draw the outline copies: rings out to the outline width, so the
	 * outline is solid however thick it is.
	 *
	 * @return list<array{float,float}>
	 */
	private function strokeOffsets(int $stroke): array
	{
		$offsets = [];
		for ($radius = $stroke; $radius > 0; $radius -= max(1, intdiv($stroke, 3))) {
			for ($step = 0; $step < self::STROKE_STEPS; $step++) {
				$angle     = 2 * M_PI * $step / self::STROKE_STEPS;
				$offsets[] = [cos($angle) * $radius, sin($angle) * $radius];
			}
		}

		return $offsets;
	}

	private function save(\GdImage $image, string $filename): void
	{
		ob_start();
		$saved   = imagepng($image);
		$content = (string)ob_get_clean();

		if (!$saved || $content === '') {
			throw new \RuntimeException('Failed to save text watermark image');
		}

		$this->filesystem->write(self::WATERMARK_DIR . '/' . $filename, $content);
	}

	private function fontPath(): string
	{
		return PathResolver::packageRoot() . '/resources/fonts/RobotoRegular.ttf';
	}

	/**
	 * Get font path for custom fonts.
	 */
	private function getFontPath(?string $fontFamily): ?string
	{
		// If a specific font family is requested, try to load from depot
		if ($fontFamily) {
			$depotFontPath = $this->loadFontFromDepot($fontFamily);
			if ($depotFontPath !== null) {
				return $depotFontPath;
			}
		}

		// Default font: Always use Roboto Regular
		if (file_exists($this->fontPath())) {
			return $this->fontPath();
		}

		// No font available
		return null;
	}

	/**
	 * Load font file from the configured depot.
	 *
	 * @param string $fontFamily Font family name (with or without .ttf/.otf extension)
	 *
	 * @return string|null Path to temporary font file or null if not found
	 */
	private function loadFontFromDepot(string $fontFamily): ?string
	{
		$depotId = $this->config->imageworks['watermarkFontsDepot'] ?? 'watermark-fonts';

		// Handle font files with or without extensions (.ttf, .otf)
		$supportedExtensions = ['.ttf', '.otf'];
		$fontFileName        = $fontFamily;
		$fontExtension       = '';

		// Check if the font already has a supported extension
		$hasExtension = false;
		foreach ($supportedExtensions as $ext) {
			if (str_ends_with(strtolower($fontFamily), $ext)) {
				$hasExtension  = true;
				$fontExtension = $ext;
				break;
			}
		}

		// If no extension provided, try each supported format
		if (!$hasExtension) {
			foreach ($supportedExtensions as $ext) {
				$testFileName = $fontFamily . $ext;
				$testPath     = "depot/{$depotId}/depot/{$testFileName}";

				try {
					if ($this->filesystem->fileExists($testPath)) {
						$fontFileName  = $testFileName;
						$fontExtension = $ext;
						break;
					}
				} catch (\Exception) {
					// Continue trying other extensions
				}
			}
		}

		$depotPath = "depot/{$depotId}/depot/{$fontFileName}";

		try {
			if ($this->filesystem->fileExists($depotPath)) {
				// Create temporary file for the font (keep original extension)
				$tempFontPath = sys_get_temp_dir() . '/watermark_font_' . basename($fontFamily) . '_' . uniqid() . $fontExtension;
				$fontContent  = $this->filesystem->read($depotPath);
				file_put_contents($tempFontPath, $fontContent);

				return $tempFontPath;
			}
		} catch (\Exception $e) {
			// Log error but don't fail - fall back to default font
			$this->logger->warning('Failed to load font from depot, falling back to default', [
				'font'      => $fontFamily,
				'depot'     => $depotId,
				'error'     => $e->getMessage(),
				'exception' => $e::class,
			]);
		}

		return null;
	}
}
