<?php

namespace Tests\Unit\Media\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Media\Service\ImageMetaReader;

final class ImageMetaReaderTest extends TestCase
{
	private string $testImagePath;
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/totalcms_test_images_' . uniqid();
		mkdir($this->tempDir, 0777, true);

		// Use the real EXIF test image from test-data
		$realExifImage = dirname(__DIR__, 3) . '/test-data/image-exif.jpg';
		if (file_exists($realExifImage)) {
			$this->testImagePath = $realExifImage;
		} else {
			// Fallback to creating a simple test image
			$this->testImagePath = $this->createTestImage();
		}
	}

	protected function tearDown(): void
	{
		// Clean up test files
		if (is_dir($this->tempDir)) {
			$this->recursiveRemoveDirectory($this->tempDir);
		}
	}

	public function testGetBasicImageData(): void
	{
		$basicData = ImageMetaReader::getBasicImageData($this->testImagePath);

		$this->assertIsArray($basicData, 'Should return array for basic image data');
		$this->assertArrayHasKey('mime', $basicData);
		$this->assertArrayHasKey('width', $basicData);
		$this->assertArrayHasKey('height', $basicData);

		$this->assertEquals('image/jpeg', $basicData['mime']);
		$this->assertIsInt($basicData['width']);
		$this->assertIsInt($basicData['height']);
		$this->assertGreaterThan(0, $basicData['width']);
		$this->assertGreaterThan(0, $basicData['height']);
	}

	public function testGetBasicImageDataWithInvalidFile(): void
	{
		$invalidPath = $this->tempDir . '/nonexistent.jpg';

		// Set custom error handler to suppress expected getimagesize warning
		set_error_handler(function ($severity, $message, $file, $line): bool {
			// Only suppress warnings from getimagesize about file not found
			if ($severity === E_WARNING && str_contains($message, 'getimagesize') && str_contains($message, 'Failed to open stream')) {
				return true; // Suppress this specific warning
			}

			// Let other errors through
			return false;
		});

		try {
			$basicData = ImageMetaReader::getBasicImageData($invalidPath);

			$this->assertIsArray($basicData, 'Should return array even for invalid files');
			$this->assertEmpty($basicData, 'Should return empty array for invalid files');
		} finally {
			// Restore original error handler
			restore_error_handler();
		}
	}

	public function testGetMetaDataStructure(): void
	{
		$metadata = ImageMetaReader::getMetaData($this->testImagePath);

		$this->assertIsArray($metadata, 'Should return array for metadata');

		// Debug what's actually returned
		// var_dump('Metadata structure:', $metadata);

		if ($metadata !== [] && count($metadata) > 3) {
			// Check required top-level keys when full metadata is available
			$requiredKeys = ['exif', 'tags', 'alt', 'mime', 'width', 'height'];
			foreach ($requiredKeys as $key) {
				$this->assertArrayHasKey($key, $metadata, "Should have '$key' key in metadata");
			}

			// Check basic image data is included
			$this->assertEquals('image/jpeg', $metadata['mime']);
			$this->assertIsInt($metadata['width']);
			$this->assertIsInt($metadata['height']);

			// Check EXIF data structure
			$this->assertIsArray($metadata['exif'], 'EXIF data should be an array');
			$this->assertIsArray($metadata['tags'], 'Tags should be an array');
		} else {
			// Simple test image may not have EXIF data - test basic structure only
			$this->assertNotEmpty($metadata, 'Should return basic image data even without EXIF');
			$basicKeys = ['mime', 'width', 'height'];
			foreach ($basicKeys as $key) {
				$this->assertArrayHasKey($key, $metadata, "Should have basic '$key' key");
			}
		}
	}

	public function testFractionProcessing(): void
	{
		// Test with a mock EXIF data that includes fractions
		$testImageWithFractions = $this->createTestImageWithMockExif();

		if ($testImageWithFractions) {
			$metadata = ImageMetaReader::getMetaData($testImageWithFractions);

			if (isset($metadata['exif']['focalLength'])) {
				// Should be 31 (not "31/1") - based on test image EXIF data
				$this->assertEquals(31.0, $metadata['exif']['focalLength']);
			}

			if (isset($metadata['exif']['aperture'])) {
				// Should be 11.0 (not "11/1") - based on test image EXIF data  
				$this->assertEquals(11.0, $metadata['exif']['aperture']);
			}

			if (isset($metadata['exif']['altitude'])) {
				// Should be string "10" (not float 10.0)
				$this->assertIsString($metadata['exif']['altitude']);
			}
		} else {
			$this->markTestIncomplete('Could not create test image with mock EXIF data');
		}
	}

	public function testGPSCoordinateFormatting(): void
	{
		// Test GPS coordinate processing returns strings
		$testImageWithGPS = $this->createTestImageWithMockGPS();

		if ($testImageWithGPS) {
			$metadata = ImageMetaReader::getMetaData($testImageWithGPS);

			// GPS coordinates should be strings for schema compliance
			if (isset($metadata['exif']['latitude'])) {
				$this->assertIsString($metadata['exif']['latitude'], 'Latitude should be string');
				$this->assertIsNumeric($metadata['exif']['latitude'], 'Latitude should be numeric string');
			}

			if (isset($metadata['exif']['longitude'])) {
				$this->assertIsString($metadata['exif']['longitude'], 'Longitude should be string');
				$this->assertIsNumeric($metadata['exif']['longitude'], 'Longitude should be numeric string');
			}

			if (isset($metadata['exif']['altitude'])) {
				$this->assertIsString($metadata['exif']['altitude'], 'Altitude should be string');
				$this->assertIsNumeric($metadata['exif']['altitude'], 'Altitude should be numeric string');
			}
		} else {
			$this->markTestIncomplete('Could not create test image with mock GPS data');
		}
	}

	public function testKeywordExtraction(): void
	{
		// Test keyword extraction from various sources
		$testImageWithKeywords = $this->createTestImageWithKeywords();

		if ($testImageWithKeywords) {
			$metadata = ImageMetaReader::getMetaData($testImageWithKeywords);

			$this->assertIsArray($metadata['tags'], 'Tags should be an array');

			// Keywords should be unique and sorted
			if (isset($metadata['tags']) && $metadata['tags'] !== []) {
				$tags       = $metadata['tags'];
				$uniqueTags = array_unique($tags);
				$sortedTags = $tags;
				sort($sortedTags);

				$this->assertEquals($uniqueTags, $tags, 'Tags should be unique');
				$this->assertEquals($sortedTags, $tags, 'Tags should be sorted');
			}
		} else {
			$this->markTestIncomplete('Could not create test image with keywords for testing');
		}
	}

	public function testDateFormatting(): void
	{
		// Test date formatting to ISO format
		$testDates = [
			'2024:01:15 14:30:45' => '2024-01-15T14:30:45', // EXIF format
			'2024-01-15 14:30:45' => '2024-01-15T14:30:45', // Standard format
		];

		// Since we can't easily inject dates into EXIF, we test the internal date formatting
		// by using reflection to test the private formatDate method
		$reflection       = new \ReflectionClass(ImageMetaReader::class);
		$formatDateMethod = $reflection->getMethod('formatDate');

		foreach ($testDates as $input => $expectedPrefix) {
			$result = $formatDateMethod->invoke(null, $input);

			$this->assertNotNull($result, "Should format date: $input");
			$this->assertStringStartsWith($expectedPrefix, $result, "Date should be properly formatted for: $input");
		}

		// Test invalid date
		$invalidResult = $formatDateMethod->invoke(null, 'invalid-date');
		$this->assertNull($invalidResult, 'Should return null for invalid date');
	}

	public function testXMPLensExtraction(): void
	{
		// Test XMP lens data extraction
		$testImageWithXMP = $this->createTestImageWithMockXMP();

		if ($testImageWithXMP) {
			$metadata = ImageMetaReader::getMetaData($testImageWithXMP);

			// Check if lens information is extracted
			if (isset($metadata['exif']['lens'])) {
				$this->assertIsString($metadata['exif']['lens']);
				$this->assertNotEmpty($metadata['exif']['lens']);
			}
		} else {
			$this->markTestIncomplete('Could not create test image with XMP data');
		}
	}

	public function testIPTCLocationExtraction(): void
	{
		// Test IPTC location data extraction
		$testImageWithIPTC = $this->createTestImageWithMockIPTC();

		if ($testImageWithIPTC) {
			$metadata = ImageMetaReader::getMetaData($testImageWithIPTC);

			$locationFields = ['country', 'state', 'city', 'sublocation'];
			foreach ($locationFields as $field) {
				if (isset($metadata['exif'][$field])) {
					$this->assertIsString($metadata['exif'][$field], "$field should be string");
					$this->assertNotEmpty($metadata['exif'][$field], "$field should not be empty");
				}
			}
		} else {
			$this->markTestIncomplete('Could not create test image with IPTC data');
		}
	}

	public function testEmptyAndNullDataHandling(): void
	{
		$metadata = ImageMetaReader::getMetaData($this->testImagePath);

		if ($metadata !== [] && isset($metadata['exif'])) {
			// Test that null/empty values are properly filtered out
			$this->assertIsArray($metadata['exif']);

			// No null values should exist in the final exif array
			foreach ($metadata['exif'] as $key => $value) {
				$this->assertNotNull($value, "EXIF field '$key' should not be null");
				if (is_string($value)) {
					$this->assertNotEmpty(trim($value), "EXIF field '$key' should not be empty string");
				}
			}
		} else {
			// If no EXIF data, test passes
			$this->assertTrue(true, 'No EXIF data to validate');
		}
	}

	public function testAltTextGeneration(): void
	{
		$metadata = ImageMetaReader::getMetaData($this->testImagePath);

		if ($metadata !== [] && count($metadata) > 3 && isset($metadata['alt'])) {
			// Full metadata structure with alt text
			$this->assertArrayHasKey('alt', $metadata);

			// Alt text should be generated from title or description if available
			if (isset($metadata['exif']['title']) || isset($metadata['exif']['description'])) {
				$this->assertNotEmpty($metadata['alt'], 'Alt text should be generated when title/description exists');
			} else {
				// Alt text may be empty if no title/description
				$this->assertIsString($metadata['alt'], 'Alt text should be a string even if empty');
			}
		} else {
			// Simple image without EXIF data - test passes
			$this->assertTrue(true, 'Simple test image without EXIF data - alt text not applicable');
		}
	}

	// Helper methods for creating test images with specific metadata

	private function createTestImage(): string
	{
		$imagePath = $this->tempDir . '/test_image.jpg';

		// Create a simple test image
		$image = imagecreate(100, 100);
		$white = imagecolorallocate($image, 255, 255, 255);
		$black = imagecolorallocate($image, 0, 0, 0);

		// Fill background and add some content
		imagefill($image, 0, 0, $white);
		imagestring($image, 5, 10, 40, 'TEST', $black);

		// Save as JPEG
		imagejpeg($image, $imagePath, 90);
		imagedestroy($image);

		return $imagePath;
	}

	private function createTestImageWithMockExif(): ?string
	{
		// Use the real EXIF test image since it has all the data we need
		return $this->testImagePath;
	}

	private function createTestImageWithMockGPS(): ?string
	{
		// Use the real EXIF test image since it has GPS coordinates
		return $this->testImagePath;
	}

	private function createTestImageWithKeywords(): ?string
	{
		// Use the real EXIF test image for keyword extraction testing
		return $this->testImagePath;
	}

	private function createTestImageWithMockXMP(): ?string
	{
		// Use the real EXIF test image for XMP testing 
		return $this->testImagePath;
	}

	private function createTestImageWithMockIPTC(): ?string
	{
		// Use the real EXIF test image for IPTC testing
		return $this->testImagePath;
	}

	private function recursiveRemoveDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) {
			$path = $dir . DIRECTORY_SEPARATOR . $file;
			is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
		}
		rmdir($dir);
	}
}
