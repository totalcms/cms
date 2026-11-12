<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\ImageWorks\Data\TextWatermarkStyle;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/**
 * Text watermark generator for ImageWorks.
 *
 * Draws the text to a transparent PNG with GD, which Glide then lays over the
 * image like any other watermark. In tiled mode the PNG is a whole pattern of
 * marks (see WatermarkPatternBuilder). Each style is drawn once and kept in
 * WATERMARK_DIR under a name made from its settings.
 */
readonly class TextWatermarkFactory
{
	public const WATERMARK_DIR = '.system/watermarks';

	/** Outline directions: enough that a thick outline has no gaps. */
	private const STROKE_STEPS = 24;
	/** Shadow opacity: GD alpha, 0 opaque to 127 clear. */
	private const SHADOW_ALPHA = 70;

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
	 * Generate a text watermark image with caching.
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

		$image = $this->drawText($style);
		if ($style->tiles > 0) {
			$image = WatermarkPatternBuilder::build($image, $style->tiles);
		}

		$this->save($image, $filename);

		return $filename;
	}

	private function drawText(TextWatermarkStyle $style): \GdImage
	{
		$fontPath = $this->getFontPath($style->font);
		// Only depot fonts create temporary files that need cleanup
		$isTemporaryFont = $style->font !== null && $fontPath !== null && str_contains($fontPath, sys_get_temp_dir());

		try {
			return $fontPath !== null && function_exists('imagettftext')
				? $this->drawTtfText($style, $fontPath)
				: $this->drawBuiltinText($style);
		} finally {
			if ($isTemporaryFont && file_exists($fontPath)) {
				unlink($fontPath);
			}
		}
	}

	private function drawTtfText(TextWatermarkStyle $style, string $fontPath): \GdImage
	{
		$box = imageftbbox($style->fontSize, $style->angle, $fontPath, $style->text);
		if (!is_array($box)) {
			throw new \RuntimeException('Failed to create text bounding box');
		}

		// For rotated text the box is the envelope of the four corners:
		// [0,1] lower left, [2,3] lower right, [4,5] upper right, [6,7] upper left.
		$minX = min($box[0], $box[2], $box[4], $box[6]);
		$maxX = max($box[0], $box[2], $box[4], $box[6]);
		$minY = min($box[1], $box[3], $box[5], $box[7]);
		$maxY = max($box[1], $box[3], $box[5], $box[7]);

		$lineCount = substr_count($style->text, "\n") + 1;
		$padding   = $style->padding + $style->effectsMargin();
		// Extra width balances the font's side bearing; extra height leaves
		// room for descenders (and rotation), per line.
		$width       = abs($maxX - $minX) + ($padding * 2) + ($style->fontSize * 0.1);
		$extraHeight = $style->angle === 0 ? ($style->fontSize * 0.5 * $lineCount) : ($style->fontSize * 0.8 * $lineCount);
		$height      = abs($maxY - $minY) + ($padding * 2) + $extraHeight;

		$image = $this->canvas((int)$width, (int)$height, $style->background);

		if ($style->angle === 0) {
			// Baseline from the top, so multi-line text grows downward.
			$x = $padding;
			$y = $padding + $style->fontSize;
		} else {
			// Center the rotated text in the canvas.
			$x = (imagesx($image) / 2) - ($minX + ($maxX - $minX) / 2);
			$y = (imagesy($image) / 2) - ($minY + ($maxY - $minY) / 2);
		}

		/** @param array{int<0,255>,int<0,255>,int<0,255>} $rgb */
		$draw = function (array $rgb, int $alpha, float $x, float $y) use ($image, $style, $fontPath): void {
			$color = imagecolorallocatealpha($image, $rgb[0], $rgb[1], $rgb[2], max(0, min(127, $alpha)));
			if ($color === false || imagettftext($image, $style->fontSize, $style->angle, (int)round($x), (int)round($y), $color, $fontPath, $style->text) === false) {
				throw new \RuntimeException('Failed to render TTF text');
			}
		};

		if ($style->shadow > 0) {
			$draw($style->shadowColor, self::SHADOW_ALPHA, $x + $style->shadow, $y + $style->shadow);
		}

		foreach ($this->strokeOffsets($style->stroke) as [$dx, $dy]) {
			$draw($style->strokeColor, 0, $x + $dx, $y + $dy);
		}

		$draw($style->color, 0, $x, $y);

		return $image;
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

	/** Without a TTF font: GD's built-in bitmap font, with no outline or shadow. */
	private function drawBuiltinText(TextWatermarkStyle $style): \GdImage
	{
		$lineCount = substr_count($style->text, "\n") + 1;
		$width     = strlen($style->text) * ($style->fontSize * 0.6) + ($style->padding * 2);
		$height    = ($style->fontSize * 1.5 * $lineCount) + ($style->padding * 2);

		$image  = $this->canvas((int)$width, (int)$height, $style->background);
		$color  = imagecolorallocatealpha($image, $style->color[0], $style->color[1], $style->color[2], 0);
		$fontId = min(5, max(1, intdiv($style->fontSize, 10)));
		if ($color === false) {
			throw new \RuntimeException('Failed to allocate text color');
		}

		imagestring($image, $fontId, $style->padding, (int)(($height - imagefontheight($fontId)) / 2), $style->text, $color);

		return $image;
	}

	/** @param array{int<0,255>,int<0,255>,int<0,255>}|null $background null for transparent */
	private function canvas(int $width, int $height, ?array $background): \GdImage
	{
		$image = imagecreatetruecolor(max(10, $width), max(10, $height));
		if ($image === false) {
			throw new \RuntimeException('Failed to create image resource');
		}

		if ($background === null) {
			// Transparent background - crucial for watermarks
			imagealphablending($image, false);
			imagesavealpha($image, true);
			$fill = imagecolorallocatealpha($image, 0, 0, 0, 127);
		} else {
			$fill = imagecolorallocate($image, $background[0], $background[1], $background[2]);
		}

		if ($fill === false) {
			throw new \RuntimeException('Failed to allocate background color');
		}

		imagefill($image, 0, 0, $fill);
		// Blend the text onto it.
		imagealphablending($image, true);

		return $image;
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
