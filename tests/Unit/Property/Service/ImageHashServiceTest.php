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

	/*
	 * uploadDate used to feed the hash, on the theory that it was needed to
	 * catch a file being replaced in place. It isn't: PropertyRepository's
	 * getUniqueFilename() appends a uniqid suffix to every upload, so `name`
	 * already differs whenever new bytes arrive — including a re-upload of the
	 * same file. All uploadDate added was instability: an image nothing had
	 * touched got a fresh hash on any write that rebuilt the field, which
	 * churned ImageWorks crops and made two identical images compare unequal.
	 */
	test('ImageHashService → ignores uploadDate (a new upload changes name instead)', function (): void {
		$a = ['name' => 'photo.jpg', 'uploadDate' => '2026-04-17T12:00:00+00:00'];
		$b = ['name' => 'photo.jpg', 'uploadDate' => '2026-04-18T12:00:00+00:00'];

		expect(ImageHashService::compute($a))->toBe(ImageHashService::compute($b));
	});

	test('ImageHashService → still changes when a new upload lands (name differs)', function (): void {
		$a = ['name' => 'photo-a1b2c.jpg', 'uploadDate' => '2026-04-17T12:00:00+00:00'];
		$b = ['name' => 'photo-d3e4f.jpg', 'uploadDate' => '2026-04-18T12:00:00+00:00'];

		expect(ImageHashService::compute($a))->not->toBe(ImageHashService::compute($b));
	});

	test('ImageHashService → hashes two empty images identically', function (): void {
		// The sync regression: pages with no image at all read as "differs"
		// between two installs purely because each side stamped its own
		// uploadDate onto an empty field.
		$local = ['name' => '', 'size' => 0, 'uploadDate' => '2026-08-19T03:55:06+00:00'];
		$prod  = ['name' => '', 'size' => 0, 'uploadDate' => '2026-08-19T04:21:32+00:00'];

		expect(ImageHashService::compute($local))->toBe(ImageHashService::compute($prod));
	});

	test('ImageHashService → handles empty image data', function (): void {
		expect(ImageHashService::compute([]))->toMatch('/^[0-9a-f]{8}$/');
	});
});
