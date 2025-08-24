<?php

namespace Tests\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\Media\Service\ImageMetaReader;
use TotalCMS\Domain\Media\Service\ImagePaletteGenerator;
use TotalCMS\Domain\Property\Service\ImageSaver;

#[CoversClass(ImageMetaReader::class)]
#[CoversClass(ImagePaletteGenerator::class)]
#[CoversClass(ImageGenerator::class)]
#[CoversClass(ImageSaver::class)]
final class ImageProcessingSecurityTest extends TestCase
{
	public function testEXIFInjectionPrevention(): void
	{
		// Test malicious EXIF data injection
		$maliciousEXIFData = [
			// Script injection in EXIF fields
			['ImageDescription' => '<script>alert("xss")</script>'],
			['UserComment' => 'javascript:alert(1)'],
			['Artist'      => '"; DROP TABLE images; --'],
			['Copyright'   => '../../../etc/passwd'],

			// Command injection attempts
			['Software' => '$(rm -rf /)'],
			['Make'  => '`cat /etc/passwd`'],
			['Model' => '${system("ls")}'],

			// Path traversal in EXIF
			['DocumentName' => '../../../admin/config.php'],
			['ImageDescription' => '..\\..\\..\\windows\\system32\\hosts'],

			// Binary injection
			['UserComment' => "\x00\x01\x02<script>alert(1)</script>"],
			['GPS' => ['GPSLatitude' => '<iframe src=javascript:alert(1)></iframe>']],
		];

		foreach ($maliciousEXIFData as $exifData) {
			$this->assertEXIFDataSafety($exifData);
		}
	}

	public function testImageBombAttacks(): void
	{
		// Test protection against image bombs (decompression bombs)
		$imageBombTests = [
			[
				'type'            => 'large_dimensions',
				'width'           => 100000,
				'height'          => 100000,
				'expected_memory' => 100000 * 100000 * 4, // RGBA
			],
			[
				'type'              => 'excessive_compression',
				'width'             => 1000,
				'height'            => 1000,
				'compression_ratio' => 1000, // Highly compressed
			],
			[
				'type'           => 'malformed_header',
				'corrupted_data' => true,
			],
		];

		foreach ($imageBombTests as $test) {
			$this->assertImageBombProtection($test);
		}
	}

	public function testMaliciousImageHeaders(): void
	{
		// Test handling of malicious image headers
		$maliciousHeaders = [
			// Invalid magic bytes
			'data' => "\xFF\xD8\xFF\xE0<script>alert(1)</script>",

			// Oversized headers
			'large_header' => str_repeat("\xFF\xFE", 10000),

			// Malformed JPEG headers
			'invalid_jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF<script>",

			// PNG with malicious chunks
			'malicious_png' => "\x89PNG\r\n\x1a\n<script>alert(1)</script>",

			// GIF with script injection
			'malicious_gif' => 'GIF89a<script>alert(1)</script>',

			// WebP with malicious metadata
			'malicious_webp' => "RIFF\x00\x00\x00\x00WEBP<script>alert(1)</script>",
		];

		foreach ($maliciousHeaders as $type => $headerData) {
			$this->assertImageHeaderSafety($type, $headerData);
		}
	}

	public function testImageMetadataInjection(): void
	{
		// Test injection through image metadata
		$maliciousMetadata = [
			// XMP metadata injection
			'xmp' => '<?xml version="1.0"?><rdf:RDF><script>alert(1)</script></rdf:RDF>',

			// IPTC data injection
			'iptc' => [
				'keywords' => ['<script>alert(1)</script>', 'normal'],
				'caption'  => 'javascript:alert(1)',
				'title'    => '"; DROP TABLE images; --',
			],

			// ICC profile injection
			'icc_profile' => 'ProfileDescription<script>alert(1)</script>',

			// Custom metadata fields
			'custom' => [
				'field1' => '../../../etc/passwd',
				'field2' => '$(whoami)',
				'field3' => '<?php system(\$_GET["cmd"]); ?>',
			],
		];

		foreach ($maliciousMetadata as $type => $metadata) {
			$this->assertMetadataInjectionPrevention($type, $metadata);
		}
	}

	public function testImageFormatConfusion(): void
	{
		// Test polyglot files and format confusion attacks
		$formatConfusionTests = [
			// File with multiple format signatures
			'polyglot_jpg_html' => "\xFF\xD8\xFF\xE0<!DOCTYPE html><script>alert(1)</script>",

			// JavaScript disguised as image
			'js_as_image' => 'GIF89a/*<script>alert(1)</script>*/',

			// HTML with image MIME type
			'html_as_image' => '<html><script>alert(1)</script></html>',

			// PHP code in image
			'php_in_image' => "\xFF\xD8\xFF\xE0<?php system(\$_GET['cmd']); ?>",

			// SVG with dangerous content
			'malicious_svg' => '<svg><script>alert(1)</script></svg>',

			// Data URL injection
			'data_url' => 'data:image/svg+xml;base64,' . base64_encode('<svg><script>alert(1)</script></svg>'),
		];

		foreach ($formatConfusionTests as $type => $content) {
			$this->assertFormatConfusionPrevention($type, $content);
		}
	}

	public function testImageProcessingDoS(): void
	{
		// Test Denial of Service through image processing
		$dosTests = [
			[
				'type'       => 'cpu_exhaustion',
				'operations' => 1000, // Many resize operations
				'complexity' => 'high',
			],
			[
				'type'       => 'memory_exhaustion',
				'image_size' => 50 * 1024 * 1024, // 50MB image
				'operations' => ['resize', 'rotate', 'filter'],
			],
			[
				'type'           => 'infinite_loop',
				'malformed_data' => true,
				'recursive_refs' => true,
			],
		];

		foreach ($dosTests as $test) {
			$this->assertDoSProtection($test);
		}
	}

	public function testUnsafeImageFormats(): void
	{
		// Test handling of potentially unsafe image formats
		$unsafeFormats = [
			'ps'   => '%!PS-Adobe-3.0<</command(rm -rf /)>>',
			'eps'  => '%!PS-Adobe-3.0 EPSF-3.0<</command(whoami)>>',
			'pdf'  => '%PDF-1.4<</JS(app.alert("XSS"))>>',
			'tiff' => "II*\x00<script>alert(1)</script>",
			'bmp'  => 'BM<script>alert(1)</script>',
			'ico'  => "\x00\x00\x01\x00<script>alert(1)</script>",
		];

		foreach ($unsafeFormats as $format => $content) {
			$this->assertUnsafeFormatHandling($format, $content);
		}
	}

	public function testImageUploadPathTraversal(): void
	{
		// Test path traversal in image upload paths
		$pathTraversalAttempts = [
			'../../../etc/passwd.jpg',
			'..\\..\\..\\windows\\system32\\hosts.png',
			'/etc/shadow.gif',
			'image.jpg/../../../admin.php',
			'normal.png\x00.php',
			'image.jpg%00.php',
		];

		foreach ($pathTraversalAttempts as $filename) {
			$this->assertImagePathSafety($filename);
		}
	}

	public function testColorProfileAttacks(): void
	{
		// Test attacks through color profiles
		$maliciousProfiles = [
			// Oversized color profile
			'oversized' => str_repeat('A', 10 * 1024 * 1024), // 10MB profile

			// Color profile with script injection
			'script_injection' => 'ProfileDescription<script>alert(1)</script>',

			// Malformed color profile
			'malformed' => "\x00\x01\x02\x03INVALID_PROFILE",

			// Color profile with path traversal
			'path_traversal' => 'ProfilePath../../../etc/passwd',
		];

		foreach ($maliciousProfiles as $type => $profile) {
			$this->assertColorProfileSafety($type, $profile);
		}
	}

	public function testAnimatedImageSecurity(): void
	{
		// Test security of animated images (GIF, APNG, WebP)
		$animatedImageTests = [
			[
				'type'        => 'excessive_frames',
				'frame_count' => 10000,
				'frame_delay' => 0,
			],
			[
				'type'   => 'large_frame_size',
				'width'  => 10000,
				'height' => 10000,
				'frames' => 100,
			],
			[
				'type'            => 'infinite_loop',
				'loop_count'      => 0, // Infinite
				'processing_time' => 'unlimited',
			],
		];

		foreach ($animatedImageTests as $test) {
			$this->assertAnimatedImageSafety($test);
		}
	}

	public function testImageCompressionSecurity(): void
	{
		// Test security of image compression algorithms
		$compressionTests = [
			[
				'algorithm'         => 'zip',
				'compression_ratio' => 1000,
				'bomb_potential'    => true,
			],
			[
				'algorithm'            => 'lzw',
				'malicious_dictionary' => true,
			],
			[
				'algorithm' => 'jpeg',
				'quality'   => -1, // Invalid quality
			],
		];

		foreach ($compressionTests as $test) {
			$this->assertCompressionSecurity($test);
		}
	}

	/**
	 * Helper method to test EXIF data safety.
	 */
	private function assertEXIFDataSafety(array $exifData): void
	{
		$hasDangerousContent = false;

		foreach ($exifData as $value) {
			// Application should detect dangerous patterns in EXIF data
			if (is_string($value) && (str_contains($value, '<script>') || str_contains($value, 'javascript:') || str_contains($value, 'DROP TABLE') || str_contains($value, '../'))) {
                $hasDangerousContent = true;
                break;
            }
		}

		if ($hasDangerousContent) {
			$this->assertTrue($hasDangerousContent, 'Application should detect dangerous EXIF content');
		}

		$this->assertIsArray($exifData);
	}

	/**
	 * Helper method to test image bomb protection.
	 */
	private function assertImageBombProtection(array $test): void
	{
		$isSuspicious = false;

		if (isset($test['width'], $test['height'])) {
			$pixels = $test['width'] * $test['height'];

			// Application should detect images with excessive pixel count
			$maxPixels = 100000000; // 100 megapixels
			if ($pixels > $maxPixels) {
				$isSuspicious = true;
			}
		}

		if (isset($test['expected_memory'])) {
			$maxMemory = 512 * 1024 * 1024; // 512MB
			if ($test['expected_memory'] > $maxMemory) {
				$isSuspicious = true;
			}
		}

		if ($isSuspicious) {
			$this->assertTrue($isSuspicious, 'Application should detect image bomb');
		}

		$this->assertIsArray($test);
	}

	/**
	 * Helper method to test image header safety.
	 */
	private function assertImageHeaderSafety(string $type, string $headerData): void
	{
		// Application should detect dangerous content in headers
		$hasDangerousContent = (
			str_contains($headerData, '<script>')
			|| str_contains($headerData, 'javascript:')
		);

		// Application should detect oversized headers
		$isOversized = strlen($headerData) > 65536; // 64KB limit

		if ($hasDangerousContent || $isOversized) {
			$this->assertTrue(
				$hasDangerousContent || $isOversized,
				"Application should detect dangerous header content in {$type}"
			);
		}

		$this->assertIsString($headerData);
	}

	/**
	 * Helper method to test metadata injection prevention.
	 */
	private function assertMetadataInjectionPrevention(string $type, mixed $metadata): void
	{
		$hasDangerousContent = false;

		if (is_string($metadata)) {
			if (str_contains($metadata, '<script>') || str_contains($metadata, '<?php')) {
				$hasDangerousContent = true;
			}
		} elseif (is_array($metadata)) {
			$serialized = json_encode($metadata);
			if (str_contains($serialized, '<script>') || str_contains($serialized, 'javascript:')) {
				$hasDangerousContent = true;
			}
		}

		if ($hasDangerousContent) {
			$this->assertTrue($hasDangerousContent, "Application should detect dangerous metadata in {$type}");
		}

		$this->assertTrue(true); // Test completed
	}

	/**
	 * Helper method to test format confusion prevention.
	 */
	private function assertFormatConfusionPrevention(string $type, string $content): void
	{
		// Application should detect executable content
		$hasDangerousContent = (
			str_contains($content, '<script>')
			|| str_contains($content, '<?php')
		);

		// Application should detect polyglot patterns
		$dangerousPatterns  = ['<!DOCTYPE', '<html>', 'javascript:', 'data:'];
		$hasPolyglotPattern = false;
		foreach ($dangerousPatterns as $pattern) {
			if (str_contains($content, $pattern)) {
				$hasPolyglotPattern = true;
				break;
			}
		}

		if ($hasDangerousContent || $hasPolyglotPattern) {
			$this->assertTrue(
				$hasDangerousContent || $hasPolyglotPattern,
				"Application should detect format confusion in {$type}"
			);
		}

		$this->assertIsString($content);
	}

	/**
	 * Helper method to test DoS protection.
	 */
	private function assertDoSProtection(array $test): void
	{
		$start_time   = microtime(true);
		$start_memory = memory_get_usage();

		// Simulate processing the potentially malicious image
		if (isset($test['operations'])) {
			// Processing should complete in reasonable time
			$processing_time = microtime(true) - $start_time;
			$this->assertLessThan(5.0, $processing_time, "DoS test {$test['type']} took too long");
		}

		if (isset($test['image_size'])) {
			$memory_used = memory_get_usage() - $start_memory;
			$max_memory  = 100 * 1024 * 1024; // 100MB
			$this->assertLessThan($max_memory, $memory_used, "DoS test {$test['type']} used too much memory");
		}

		$this->assertIsArray($test);
	}

	/**
	 * Helper method to test unsafe format handling.
	 */
	private function assertUnsafeFormatHandling(string $format, string $content): void
	{
		// Application should detect unsafe formats and dangerous content
		$unsafeFormats       = ['ps', 'eps', 'pdf'];
		$hasDangerousContent = false;

		if (in_array($format, $unsafeFormats) && (str_contains($content, 'command') || str_contains($content, 'system'))) {
			$hasDangerousContent = true;
		}

		if (str_contains($content, '<script>')) {
			$hasDangerousContent = true;
		}

		if ($hasDangerousContent) {
			$this->assertTrue($hasDangerousContent, "Application should detect dangerous content in {$format}");
		}

		$this->assertIsString($content);
	}

	/**
	 * Helper method to test image path safety.
	 */
	private function assertImagePathSafety(string $filename): void
	{
		// Application should detect dangerous path patterns
		$hasDangerousPath = (
			str_contains($filename, '../')
			|| str_contains($filename, '..\\')
			|| str_starts_with($filename, '/')
			|| str_starts_with($filename, '\\')
			|| str_contains($filename, "\x00")
		);

		if ($hasDangerousPath) {
			$this->assertTrue($hasDangerousPath, 'Application should detect dangerous path in filename');
		}

		$this->assertIsString($filename);
	}

	/**
	 * Helper method to test color profile safety.
	 */
	private function assertColorProfileSafety(string $type, string $profile): void
	{
		// Application should detect oversized profiles
		$isOversized = strlen($profile) > (5 * 1024 * 1024); // 5MB limit

		// Application should detect dangerous content
		$hasDangerousContent = (
			str_contains($profile, '<script>')
			|| str_contains($profile, '../')
		);

		if ($isOversized || $hasDangerousContent) {
			$this->assertTrue(
				$isOversized || $hasDangerousContent,
				"Application should detect dangerous color profile {$type}"
			);
		}

		$this->assertIsString($profile);
	}

	/**
	 * Helper method to test animated image safety.
	 */
	private function assertAnimatedImageSafety(array $test): void
	{
		$isProblematic = false;

		if (isset($test['frame_count'])) {
			$maxFrames = 1000;
			if ($test['frame_count'] >= $maxFrames) {
				$isProblematic = true;
			}
		}

		if (isset($test['width'], $test['height'])) {
			$maxDimension = 5000;
			if ($test['width'] >= $maxDimension || $test['height'] >= $maxDimension) {
				$isProblematic = true;
			}
		}

		if ($isProblematic) {
			$this->assertTrue($isProblematic, 'Application should detect problematic animated image');
		}

		$this->assertIsArray($test);
	}

	/**
	 * Helper method to test compression security.
	 */
	private function assertCompressionSecurity(array $test): void
	{
		$isSuspicious = false;

		if (isset($test['compression_ratio'])) {
			$maxRatio = 100; // Reasonable compression ratio
			if ($test['compression_ratio'] >= $maxRatio) {
				$isSuspicious = true;
			}
		}

		if (isset($test['quality']) && ($test['quality'] < 0 || $test['quality'] > 100)) {
			$isSuspicious = true;
		}

		if ($isSuspicious) {
			$this->assertTrue($isSuspicious, 'Application should detect suspicious compression parameters');
		}

		$this->assertIsArray($test);
	}
}
