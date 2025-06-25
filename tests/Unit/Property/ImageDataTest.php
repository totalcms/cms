<?php

namespace Tests\Unit\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\ListData;

#[CoversClass(ImageData::class)]
final class ImageDataTest extends TestCase
{
	public function testCreatesImageDataWithAllProperties(): void
	{
		$imageData = [
			'name'       => 'photo.jpg',
			'alt'        => 'Beautiful sunset photo',
			'mime'       => 'image/jpeg',
			'size'       => 2048576,
			'width'      => 1920,
			'height'     => 1080,
			'featured'   => true,
			'link'       => 'https://example.com/photo',
			'tags'       => ['sunset', 'nature', 'photography'],
			'palette'    => ['#FF5733', '#C70039', '#900C3F'],
			'focalpoint' => ['x' => 30, 'y' => 70],
			'exif'       => [
				'camera' => 'Canon EOS R5',
				'lens'   => 'RF 24-70mm F2.8',
				'iso'    => 100,
				'date'   => '2023-01-15T10:30:00+00:00',
			],
			'uploadDate' => '2023-01-16T09:00:00+00:00',
		];

		$data = new ImageData($imageData);

		$this->assertSame('photo.jpg', $data->name);
		$this->assertSame('Beautiful sunset photo', $data->alt);
		$this->assertSame('image/jpeg', $data->mime);
		$this->assertSame(2048576, $data->size);
		$this->assertSame(1920, $data->width);
		$this->assertSame(1080, $data->height);
		$this->assertTrue($data->featured);
		$this->assertSame('https://example.com/photo', $data->link);
		$this->assertSame(['#FF5733', '#C70039', '#900C3F'], $data->palette);
		$this->assertSame(['x' => 30, 'y' => 70], $data->focalpoint);
		$this->assertInstanceOf(ListData::class, $data->tags);
		$this->assertInstanceOf(DateData::class, $data->uploadDate);
		$this->assertIsArray($data->exif);
		$this->assertSame('Canon EOS R5', $data->exif['camera']);
	}

	public function testUsesDefaultsForMissingProperties(): void
	{
		$data = new ImageData();

		$this->assertSame('', $data->name);
		$this->assertSame('', $data->alt);
		$this->assertSame('', $data->mime);
		$this->assertSame(0, $data->size);
		$this->assertSame(0, $data->width);
		$this->assertSame(0, $data->height);
		$this->assertFalse($data->featured);
		$this->assertSame('', $data->link);
		$this->assertSame([], $data->palette);
		$this->assertSame(ImageData::DEFAULT_FOCALPOINT, $data->focalpoint);
		$this->assertSame([], $data->tags->list);
		$this->assertSame(['nodata' => ''], $data->exif);
	}

	public function testUsesDefaultFocalpointWhenNotProvided(): void
	{
		$data = new ImageData();

		$this->assertSame(['x' => 50, 'y' => 50], $data->focalpoint);
	}

	public function testHandlesDangerousFilenames(): void
	{
		$dangerousNames = [
			'../../../etc/passwd.jpg',
			'<script>alert(1)</script>.png',
			'../../upload.php.jpg', // Directory traversal with double extension
		];

		foreach ($dangerousNames as $name) {
			$data = new ImageData(['name' => $name]);
			$this->assertSame($name, $data->name);
		}
	}

	public function testHandlesDangerousMimeTypes(): void
	{
		$dangerousMimes = [
			'text/html', // Could be served as HTML
			'image/svg+xml', // Can contain scripts
			'application/javascript',
		];

		foreach ($dangerousMimes as $mime) {
			$data = new ImageData(['mime' => $mime]);
			$this->assertSame($mime, $data->mime);
		}
	}

	public function testHandlesMaliciousAltText(): void
	{
		$maliciousAlt = [
			'<script>alert("xss")</script>',
			'javascript:void(0)',
			"'; DROP TABLE images; --",
		];

		foreach ($maliciousAlt as $alt) {
			$data = new ImageData(['alt' => $alt]);
			$this->assertSame($alt, $data->alt);
		}
	}

	public function testHandlesValidImageDimensions(): void
	{
		$dimensions = [
			['width' => 1920, 'height' => 1080], // 1080p
			['width' => 3840, 'height' => 2160], // 4K
			['width' => 1, 'height' => 1],       // Minimum
		];

		foreach ($dimensions as $dim) {
			$data = new ImageData($dim);
			$this->assertSame($dim['width'], $data->width);
			$this->assertSame($dim['height'], $data->height);
		}
	}

	public function testAcceptsValidFocalpoints(): void
	{
		$validFocalpoints = [
			['x' => 0, 'y' => 0],     // Top-left
			['x' => 50, 'y' => 50],   // Center
			['x' => 100, 'y' => 100], // Bottom-right
		];

		foreach ($validFocalpoints as $fp) {
			$data = new ImageData(['focalpoint' => $fp]);
			$this->assertSame($fp, $data->focalpoint);
		}
	}

	public function testAcceptsValidColorPalettes(): void
	{
		$validPalettes = [
			['#FF0000', '#00FF00', '#0000FF'], // RGB
			['#FFFFFF', '#000000'],            // Black & White
			[], // Empty palette
		];

		foreach ($validPalettes as $palette) {
			$data = new ImageData(['palette' => $palette]);
			$this->assertSame($palette, $data->palette);
		}
	}

	public function testProcessesExifDataCorrectly(): void
	{
		$exifData = [
			'camera' => 'Canon EOS R5',
			'lens'   => 'RF 24-70mm F2.8',
			'iso'    => 100,
			'date'   => '2023-01-15T10:30:00+00:00',
		];

		$data = new ImageData(['exif' => $exifData]);

		$this->assertSame('Canon EOS R5', $data->exif['camera']);
		$this->assertSame('RF 24-70mm F2.8', $data->exif['lens']);
		$this->assertSame(100, $data->exif['iso']);
	}

	public function testHandlesMaliciousExifData(): void
	{
		$maliciousExif = [
			'camera'      => '<script>alert("camera")</script>',
			'description' => '"; DROP TABLE images; --',
			'software'    => 'javascript:void(0)',
		];

		$data = new ImageData(['exif' => $maliciousExif]);

		foreach ($maliciousExif as $key => $value) {
			$this->assertSame($value, $data->exif[$key]);
		}
	}

	public function testTransformsToArrayCorrectly(): void
	{
		$imageData = [
			'name'     => 'test.jpg',
			'alt'      => 'Test image',
			'width'    => 800,
			'height'   => 600,
			'featured' => true,
			'tags'     => ['test'],
		];

		$data        = new ImageData($imageData);
		$transformed = $data->transform();

		$this->assertIsArray($transformed);
		$this->assertSame('test.jpg', $transformed['name']);
		$this->assertSame('Test image', $transformed['alt']);
		$this->assertSame(800, $transformed['width']);
		$this->assertSame(600, $transformed['height']);
		$this->assertTrue($transformed['featured']);
		$this->assertIsArray($transformed['tags']);
		$this->assertIsString($transformed['uploadDate']);
		$this->assertIsArray($transformed['exif']);
		$this->assertIsArray($transformed['focalpoint']);
		$this->assertIsArray($transformed['palette']);
	}

	public function testSerializesToJsonStringCorrectly(): void
	{
		$data = new ImageData(['name' => 'test.jpg', 'alt' => 'Test']);
		$json = (string)$data;

		$this->assertIsString($json);
		$this->assertStringContainsString('"name":"test.jpg"', $json);
		$this->assertStringContainsString('"alt":"Test"', $json);

		$decoded = json_decode($json, true);
		$this->assertIsArray($decoded);
		$this->assertSame('test.jpg', $decoded['name']);
	}

	public function testProcessesTagsCorrectly(): void
	{
		$tags = ['nature', 'landscape', 'photography'];
		$data = new ImageData(['tags' => $tags]);

		$this->assertSame($tags, $data->tags->list);
	}

	public function testHandlesExtremelyLargeFileSizes(): void
	{
		$largeSize = PHP_INT_MAX;
		$data      = new ImageData(['size' => $largeSize]);
		$this->assertSame($largeSize, $data->size);
	}

	public function testAcceptsSettingsParameter(): void
	{
		$settings = ['quality' => 85, 'format' => 'webp'];
		$data     = new ImageData([], $settings);

		$this->assertSame($settings, $data->settings);
	}

	public function testIdentifiesCommonImageMimeTypes(): void
	{
		$imageMimes = [
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/svg+xml',
		];

		foreach ($imageMimes as $mime) {
			$data = new ImageData(['mime' => $mime]);
			$this->assertSame($mime, $data->mime);
		}
	}

	public function testCalculatesAspectRatioCorrectly(): void
	{
		$data        = new ImageData(['width' => 1920, 'height' => 1080]);
		$aspectRatio = $data->width / $data->height;

		$this->assertEqualsWithDelta(16 / 9, $aspectRatio, 0.01);
	}
}
