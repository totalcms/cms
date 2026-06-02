<?php

declare(strict_types=1);

namespace Tests\Unit\Media\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Media\Service\ImagePaletteGenerator;

final class ImagePaletteGeneratorTest extends TestCase
{
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/imagepalette_test_' . uniqid();
		mkdir($this->tempDir);
	}

	protected function tearDown(): void
	{
		if (is_dir($this->tempDir)) {
			$this->removeDirectory($this->tempDir);
		}
	}

	private function removeDirectory(string $dir): void
	{
		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) {
			$filePath = $dir . '/' . $file;
			is_dir($filePath) ? $this->removeDirectory($filePath) : unlink($filePath);
		}
		rmdir($dir);
	}

	private function createTestImage(string $filename = 'test.jpg', int $width = 100, int $height = 100): string
	{
		$imagePath = $this->tempDir . '/' . $filename;

		// Create a simple test image with multiple colors
		$image = imagecreatetruecolor($width, $height);

		// Add different colored sections for palette detection
		$red    = imagecolorallocate($image, 255, 0, 0);
		$blue   = imagecolorallocate($image, 0, 0, 255);
		$green  = imagecolorallocate($image, 0, 255, 0);
		$yellow = imagecolorallocate($image, 255, 255, 0);

		// Fill different quadrants with different colors
		imagefilledrectangle($image, 0, 0, $width / 2, $height / 2, $red);
		imagefilledrectangle($image, $width / 2, 0, $width, $height / 2, $blue);
		imagefilledrectangle($image, 0, $height / 2, $width / 2, $height, $green);
		imagefilledrectangle($image, $width / 2, $height / 2, $width, $height, $yellow);

		imagejpeg($image, $imagePath, 90);
		unset($image); // GD images are auto-garbage collected since PHP 8.0

		return $imagePath;
	}

	public function testGetPaletteWithValidImage(): void
	{
		if (!extension_loaded('gd') && !extension_loaded('imagick')) {
			$this->markTestSkipped('Neither GD nor ImageMagick extension is loaded');
		}

		$imagePath = $this->createTestImage();

		$palette = ImagePaletteGenerator::getPalette($imagePath);

		$this->assertIsArray($palette);
		$this->assertNotEmpty($palette);
		$this->assertLessThanOrEqual(5, count($palette));

		// Each color should be a hex string
		foreach ($palette as $color) {
			$this->assertIsString($color);
			$this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $color);
		}
	}

	public function testGetPaletteReturnsMaximumFiveColors(): void
	{
		if (!extension_loaded('gd') && !extension_loaded('imagick')) {
			$this->markTestSkipped('Neither GD nor ImageMagick extension is loaded');
		}

		$imagePath = $this->createTestImage();

		$palette = ImagePaletteGenerator::getPalette($imagePath);

		$this->assertLessThanOrEqual(5, count($palette));
	}

	public function testGetPaletteThrowsExceptionForNonexistentFile(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Image file does not exist:');

		ImagePaletteGenerator::getPalette('/nonexistent/path/image.jpg');
	}

	public function testGetPaletteThrowsExceptionWhenNoImageExtensionsLoaded(): void
	{
		// This test would require mocking extension_loaded, which is complex
		// Instead we'll test with a mock scenario using reflection
		$this->markTestSkipped('Cannot easily test extension loading without complex mocking');
	}

	public function testGetPaletteThrowsExceptionForUnreadableFile(): void
	{
		$imagePath = $this->createTestImage();

		// Make file unreadable (if possible)
		if (chmod($imagePath, 0000)) {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('Image file is not readable:');

			ImagePaletteGenerator::getPalette($imagePath);

			// Restore permissions for cleanup
			chmod($imagePath, 0644);
		} else {
			$this->markTestSkipped('Cannot change file permissions on this system');
		}
	}

	public function testGetPaletteThrowsExceptionForInvalidImageFile(): void
	{
		// Create a non-image file
		$textFilePath = $this->tempDir . '/notanimage.txt';
		file_put_contents($textFilePath, 'This is not an image file');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('File is not a valid image:');

		ImagePaletteGenerator::getPalette($textFilePath);
	}

	public function testGetPaletteThrowsExceptionForZeroDimensionImage(): void
	{
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for this test');
		}

		// Create a corrupted/invalid image file that will fail getimagesize()
		$imagePath = $this->tempDir . '/corrupted.jpg';

		// Write invalid JPEG header data
		file_put_contents($imagePath, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01"); // Truncated JPEG

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('File is not a valid image:');

		ImagePaletteGenerator::getPalette($imagePath);
	}

	public function testGetPaletteWithDifferentImageFormats(): void
	{
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for this test');
		}

		// Test PNG format
		$pngPath = $this->tempDir . '/test.png';
		$image   = imagecreatetruecolor(50, 50);
		$red     = imagecolorallocate($image, 255, 0, 0);
		imagefill($image, 0, 0, $red);
		imagepng($image, $pngPath);
		unset($image); // GD images are auto-garbage collected since PHP 8.0

		$palette = ImagePaletteGenerator::getPalette($pngPath);

		$this->assertIsArray($palette);
		$this->assertNotEmpty($palette);

		foreach ($palette as $color) {
			$this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $color);
		}
	}

	public function testGetPaletteWithSmallImage(): void
	{
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for this test');
		}

		$smallImagePath = $this->createTestImage('small.jpg', 10, 10);

		$palette = ImagePaletteGenerator::getPalette($smallImagePath);

		$this->assertIsArray($palette);
		$this->assertNotEmpty($palette);
		$this->assertLessThanOrEqual(5, count($palette));
	}

	public function testGetPaletteWithLargeImage(): void
	{
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for this test');
		}

		$largeImagePath = $this->createTestImage('large.jpg', 500, 500);

		$palette = ImagePaletteGenerator::getPalette($largeImagePath);

		$this->assertIsArray($palette);
		$this->assertNotEmpty($palette);
		$this->assertLessThanOrEqual(5, count($palette));
	}

	/**
	 * A large image must NOT OOM-kill the worker. On a GD-only install ColorThief
	 * would decode the full bitmap and exhaust memory_limit with an uncatchable
	 * fatal — the guard converts that into a RuntimeException so the caller can
	 * skip the palette and still save the image with correct metadata.
	 *
	 * Uses a tiny PNG whose header *declares* huge dimensions, so getimagesize
	 * reports them without allocating any pixels and the guard trips before any
	 * decode is attempted.
	 */
	public function testGetPaletteThrowsWhenGdDecodeWouldExceedMemoryLimit(): void
	{
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for this test');
		}
		if (extension_loaded('imagick')) {
			$this->markTestSkipped('Guard is GD-only; ColorThief uses Imagick when it is present');
		}

		$imagePath = $this->createHugeHeaderPng('huge.png', 25000, 25000);

		// Generous headroom above current usage so the test process is never at
		// risk, while the ~3GB decode estimate for 25000x25000 still far exceeds it.
		$original = ini_get('memory_limit');
		ini_set('memory_limit', (string)(memory_get_usage(true) + 256 * 1024 * 1024));

		try {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('too large to decode with GD');
			ImagePaletteGenerator::getPalette($imagePath);
		} finally {
			ini_set('memory_limit', (string)$original);
		}
	}

	/** Write a minimal PNG (signature + IHDR) that declares the given dimensions. */
	private function createHugeHeaderPng(string $filename, int $width, int $height): string
	{
		$imagePath = $this->tempDir . '/' . $filename;

		$ihdr = pack('N', $width) . pack('N', $height) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);
		$png  = "\x89PNG\r\n\x1a\n"
			. pack('N', strlen($ihdr)) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
			. pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));

		file_put_contents($imagePath, $png);

		return $imagePath;
	}

	public function testGetPaletteWithSingleColorImage(): void
	{
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for this test');
		}

		// Create single color image
		$imagePath = $this->tempDir . '/single_color.jpg';
		$image     = imagecreatetruecolor(100, 100);
		$red       = imagecolorallocate($image, 255, 0, 0);
		imagefill($image, 0, 0, $red);
		imagejpeg($image, $imagePath, 90);
		unset($image); // GD images are auto-garbage collected since PHP 8.0

		$palette = ImagePaletteGenerator::getPalette($imagePath);

		$this->assertIsArray($palette);
		$this->assertNotEmpty($palette);

		// Should have at least one color (the red)
		$this->assertGreaterThanOrEqual(1, count($palette));
	}

	public function testGetPaletteReturnsHexColors(): void
	{
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for this test');
		}

		$imagePath = $this->createTestImage();

		$palette = ImagePaletteGenerator::getPalette($imagePath);

		foreach ($palette as $color) {
			$this->assertIsString($color);
			$this->assertStringStartsWith('#', $color);
			$this->assertEquals(7, strlen($color)); // # + 6 hex digits
			$this->assertTrue(ctype_xdigit(substr($color, 1))); // Check if hex digits
		}
	}

	public function testGetPaletteWithComplexImage(): void
	{
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for this test');
		}

		// Create complex image with gradients and multiple colors
		$imagePath = $this->tempDir . '/complex.jpg';
		$image     = imagecreatetruecolor(200, 200);

		// Create gradient effect with multiple colors
		for ($x = 0; $x < 200; $x++) {
			for ($y = 0; $y < 200; $y++) {
				$r = ($x * 255) / 200;
				$g = ($y * 255) / 200;
				$b = (($x + $y) * 255) / 400;

				$color = imagecolorallocate($image, (int)$r, (int)$g, (int)$b);
				imagesetpixel($image, $x, $y, $color);
			}
		}

		imagejpeg($image, $imagePath, 90);
		unset($image); // GD images are auto-garbage collected since PHP 8.0

		$palette = ImagePaletteGenerator::getPalette($imagePath);

		$this->assertIsArray($palette);
		$this->assertNotEmpty($palette);
		$this->assertLessThanOrEqual(5, count($palette));

		// Should detect multiple colors in gradient
		$this->assertGreaterThan(1, count($palette));
	}

	public function testGetPaletteConsistency(): void
	{
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for this test');
		}

		$imagePath = $this->createTestImage();

		// Get palette multiple times - should be consistent
		$palette1 = ImagePaletteGenerator::getPalette($imagePath);
		$palette2 = ImagePaletteGenerator::getPalette($imagePath);

		$this->assertEquals($palette1, $palette2);
	}

	public function testGetPaletteHandlesImageWithTransparency(): void
	{
		if (!extension_loaded('gd')) {
			$this->markTestSkipped('GD extension is required for this test');
		}

		// Create PNG with transparency
		$imagePath = $this->tempDir . '/transparent.png';
		$image     = imagecreatetruecolor(100, 100);

		// Enable alpha blending and save transparency
		imagealphablending($image, false);
		imagesavealpha($image, true);

		// Create transparent background
		$transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
		imagefill($image, 0, 0, $transparent);

		// Add some colored areas
		$red = imagecolorallocatealpha($image, 255, 0, 0, 0);
		imagefilledrectangle($image, 25, 25, 75, 75, $red);

		imagepng($image, $imagePath);
		unset($image); // GD images are auto-garbage collected since PHP 8.0

		$palette = ImagePaletteGenerator::getPalette($imagePath);

		$this->assertIsArray($palette);
		$this->assertNotEmpty($palette);
	}
}
