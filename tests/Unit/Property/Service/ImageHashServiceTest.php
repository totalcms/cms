<?php

declare(strict_types=1);

use TotalCMS\Domain\Property\Service\ImageHashService;

describe('ImageHashService', function (): void {
	test('ImageHashService → returns an 8-char hex string', function (): void {
		$hash = ImageHashService::compute(['name' => 'photo.jpg']);

		expect($hash)->toBeString();
		expect(strlen($hash))->toBe(8);
		expect($hash)->toMatch('/^[0-9a-f]{8}$/');
	});

	test('ImageHashService → is deterministic for identical input', function (): void {
		$data = [
			'name'       => 'photo.jpg',
			'alt'        => 'A photo',
			'focalpoint' => ['x' => 50, 'y' => 50],
		];

		expect(ImageHashService::compute($data))->toBe(ImageHashService::compute($data));
	});

	test('ImageHashService → is key-order independent', function (): void {
		$a = ['name' => 'photo.jpg', 'alt' => 'text', 'size' => 100];
		$b = ['size' => 100, 'alt' => 'text', 'name' => 'photo.jpg'];

		expect(ImageHashService::compute($a))->toBe(ImageHashService::compute($b));
	});

	test('ImageHashService → sorts nested associative arrays', function (): void {
		$a = ['exif' => ['camera' => 'Canon', 'iso' => 100, 'lens' => '50mm']];
		$b = ['exif' => ['lens' => '50mm', 'camera' => 'Canon', 'iso' => 100]];

		expect(ImageHashService::compute($a))->toBe(ImageHashService::compute($b));
	});

	test('ImageHashService → preserves list order (palette is semantic)', function (): void {
		$a = ['palette' => ['#ff0000', '#00ff00', '#0000ff']];
		$b = ['palette' => ['#0000ff', '#00ff00', '#ff0000']];

		expect(ImageHashService::compute($a))->not->toBe(ImageHashService::compute($b));
	});

	test('ImageHashService → excludes hash field from computation', function (): void {
		$without = ['name' => 'photo.jpg', 'alt' => 'text'];
		$withOld = ['name' => 'photo.jpg', 'alt' => 'text', 'hash' => 'oldvalue'];

		expect(ImageHashService::compute($without))->toBe(ImageHashService::compute($withOld));
	});

	test('ImageHashService → excludes updateDate and modifiedAt', function (): void {
		$base   = ['name' => 'photo.jpg'];
		$update = ['name' => 'photo.jpg', 'updateDate' => '2026-04-17T12:00:00+00:00'];
		$mod    = ['name' => 'photo.jpg', 'modifiedAt' => '2026-04-17T12:00:00+00:00'];

		expect(ImageHashService::compute($base))->toBe(ImageHashService::compute($update));
		expect(ImageHashService::compute($base))->toBe(ImageHashService::compute($mod));
	});

	test('ImageHashService → changes when focal point changes', function (): void {
		$a = ['name' => 'photo.jpg', 'focalpoint' => ['x' => 50, 'y' => 50]];
		$b = ['name' => 'photo.jpg', 'focalpoint' => ['x' => 25, 'y' => 75]];

		expect(ImageHashService::compute($a))->not->toBe(ImageHashService::compute($b));
	});

	test('ImageHashService → changes when alt text changes', function (): void {
		$a = ['name' => 'photo.jpg', 'alt' => 'original'];
		$b = ['name' => 'photo.jpg', 'alt' => 'updated'];

		expect(ImageHashService::compute($a))->not->toBe(ImageHashService::compute($b));
	});

	test('ImageHashService → changes when uploadDate changes', function (): void {
		$a = ['name' => 'photo.jpg', 'uploadDate' => '2026-04-17T12:00:00+00:00'];
		$b = ['name' => 'photo.jpg', 'uploadDate' => '2026-04-18T12:00:00+00:00'];

		expect(ImageHashService::compute($a))->not->toBe(ImageHashService::compute($b));
	});

	test('ImageHashService → handles empty image data', function (): void {
		expect(ImageHashService::compute([]))->toMatch('/^[0-9a-f]{8}$/');
	});
});
