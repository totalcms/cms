<?php

namespace TotalCMS\Domain\ImageWorks\Service;

use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

/**
 * Text watermark generator for ImageWorks.
 *
 * Generates text watermarks as images that can be applied to images
 */
final readonly class TextWatermarkFactory
{
	public const WATERMARK_DIR = '.watermarks';
	private const FONT_PATH    = __DIR__ . '/../../../../resources/fonts/RobotoRegular.ttf';

	public function __construct(
		private StorageAdapterInterface $filesystem,
		private Config $config,
	) {
	}

	/**
	 * Generate a text watermark image with caching.
	 *
	 * @param array<string,mixed> $params Text watermark parameters
	 *
	 * @return string Path to generated watermark image
	 */
	public function generateTextWatermark(array $params): string
	{
		$text = $params['marktext'] ?? '';
		if (empty($text)) {
			throw new \InvalidArgumentException('Text watermark requires marktext parameter');
		}

		// Text watermark parameters with defaults
		$fontSize        = (int)($params['marktextsize'] ?? 500);
		$fontColor       = $this->parseColor($params['marktextcolor'] ?? 'ffffff');
		$fontFamily      = $params['marktextfont'] ?? null;
		$backgroundColor = isset($params['marktextbg']) ? $this->parseColor($params['marktextbg']) : null;
		$padding         = (int)($params['marktextpad'] ?? 10);
		$angle           = (int)($params['marktextangle'] ?? 0);
		// $opacity         = (int)($params['marktextalpha'] ?? 100);

		// Generate cache key based on all parameters except opacity
		$cacheKey            = $this->generateCacheKey($text, $fontSize, $fontColor, $fontFamily, $backgroundColor, $padding, $angle);
		$cachedWatermarkPath = self::WATERMARK_DIR . '/' . $cacheKey . '.png';

		// Check if cached watermark exists
		if ($this->filesystem->fileExists($cachedWatermarkPath)) {
			return $cacheKey . '.png';
		}

		// Create new watermark image at full opacity
		$watermarkPath = $this->createTextImage($text, $fontSize, $fontColor, $fontFamily, $backgroundColor, $padding, $angle, 100, $cacheKey);

		return $watermarkPath;
	}

	/**
	 * Create a text image using GD (based on FakerImageGD approach).
	 *
	 * @param string $text
	 * @param int $fontSize
	 * @param array<int> $fontColor RGB array
	 * @param string|null $fontFamily
	 * @param array<int>|null $backgroundColor RGB array or null for transparent
	 * @param int $padding
	 * @param int $angle
	 * @param int $opacity
	 * @param string|null $cacheKey Optional cache key, if null generates temp name
	 *
	 * @return string
	 */
	private function createTextImage(
		string $text,
		int $fontSize,
		array $fontColor,
		?string $fontFamily,
		?array $backgroundColor,
		int $padding,
		int $angle,
		int $opacity,
		?string $cacheKey = null,
	): string {
		if (!function_exists('imagecreatetruecolor')) {
			throw new \RuntimeException('GD is not available on this PHP installation. Impossible to generate text watermark.');
		}

		// Get font path (prefer TTF)
		$fontPath = $this->getFontPath($fontFamily);
		// Only depot fonts create temporary files that need cleanup
		$isTemporaryFont = $fontFamily && $fontPath && str_contains($fontPath, sys_get_temp_dir());

		// Calculate initial dimensions based on whether we have TTF support
		if ($fontPath && function_exists('imageftbbox')) {
			// Start with larger dimensions and adjust if needed
			$initialWidth  = max(200, strlen($text) * $fontSize);
			$initialHeight = max(100, $fontSize * 2);

			// Create temporary image to calculate actual text size
			$tempImage = imagecreatetruecolor($initialWidth, $initialHeight);
			if ($tempImage === false) {
				throw new \RuntimeException('Failed to create temporary image for text measurement');
			}

			$textBox = imageftbbox($fontSize, $angle, $fontPath, $text);
			imagedestroy($tempImage);

			if (!is_array($textBox)) {
				throw new \RuntimeException('Failed to create text bounding box');
			}

			// For rotated text, we need to calculate the envelope (bounding rectangle)
			// textBox contains 8 values: [0]=>lower left X, [1]=>lower left Y, [2]=>lower right X, [3]=>lower right Y,
			// [4]=>upper right X, [5]=>upper right Y, [6]=>upper left X, [7]=>upper left Y
			$minX = min($textBox[0], $textBox[2], $textBox[4], $textBox[6]);
			$maxX = max($textBox[0], $textBox[2], $textBox[4], $textBox[6]);
			$minY = min($textBox[1], $textBox[3], $textBox[5], $textBox[7]);
			$maxY = max($textBox[1], $textBox[3], $textBox[5], $textBox[7]);

			$textWidth  = abs($maxX - $minX);
			$textHeight = abs($maxY - $minY);

			// Add extra width padding to balance font bearing - small additional padding on right
			$width = $textWidth + ($padding * 2) + ($fontSize * 0.1);
			// Add extra height for descenders and rotation space - increase margin for rotated text
			$extraHeight = $angle == 0 ? ($fontSize * 0.5) : ($fontSize * 0.8);
			$height      = $textHeight + ($padding * 2) + $extraHeight;
		} else {
			// Fallback for built-in fonts
			$width  = strlen($text) * ($fontSize * 0.6) + ($padding * 2);
			$height = $fontSize * 1.5 + ($padding * 2);
		}

		// Ensure minimum dimensions and convert to int
		$width  = max(10, (int)$width);
		$height = max(10, (int)$height);

		// Create the actual image
		$image = imagecreatetruecolor($width, $height);
		if ($image === false) {
			throw new \RuntimeException('Failed to create image resource');
		}

		// Set up transparency or background
		if ($backgroundColor === null) {
			// Transparent background - crucial for watermarks
			imagealphablending($image, false);
			imagesavealpha($image, true);
			$transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
			if ($transparent === false) {
				imagedestroy($image);
				throw new \RuntimeException('Failed to allocate transparent color');
			}
			imagefill($image, 0, 0, $transparent);
			imagealphablending($image, true); // Re-enable for text rendering
		} else {
			// Solid background
			$bgColor = imagecolorallocate(
				$image,
				max(0, min(255, $backgroundColor[0])),
				max(0, min(255, $backgroundColor[1])),
				max(0, min(255, $backgroundColor[2]))
			);
			if ($bgColor === false) {
				imagedestroy($image);
				throw new \RuntimeException('Failed to allocate background color');
			}
			imagefill($image, 0, 0, $bgColor);
		}

		// Calculate alpha from opacity (0-100 to 0-127)
		$alpha     = max(0, min(127, (int)(127 - ($opacity / 100 * 127))));
		$textColor = imagecolorallocatealpha(
			$image,
			max(0, min(255, $fontColor[0])),
			max(0, min(255, $fontColor[1])),
			max(0, min(255, $fontColor[2])),
			$alpha
		);
		if ($textColor === false) {
			imagedestroy($image);
			throw new \RuntimeException('Failed to allocate text color');
		}

		// Add text to image using positioning that accounts for rotation
		if ($fontPath && function_exists('imagettftext')) {
			// Use TTF font - position text correctly for both rotated and non-rotated text
			if ($angle === 0) {
				// No rotation - position text with baseline well above bottom
				$x = $padding;
				// Position baseline high enough to show descenders
				// TTF baseline is where the text sits, descenders go below this line
				$y = $height - $padding - ($fontSize * 0.5); // Leave 50% of font size for descenders
			} else {
				// With rotation - calculate proper centered positioning
				$textBox = imageftbbox($fontSize, $angle, $fontPath, $text);
				if (is_array($textBox)) {
					// Calculate the bounding box dimensions
					$minX = min($textBox[0], $textBox[2], $textBox[4], $textBox[6]);
					$maxX = max($textBox[0], $textBox[2], $textBox[4], $textBox[6]);
					$minY = min($textBox[1], $textBox[3], $textBox[5], $textBox[7]);
					$maxY = max($textBox[1], $textBox[3], $textBox[5], $textBox[7]);

					// Center the text in the image by adjusting for the bounding box offset
					$x = ($width / 2) - ($minX + ($maxX - $minX) / 2);
					$y = ($height / 2) - ($minY + ($maxY - $minY) / 2);
				} else {
					// Fallback positioning
					$x = $width / 2;
					$y = $height / 2;
				}
			}

			$result = imagettftext($image, $fontSize, $angle, (int)$x, (int)$y, $textColor, $fontPath, $text);
			if ($result === false) {
				imagedestroy($image);
				throw new \RuntimeException('Failed to render TTF text');
			}
		} else {
			// Use built-in font as fallback
			$fontId = min(5, max(1, (int)($fontSize / 10)));
			$x      = $padding;
			$y      = ($height - imagefontheight($fontId)) / 2;
			imagestring($image, $fontId, (int)$x, (int)$y, $text, $textColor);
		}

		// Determine filename
		$filename = $cacheKey ? $cacheKey . '.png' : $this->generateTempPath();
		$fullPath = sys_get_temp_dir() . '/' . $filename;

		if (!imagepng($image, $fullPath)) {
			imagedestroy($image);
			throw new \RuntimeException('Failed to save text watermark image');
		}

		imagedestroy($image);

		// Store in filesystem for Glide to access
		$watermarkPath = self::WATERMARK_DIR . '/' . $filename;
		$content       = file_get_contents($fullPath);
		if ($content === false) {
			throw new \RuntimeException('Failed to read temporary file');
		}
		$this->filesystem->write($watermarkPath, $content);

		// Clean up temp file
		unlink($fullPath);

		// Clean up temporary font file if it was loaded from depot
		if ($isTemporaryFont && file_exists($fontPath)) {
			unlink($fontPath);
		}

		return $filename;
	}

	/**
	 * Generate cache key based on text watermark parameters (excluding opacity).
	 *
	 * @param string $text
	 * @param int $fontSize
	 * @param array<int> $fontColor
	 * @param string|null $fontFamily
	 * @param array<int>|null $backgroundColor
	 * @param int $padding
	 * @param int $angle
	 *
	 * @return string
	 */
	private function generateCacheKey(
		string $text,
		int $fontSize,
		array $fontColor,
		?string $fontFamily,
		?array $backgroundColor,
		int $padding,
		int $angle,
	): string {
		// Create a deterministic cache key based on all parameters except opacity
		$keyData = [
			'text'            => $text,
			'fontSize'        => $fontSize,
			'fontColor'       => implode(',', $fontColor),
			'fontFamily'      => $fontFamily ?? 'default',
			'backgroundColor' => $backgroundColor ? implode(',', $backgroundColor) : 'transparent',
			'padding'         => $padding,
			'angle'           => $angle,
		];

		// Generate a hash of the parameters for the cache key
		$serialized = serialize($keyData);
		$hash       = hash('sha256', $serialized);

		// Use first 16 characters of hash for cache key (sufficient for uniqueness while keeping filenames reasonable)
		return 'text_watermark_' . substr($hash, 0, 16);
	}

	/**
	 * Parse color string to RGB array.
	 *
	 * @param string $color Hex color (with or without #)
	 *
	 * @return array<int> RGB array
	 */
	private function parseColor(string $color): array
	{
		$color = ltrim($color, '#');

		if (strlen($color) === 3) {
			$color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
		}

		if (strlen($color) !== 6) {
			throw new \InvalidArgumentException('Invalid color format. Use hex format like "ffffff" or "#ffffff"');
		}

		return [
			(int)hexdec(substr($color, 0, 2)),  // R
			(int)hexdec(substr($color, 2, 2)),  // G
			(int)hexdec(substr($color, 4, 2)),   // B
		];
	}

	/**
	 * Get font path for custom fonts.
	 *
	 * @param string|null $fontFamily
	 *
	 * @return string|null
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
		if (file_exists(self::FONT_PATH)) {
			return self::FONT_PATH;
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
				} catch (\Exception $e) {
					// Continue trying other extensions
				}
			}
		}

		$depotPath = "depot/{$depotId}/depot/{$fontFileName}";

		try {
			if ($this->filesystem->fileExists($depotPath)) {
				// Create temporary file for the font (keep original extension)
				$tempFontPath = sys_get_temp_dir() . '/watermark_font_' . $fontFamily . '_' . uniqid() . $fontExtension;
				$fontContent  = $this->filesystem->read($depotPath);
				file_put_contents($tempFontPath, $fontContent);

				return $tempFontPath;
			}
		} catch (\Exception $e) {
			// Log error but don't fail - fall back to default font
			error_log("Failed to load font '{$fontFamily}' from depot '{$depotId}': " . $e->getMessage());
		}

		return null;
	}

	/**
	 * Generate temporary file path.
	 *
	 * @return string
	 */
	private function generateTempPath(): string
	{
		return 'text_watermark_' . uniqid() . '.png';
	}

	/**
	 * Clean up temporary watermark files (for backwards compatibility).
	 *
	 * @param string $watermarkPath
	 *
	 * @return void
	 */
	public function cleanup(string $watermarkPath): void
	{
		$fullPath = self::WATERMARK_DIR . '/' . $watermarkPath;
		if ($this->filesystem->fileExists($fullPath)) {
			$this->filesystem->delete($fullPath);
		}
	}

	/**
	 * Clear cached watermarks older than specified time.
	 *
	 * @param int $maxAge Maximum age in seconds (default: 30 days)
	 *
	 * @return int Number of files cleaned up
	 */
	public function clearOldCache(int $maxAge = 2592000): int
	{
		$cleaned    = 0;
		$cutoffTime = time() - $maxAge;

		try {
			$files = $this->filesystem->listFiles(self::WATERMARK_DIR);

			foreach ($files as $file) {
				if (str_starts_with($file, 'text_watermark_')) {
					$fullPath = self::WATERMARK_DIR . '/' . $file;

					// Use flysystem directly to get file metadata
					$lastModified = $this->filesystem->flysystem()->lastModified($fullPath);

					if ($lastModified && $lastModified < $cutoffTime) {
						$this->filesystem->delete($fullPath);
						$cleaned++;
					}
				}
			}
		} catch (\Exception $e) {
			// Log error but don't fail
			error_log('Error cleaning watermark cache: ' . $e->getMessage());
		}

		return $cleaned;
	}
}
