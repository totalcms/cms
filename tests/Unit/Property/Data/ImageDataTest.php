<?php

use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\ListData;

describe('ImageData', function (): void {
	test('ImageData → creates with empty data', function (): void {
		$image = new ImageData();

		expect($image->alt)->toBe('');
		expect($image->exif)->toBe(['nodata' => '']);
		expect($image->featured)->toBe(false);
		expect($image->focalpoint)->toBe(ImageData::DEFAULT_FOCALPOINT);
		expect($image->height)->toBe(0);
		expect($image->link)->toBe('');
		expect($image->mime)->toBe('');
		expect($image->name)->toBe('');
		expect($image->palette)->toBe([]);
		expect($image->size)->toBe(0);
		expect($image->tags)->toBeInstanceOf(ListData::class);
		expect($image->uploadDate)->toBeInstanceOf(DateData::class);
		expect($image->width)->toBe(0);
		expect($image->settings)->toBe([]);
	});

	test('ImageData → uses default focalpoint constant', function (): void {
		$image = new ImageData();

		expect(ImageData::DEFAULT_FOCALPOINT)->toBe(['x' => 50, 'y' => 50]);
		expect($image->focalpoint)->toBe(['x' => 50, 'y' => 50]);
	});

	test('ImageData → creates with complete image data', function (): void {
		$imageData = [
			'alt'        => 'Beautiful landscape photo',
			'exif'       => ['camera' => 'Canon EOS R5', 'iso' => '100'],
			'featured'   => true,
			'focalpoint' => ['x' => 75, 'y' => 25],
			'height'     => 2048,
			'link'       => 'https://example.com/photo',
			'mime'       => 'image/jpeg',
			'name'       => 'landscape.jpg',
			'palette'    => ['#ff0000', '#00ff00', '#0000ff'],
			'size'       => 1024000,
			'tags'       => ['landscape', 'nature', 'photography'],
			'uploadDate' => '2024-01-15T10:30:00+00:00',
			'width'      => 3072,
		];

		$image = new ImageData($imageData);

		expect($image->alt)->toBe('Beautiful landscape photo');
		expect($image->exif)->toBe(['camera' => 'Canon EOS R5', 'iso' => '100']);
		expect($image->featured)->toBe(true);
		expect($image->focalpoint)->toBe(['x' => 75, 'y' => 25]);
		expect($image->height)->toBe(2048);
		expect($image->link)->toBe('https://example.com/photo');
		expect($image->mime)->toBe('image/jpeg');
		expect($image->name)->toBe('landscape.jpg');
		expect($image->palette)->toBe(['#ff0000', '#00ff00', '#0000ff']);
		expect($image->size)->toBe(1024000);
		expect($image->tags->list)->toBe(['landscape', 'nature', 'photography']);
		expect($image->uploadDate->date)->toBe('2024-01-15T10:30:00+00:00');
		expect($image->width)->toBe(3072);
	});

	test('ImageData → creates with settings', function (): void {
		$settings = ['maxWidth' => 4000, 'quality' => 85];
		$image    = new ImageData([], $settings);

		expect($image->settings)->toBe($settings);
	});

	test('ImageData → converts string numbers to integers', function (): void {
		$imageData = [
			'height' => '1080',
			'size'   => '2048000',
			'width'  => '1920',
		];

		$image = new ImageData($imageData);

		expect($image->height)->toBe(1080);
		expect($image->size)->toBe(2048000);
		expect($image->width)->toBe(1920);
	});

	test('ImageData → handles invalid numeric values', function (): void {
		$imageData = [
			'height' => 'invalid',
			'size'   => 'also-invalid',
			'width'  => 'bad-value',
		];

		$image = new ImageData($imageData);

		expect($image->height)->toBe(0);
		expect($image->size)->toBe(0);
		expect($image->width)->toBe(0);
	});

	test('ImageData → sets default upload date when empty', function (): void {
		$image = new ImageData(['uploadDate' => '']);

		// Should be today's date in ISO format
		expect($image->uploadDate->date)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/');
	});

	test('ImageData → preserves provided upload date', function (): void {
		$specificDate = '2023-12-25T15:45:30+00:00';
		$image        = new ImageData(['uploadDate' => $specificDate]);

		expect($image->uploadDate->date)->toBe($specificDate);
	});

	test('ImageData → processes EXIF date field', function (): void {
		$imageData = [
			'exif' => [
				'camera' => 'Nikon D850',
				'date'   => '2024-06-15T14:30:00+00:00',
				'lens'   => '24-70mm f/2.8',
			],
		];

		$image = new ImageData($imageData);

		// EXIF date should be processed through DateData
		expect($image->exif['camera'])->toBe('Nikon D850');
		expect($image->exif['date'])->toBe('2024-06-15T14:30:00+00:00');
		expect($image->exif['lens'])->toBe('24-70mm f/2.8');
	});

	test('ImageData → handles EXIF without date field', function (): void {
		$imageData = [
			'exif' => [
				'camera' => 'Sony A7R IV',
				'iso'    => '200',
			],
		];

		$image = new ImageData($imageData);

		expect($image->exif)->toBe(['camera' => 'Sony A7R IV', 'iso' => '200']);
	});

	test('ImageData → handles different mime types', function (): void {
		$mimeTypes = [
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/svg+xml',
		];

		foreach ($mimeTypes as $mime) {
			$image = new ImageData(['mime' => $mime]);
			expect($image->mime)->toBe($mime);
		}
	});

	test('ImageData → processes tags through ListData', function (): void {
		$image = new ImageData(['tags' => ['photo', 'nature', 'photo', '', 'outdoor']]);

		// ListData removes empty values and duplicates
		expect($image->tags->list)->toBe(['photo', 'nature', 'outdoor']);
	});

	test('ImageData → handles custom focalpoint', function (): void {
		$customFocalpoint = ['x' => 10, 'y' => 90];
		$image            = new ImageData(['focalpoint' => $customFocalpoint]);

		expect($image->focalpoint)->toBe($customFocalpoint);
	});

	test('ImageData → handles color palette', function (): void {
		$palette = ['#ff5733', '#33ff57', '#3357ff', '#ffff33'];
		$image   = new ImageData(['palette' => $palette]);

		expect($image->palette)->toBe($palette);
	});

	test('ImageData → handles boolean featured field', function (): void {
		$featuredImage = new ImageData(['featured' => true]);
		$regularImage  = new ImageData(['featured' => false]);
		$defaultImage  = new ImageData();

		expect($featuredImage->featured)->toBe(true);
		expect($regularImage->featured)->toBe(false);
		expect($defaultImage->featured)->toBe(false);
	});

	test('ImageData → transforms to array correctly', function (): void {
		$imageData = [
			'alt'        => 'Test image',
			'exif'       => ['camera' => 'Test Camera'],
			'featured'   => true,
			'focalpoint' => ['x' => 25, 'y' => 75],
			'height'     => 800,
			'link'       => 'https://test.com',
			'mime'       => 'image/png',
			'name'       => 'test.png',
			'palette'    => ['#000000', '#ffffff'],
			'size'       => 500000,
			'tags'       => ['test', 'image'],
			'uploadDate' => '2024-01-01T00:00:00+00:00',
			'width'      => 1200,
		];

		$image  = new ImageData($imageData);
		$result = $image->transform();

		expect($result)->toHaveKey('alt', 'Test image');
		expect($result)->toHaveKey('exif', ['camera' => 'Test Camera']);
		expect($result)->toHaveKey('featured', true);
		expect($result)->toHaveKey('focalpoint', ['x' => 25, 'y' => 75]);
		expect($result)->toHaveKey('height', 800);
		expect($result)->toHaveKey('link', 'https://test.com');
		expect($result)->toHaveKey('mime', 'image/png');
		expect($result)->toHaveKey('name', 'test.png');
		expect($result)->toHaveKey('palette', ['#000000', '#ffffff']);
		expect($result)->toHaveKey('size', 500000);
		expect($result)->toHaveKey('tags', ['test', 'image']);
		expect($result)->toHaveKey('uploadDate', '2024-01-01T00:00:00+00:00');
		expect($result)->toHaveKey('width', 1200);
	});

	test('ImageData → converts to JSON string', function (): void {
		$imageData = [
			'name'   => 'photo.jpg',
			'mime'   => 'image/jpeg',
			'width'  => 1920,
			'height' => 1080,
		];

		$image = new ImageData($imageData);
		$json  = (string)$image;

		expect($json)->toBeString();
		expect($json)->not->toBe('');

		$decoded = json_decode($json, true);
		expect($decoded)->toBeArray();
		expect($decoded['name'])->toBe('photo.jpg');
		expect($decoded['mime'])->toBe('image/jpeg');
		expect($decoded['width'])->toBe(1920);
		expect($decoded['height'])->toBe(1080);
	});

	test('ImageData → handles complex image scenario', function (): void {
		$complexImage = [
			'name'       => 'wedding-portrait-2024.jpg',
			'alt'        => 'Beautiful wedding portrait with natural lighting',
			'mime'       => 'image/jpeg',
			'width'      => 6000,
			'height'     => 4000,
			'size'       => 15728640, // 15MB
			'featured'   => true,
			'focalpoint' => ['x' => 62, 'y' => 38], // Focus on faces
			'palette'    => ['#f8f4e6', '#d4af37', '#8b4513', '#ffffff', '#2f1b14'],
			'tags'       => ['wedding', 'portrait', 'professional', 'natural-light', '2024'],
			'link'       => 'https://photographer.com/gallery/wedding-2024',
			'uploadDate' => '2024-08-15T18:30:00+00:00',
			'exif'       => [
				'camera'   => 'Canon EOS R5',
				'lens'     => 'Canon RF 85mm f/1.2L',
				'iso'      => '400',
				'aperture' => 'f/1.8',
				'shutter'  => '1/200',
				'date'     => '2024-08-15T15:45:00+00:00',
			],
		];

		$image = new ImageData($complexImage);

		expect($image->name)->toBe('wedding-portrait-2024.jpg');
		expect($image->alt)->toBe('Beautiful wedding portrait with natural lighting');
		expect($image->width)->toBe(6000);
		expect($image->height)->toBe(4000);
		expect($image->featured)->toBe(true);
		expect($image->focalpoint)->toBe(['x' => 62, 'y' => 38]);
		expect(count($image->palette))->toBe(5);
		expect(count($image->tags->list))->toBe(5);
		expect($image->exif['camera'])->toBe('Canon EOS R5');
		expect($image->exif['date'])->toBe('2024-08-15T15:45:00+00:00');
	});

	test('ImageData → handles empty alt text', function (): void {
		$image = new ImageData(['alt' => '']);

		expect($image->alt)->toBe('');
	});

	test('ImageData → handles zero dimensions', function (): void {
		$image = new ImageData([
			'width'  => 0,
			'height' => 0,
			'size'   => 0,
		]);

		expect($image->width)->toBe(0);
		expect($image->height)->toBe(0);
		expect($image->size)->toBe(0);
	});

	test('ImageData → hash defaults to empty string', function (): void {
		$image = new ImageData();

		expect($image->hash)->toBe('');
	});

	test('ImageData → preserves stored hash without recomputing', function (): void {
		$image = new ImageData(['hash' => 'abc12345', 'name' => 'photo.jpg']);

		expect($image->hash)->toBe('abc12345');
	});

	test('ImageData → includes hash in transform output', function (): void {
		$image  = new ImageData(['hash' => 'abc12345']);
		$result = $image->transform();

		expect($result)->toHaveKey('hash', 'abc12345');
	});

	test('ImageData → handles various aspect ratios', function (): void {
		$aspectRatios = [
			['width' => 1920, 'height' => 1080], // 16:9
			['width' => 1920, 'height' => 1920], // 1:1 (square)
			['width' => 1080, 'height' => 1920], // 9:16 (portrait)
			['width' => 2560, 'height' => 1440], // 16:9 (2K)
			['width' => 3840, 'height' => 2160], // 16:9 (4K)
		];

		foreach ($aspectRatios as $dimensions) {
			$image = new ImageData($dimensions);
			expect($image->width)->toBe($dimensions['width']);
			expect($image->height)->toBe($dimensions['height']);
		}
	});
});
