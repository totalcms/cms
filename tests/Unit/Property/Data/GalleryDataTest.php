<?php

use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;

describe('GalleryData', function (): void {
	test('GalleryData → creates with empty images array', function (): void {
		$gallery = new GalleryData();

		expect($gallery->images)->toBe([]);
		expect($gallery->settings)->toBe([]);
	});

	test('GalleryData → creates with settings', function (): void {
		$settings = ['maxImages' => 50, 'sortable' => true];
		$gallery  = new GalleryData([], $settings);

		expect($gallery->settings)->toBe($settings);
	});

	test('GalleryData → creates with array of image data', function (): void {
		$imagesData = [
			[
				'name'   => 'photo1.jpg',
				'mime'   => 'image/jpeg',
				'width'  => 1920,
				'height' => 1080,
				'alt'    => 'First photo',
			],
			[
				'name'   => 'photo2.png',
				'mime'   => 'image/png',
				'width'  => 800,
				'height' => 600,
				'alt'    => 'Second photo',
			],
		];

		$gallery = new GalleryData($imagesData);

		expect(count($gallery->images))->toBe(2);
		expect($gallery->images[0])->toBeInstanceOf(ImageData::class);
		expect($gallery->images[1])->toBeInstanceOf(ImageData::class);
		expect($gallery->images[0]->name)->toBe('photo1.jpg');
		expect($gallery->images[1]->name)->toBe('photo2.png');
	});

	test('GalleryData → accepts existing ImageData instances', function (): void {
		$image1 = new ImageData(['name' => 'existing1.jpg', 'mime' => 'image/jpeg']);
		$image2 = new ImageData(['name' => 'existing2.png', 'mime' => 'image/png']);

		$gallery = new GalleryData([$image1, $image2]);

		expect(count($gallery->images))->toBe(2);
		expect($gallery->images[0])->toBe($image1);
		expect($gallery->images[1])->toBe($image2);
		expect($gallery->images[0]->name)->toBe('existing1.jpg');
		expect($gallery->images[1]->name)->toBe('existing2.png');
	});

	test('GalleryData → handles mixed ImageData instances and arrays', function (): void {
		$existingImage = new ImageData(['name' => 'existing.jpg', 'mime' => 'image/jpeg']);
		$newImageData  = ['name' => 'new.png', 'mime' => 'image/png', 'width' => 500];

		$gallery = new GalleryData([$existingImage, $newImageData]);

		expect(count($gallery->images))->toBe(2);
		expect($gallery->images[0])->toBe($existingImage);
		expect($gallery->images[1])->toBeInstanceOf(ImageData::class);
		expect($gallery->images[0]->name)->toBe('existing.jpg');
		expect($gallery->images[1]->name)->toBe('new.png');
		expect($gallery->images[1]->width)->toBe(500);
	});

	test('GalleryData → transforms to array of image data', function (): void {
		$imagesData = [
			[
				'name'     => 'landscape.jpg',
				'mime'     => 'image/jpeg',
				'width'    => 2048,
				'height'   => 1024,
				'alt'      => 'Beautiful landscape',
				'featured' => true,
			],
			[
				'name'     => 'portrait.png',
				'mime'     => 'image/png',
				'width'    => 800,
				'height'   => 1200,
				'alt'      => 'Portrait shot',
				'featured' => false,
			],
		];

		$gallery = new GalleryData($imagesData);
		$result  = $gallery->transform();

		expect($result)->toBeArray();
		expect(count($result))->toBe(2);
		expect($result[0])->toHaveKey('name', 'landscape.jpg');
		expect($result[0])->toHaveKey('width', 2048);
		expect($result[0])->toHaveKey('featured', true);
		expect($result[1])->toHaveKey('name', 'portrait.png');
		expect($result[1])->toHaveKey('height', 1200);
		expect($result[1])->toHaveKey('featured', false);
	});

	test('GalleryData → transform returns empty array for empty gallery', function (): void {
		$gallery = new GalleryData();
		$result  = $gallery->transform();

		expect($result)->toBe([]);
	});

	test('GalleryData → converts to JSON string', function (): void {
		$imagesData = [
			['name' => 'test1.jpg', 'mime' => 'image/jpeg', 'width' => 100],
			['name' => 'test2.png', 'mime' => 'image/png', 'height' => 200],
		];

		$gallery = new GalleryData($imagesData);
		$json    = (string)$gallery;

		expect($json)->toBeString();
		expect($json)->not->toBe('');

		$decoded = json_decode($json, true);
		expect($decoded)->toBeArray();
		expect(count($decoded))->toBe(2);
		expect($decoded[0]['name'])->toBe('test1.jpg');
		expect($decoded[1]['name'])->toBe('test2.png');
	});

	test('GalleryData → __toString returns empty array JSON for empty gallery', function (): void {
		$gallery = new GalleryData();
		$json    = (string)$gallery;

		expect($json)->toBe('[]');
		expect(json_decode($json, true))->toBe([]);
	});

	test('GalleryData → handles single image', function (): void {
		$imageData = [
			'name'   => 'single-photo.jpg',
			'mime'   => 'image/jpeg',
			'width'  => 1500,
			'height' => 1000,
			'alt'    => 'Single photo in gallery',
		];

		$gallery = new GalleryData([$imageData]);

		expect(count($gallery->images))->toBe(1);
		expect($gallery->images[0]->name)->toBe('single-photo.jpg');
		expect($gallery->images[0]->alt)->toBe('Single photo in gallery');
	});

	test('GalleryData → handles complex image data', function (): void {
		$complexImages = [
			[
				'name'       => 'wedding-ceremony.jpg',
				'mime'       => 'image/jpeg',
				'width'      => 6000,
				'height'     => 4000,
				'size'       => 15728640,
				'alt'        => 'Wedding ceremony with beautiful lighting',
				'featured'   => true,
				'focalpoint' => ['x' => 65, 'y' => 35],
				'palette'    => ['#f8f4e6', '#d4af37', '#8b4513'],
				'tags'       => ['wedding', 'ceremony', 'photography'],
				'exif'       => [
					'camera' => 'Canon EOS R5',
					'lens'   => '70-200mm f/2.8',
					'iso'    => '800',
				],
			],
			[
				'name'       => 'wedding-reception.jpg',
				'mime'       => 'image/jpeg',
				'width'      => 5000,
				'height'     => 3333,
				'size'       => 12582912,
				'alt'        => 'Reception dance floor',
				'featured'   => false,
				'focalpoint' => ['x' => 50, 'y' => 60],
				'palette'    => ['#1a1a1a', '#ffcc00', '#ff6b6b'],
				'tags'       => ['wedding', 'reception', 'dancing'],
				'exif'       => [
					'camera' => 'Canon EOS R5',
					'lens'   => '24-70mm f/2.8',
					'iso'    => '1600',
				],
			],
		];

		$gallery = new GalleryData($complexImages);

		expect(count($gallery->images))->toBe(2);
		expect($gallery->images[0]->featured)->toBe(true);
		expect($gallery->images[1]->featured)->toBe(false);
		expect($gallery->images[0]->focalpoint)->toBe(['x' => 65, 'y' => 35]);
		expect(count($gallery->images[0]->palette))->toBe(3);
		expect(count($gallery->images[0]->tags->list))->toBe(3);
	});

	test('GalleryData → preserves ImageData properties through transform', function (): void {
		$imageData = [
			'name'       => 'nature.jpg',
			'alt'        => 'Nature photograph',
			'width'      => 1920,
			'height'     => 1280,
			'featured'   => true,
			'tags'       => ['nature', 'landscape'],
			'uploadDate' => '2024-03-15T12:00:00+00:00',
		];

		$gallery = new GalleryData([$imageData]);
		$result  = $gallery->transform();

		expect($result[0]['name'])->toBe('nature.jpg');
		expect($result[0]['alt'])->toBe('Nature photograph');
		expect($result[0]['width'])->toBe(1920);
		expect($result[0]['height'])->toBe(1280);
		expect($result[0]['featured'])->toBe(true);
		expect($result[0]['tags'])->toBe(['nature', 'landscape']);
		expect($result[0]['uploadDate'])->toBe('2024-03-15T12:00:00+00:00');
	});

	test('GalleryData → handles large gallery', function (): void {
		$images = [];
		for ($i = 1; $i <= 100; $i++) {
			$images[] = [
				'name'   => "image_{$i}.jpg",
				'mime'   => 'image/jpeg',
				'width'  => 1920,
				'height' => 1080,
				'alt'    => "Image number {$i}",
			];
		}

		$gallery = new GalleryData($images);

		expect(count($gallery->images))->toBe(100);
		expect($gallery->images[0]->name)->toBe('image_1.jpg');
		expect($gallery->images[49]->name)->toBe('image_50.jpg');
		expect($gallery->images[99]->name)->toBe('image_100.jpg');
	});

	test('GalleryData → handles various image formats', function (): void {
		$mixedFormats = [
			['name' => 'photo.jpg', 'mime' => 'image/jpeg'],
			['name' => 'graphic.png', 'mime' => 'image/png'],
			['name' => 'animation.gif', 'mime' => 'image/gif'],
			['name' => 'modern.webp', 'mime' => 'image/webp'],
			['name' => 'vector.svg', 'mime' => 'image/svg+xml'],
		];

		$gallery = new GalleryData($mixedFormats);

		expect(count($gallery->images))->toBe(5);
		expect($gallery->images[0]->mime)->toBe('image/jpeg');
		expect($gallery->images[1]->mime)->toBe('image/png');
		expect($gallery->images[2]->mime)->toBe('image/gif');
		expect($gallery->images[3]->mime)->toBe('image/webp');
		expect($gallery->images[4]->mime)->toBe('image/svg+xml');
	});

	test('GalleryData → maintains image order', function (): void {
		$orderedImages = [
			['name' => 'first.jpg', 'alt' => 'First image'],
			['name' => 'second.jpg', 'alt' => 'Second image'],
			['name' => 'third.jpg', 'alt' => 'Third image'],
			['name' => 'fourth.jpg', 'alt' => 'Fourth image'],
		];

		$gallery = new GalleryData($orderedImages);
		$result  = $gallery->transform();

		expect($result[0]['name'])->toBe('first.jpg');
		expect($result[1]['name'])->toBe('second.jpg');
		expect($result[2]['name'])->toBe('third.jpg');
		expect($result[3]['name'])->toBe('fourth.jpg');
	});

	test('GalleryData → handles empty image data arrays', function (): void {
		$emptyImages = [[], [], []];

		$gallery = new GalleryData($emptyImages);

		expect(count($gallery->images))->toBe(3);
		foreach ($gallery->images as $image) {
			expect($image)->toBeInstanceOf(ImageData::class);
			expect($image->name)->toBe('');
			expect($image->mime)->toBe('');
		}
	});
});
