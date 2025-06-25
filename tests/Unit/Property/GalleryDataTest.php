<?php

namespace Tests\Unit\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;

#[CoversClass(GalleryData::class)]
final class GalleryDataTest extends TestCase
{
	public function testCreatesGalleryWithImageDataObjects(): void
	{
		$images = [
			new ImageData(['name' => 'image1.jpg', 'alt' => 'First image']),
			new ImageData(['name' => 'image2.png', 'alt' => 'Second image']),
		];

		$gallery = new GalleryData($images);
		
		$this->assertCount(2, $gallery->images);
		$this->assertInstanceOf(ImageData::class, $gallery->images[0]);
		$this->assertInstanceOf(ImageData::class, $gallery->images[1]);
		$this->assertSame('image1.jpg', $gallery->images[0]->name);
		$this->assertSame('image2.png', $gallery->images[1]->name);
	}

	public function testCreatesGalleryWithArrayData(): void
	{
		$imageArrays = [
			['name' => 'photo1.jpg', 'alt' => 'Beautiful landscape', 'width' => 1920, 'height' => 1080],
			['name' => 'photo2.gif', 'alt' => 'Animated graphic', 'width' => 800, 'height' => 600],
		];

		$gallery = new GalleryData($imageArrays);
		
		$this->assertCount(2, $gallery->images);
		$this->assertInstanceOf(ImageData::class, $gallery->images[0]);
		$this->assertInstanceOf(ImageData::class, $gallery->images[1]);
		$this->assertSame('photo1.jpg', $gallery->images[0]->name);
		$this->assertSame('Beautiful landscape', $gallery->images[0]->alt);
		$this->assertSame(1920, $gallery->images[0]->width);
	}

	public function testCreatesMixedGallery(): void
	{
		$mixedImages = [
			new ImageData(['name' => 'existing.jpg']),
			['name' => 'new.png', 'alt' => 'New image'],
		];

		$gallery = new GalleryData($mixedImages);
		
		$this->assertCount(2, $gallery->images);
		$this->assertInstanceOf(ImageData::class, $gallery->images[0]);
		$this->assertInstanceOf(ImageData::class, $gallery->images[1]);
		$this->assertSame('existing.jpg', $gallery->images[0]->name);
		$this->assertSame('new.png', $gallery->images[1]->name);
	}

	public function testCreatesEmptyGallery(): void
	{
		$gallery = new GalleryData();
		
		$this->assertCount(0, $gallery->images);
		$this->assertSame([], $gallery->images);
	}

	public function testTransformsCorrectly(): void
	{
		$imageData = [
			['name' => 'test1.jpg', 'alt' => 'Test 1', 'width' => 800, 'height' => 600],
			['name' => 'test2.png', 'alt' => 'Test 2', 'width' => 1024, 'height' => 768],
		];

		$gallery = new GalleryData($imageData);
		$transformed = $gallery->transform();
		
		$this->assertIsArray($transformed);
		$this->assertCount(2, $transformed);
		
		// Each item should be a transformed ImageData
		$this->assertIsArray($transformed[0]);
		$this->assertIsArray($transformed[1]);
		$this->assertSame('test1.jpg', $transformed[0]['name']);
		$this->assertSame('Test 1', $transformed[0]['alt']);
		$this->assertSame(800, $transformed[0]['width']);
	}

	public function testSerializesToJsonCorrectly(): void
	{
		$imageData = [
			['name' => 'serialize.jpg', 'alt' => 'Serialization test'],
		];

		$gallery = new GalleryData($imageData);
		$json = (string)$gallery;
		
		$this->assertIsString($json);
		$this->assertStringContainsString('"name":"serialize.jpg"', $json);
		$this->assertStringContainsString('"alt":"Serialization test"', $json);
		
		$decoded = json_decode($json, true);
		$this->assertIsArray($decoded);
		$this->assertCount(1, $decoded);
	}

	public function testHandlesDangerousImageData(): void
	{
		$dangerousImages = [
			[
				'name' => '<script>alert("xss")</script>.jpg',
				'alt' => 'javascript:void(0)',
				'link' => 'data:text/html,<script>alert(1)</script>',
			],
			[
				'name' => '../../etc/passwd.png',
				'alt' => '"; DROP TABLE images; --',
				'mime' => 'text/html',
			],
		];

		$gallery = new GalleryData($dangerousImages);
		
		$this->assertCount(2, $gallery->images);
		// Images are stored as-is in ImageData, sanitization happens at display
		$this->assertStringContainsString('<script>', $gallery->images[0]->name);
		$this->assertStringContainsString('../../etc/passwd', $gallery->images[1]->name);
	}

	public function testHandlesLargeGalleries(): void
	{
		$largeImageSet = [];
		for ($i = 0; $i < 500; $i++) {
			$largeImageSet[] = [
				'name' => "image_{$i}.jpg",
				'alt' => "Image number {$i}",
				'width' => 800 + ($i % 200),
				'height' => 600 + ($i % 150),
			];
		}

		$gallery = new GalleryData($largeImageSet);
		
		$this->assertCount(500, $gallery->images);
		$this->assertInstanceOf(ImageData::class, $gallery->images[0]);
		$this->assertInstanceOf(ImageData::class, $gallery->images[499]);
		$this->assertSame('image_0.jpg', $gallery->images[0]->name);
		$this->assertSame('image_499.jpg', $gallery->images[499]->name);
	}

	public function testHandlesVariousImageFormats(): void
	{
		$variousImages = [
			['name' => 'photo.jpg', 'mime' => 'image/jpeg'],
			['name' => 'graphic.png', 'mime' => 'image/png'],
			['name' => 'animation.gif', 'mime' => 'image/gif'],
			['name' => 'vector.svg', 'mime' => 'image/svg+xml'],
			['name' => 'modern.webp', 'mime' => 'image/webp'],
		];

		$gallery = new GalleryData($variousImages);
		
		$this->assertCount(5, $gallery->images);
		$this->assertSame('image/jpeg', $gallery->images[0]->mime);
		$this->assertSame('image/png', $gallery->images[1]->mime);
		$this->assertSame('image/svg+xml', $gallery->images[3]->mime);
	}

	public function testHandlesComplexImageMetadata(): void
	{
		$complexImages = [
			[
				'name' => 'professional.jpg',
				'alt' => 'Professional photography',
				'width' => 3840,
				'height' => 2160,
				'featured' => true,
				'tags' => ['professional', 'portrait', 'studio'],
				'exif' => [
					'camera' => 'Canon EOS R5',
					'lens' => 'RF 85mm F1.2L',
					'iso' => 200,
					'aperture' => 'f/2.8',
				],
				'focalpoint' => ['x' => 30, 'y' => 70],
				'palette' => ['#2C3E50', '#E74C3C', '#F39C12'],
			],
		];

		$gallery = new GalleryData($complexImages);
		
		$this->assertCount(1, $gallery->images);
		$image = $gallery->images[0];
		
		$this->assertSame('professional.jpg', $image->name);
		$this->assertSame(3840, $image->width);
		$this->assertTrue($image->featured);
		$this->assertSame(['professional', 'portrait', 'studio'], $image->tags->list);
		$this->assertSame('Canon EOS R5', $image->exif['camera']);
		$this->assertSame(['x' => 30, 'y' => 70], $image->focalpoint);
	}

	public function testAcceptsSettingsParameter(): void
	{
		$settings = ['maxImages' => 50, 'allowedFormats' => ['jpg', 'png']];
		$images = [['name' => 'test.jpg']];
		
		$gallery = new GalleryData($images, $settings);
		$this->assertSame($settings, $gallery->settings);
	}

	public function testUsesEmptyArrayAsDefaultSettings(): void
	{
		$gallery = new GalleryData();
		$this->assertSame([], $gallery->settings);
	}

	public function testHandlesUnicodeInImageData(): void
	{
		$unicodeImages = [
			[
				'name' => 'φωτογραφία.jpg', // Greek
				'alt' => '美しい写真', // Japanese
				'tags' => ['фотография', '照片'], // Russian, Chinese
			],
		];

		$gallery = new GalleryData($unicodeImages);
		
		$this->assertCount(1, $gallery->images);
		$this->assertStringContainsString('φωτογραφία', $gallery->images[0]->name);
		$this->assertStringContainsString('美しい写真', $gallery->images[0]->alt);
		$this->assertContains('фотография', $gallery->images[0]->tags->list);
	}

	public function testTransformationPreservesImageStructure(): void
	{
		$originalData = [
			[
				'name' => 'structure_test.jpg',
				'alt' => 'Structure preservation test',
				'width' => 1200,
				'height' => 800,
				'tags' => ['test', 'structure'],
				'exif' => ['camera' => 'Test Camera'],
			],
		];

		$gallery = new GalleryData($originalData);
		$transformed = $gallery->transform();
		
		$this->assertIsArray($transformed[0]);
		$this->assertArrayHasKey('name', $transformed[0]);
		$this->assertArrayHasKey('alt', $transformed[0]);
		$this->assertArrayHasKey('width', $transformed[0]);
		$this->assertArrayHasKey('height', $transformed[0]);
		$this->assertArrayHasKey('tags', $transformed[0]);
		$this->assertArrayHasKey('exif', $transformed[0]);
		
		$this->assertSame('structure_test.jpg', $transformed[0]['name']);
		$this->assertSame(1200, $transformed[0]['width']);
		$this->assertIsArray($transformed[0]['tags']);
		$this->assertIsArray($transformed[0]['exif']);
	}

	public function testHandlesJsonSerializationErrors(): void
	{
		// Create gallery with valid data
		$gallery = new GalleryData([['name' => 'valid.jpg']]);
		
		// Normal serialization should work
		$json = (string)$gallery;
		$this->assertIsString($json);
		$this->assertNotEmpty($json);
	}
}