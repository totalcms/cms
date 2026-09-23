<?php

use TotalCMS\Domain\Media\Service\UploadPreviewUrl;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;

// The preview URL the upload response ships must match what ImageField and
// GalleryField render on a page refresh — same dimensions, quality and cache
// token — so the droplet can swap its thumbnail for the stored image.
describe('UploadPreviewUrl', function (): void {
	test('builds the public ImageWorks URL for an image upload (no /api prefix)', function (): void {
		$object = new ObjectData('post-1', [
			'image' => new ImageData(['name' => 'photo.jpg', 'hash' => 'abc123', 'uploadDate' => '2026-07-19T10:00:00+00:00']),
		]);

		expect(UploadPreviewUrl::build('image', '', 'blog', 'post-1', 'image', null, $object))
			->toBe('/imageworks/blog/post-1/image.jpg?w=600&h=600&q=60&cache=abc123');
	});

	test('prefixes the configured base path on subdirectory installs', function (): void {
		$object = new ObjectData('post-1', [
			'image' => new ImageData(['name' => 'photo.jpg', 'hash' => 'abc123', 'uploadDate' => '2026-07-19T10:00:00+00:00']),
		]);

		expect(UploadPreviewUrl::build('image', '/cms', 'blog', 'post-1', 'image', null, $object))
			->toBe('/cms/imageworks/blog/post-1/image.jpg?w=600&h=600&q=60&cache=abc123');
	});

	test('builds the gallery URL for the newest (last) image', function (): void {
		$object = new ObjectData('trip', [
			'gallery' => new GalleryData([
				['name' => 'old.jpg', 'hash' => 'aaa111', 'uploadDate' => '2026-07-01T10:00:00+00:00'],
				['name' => 'new-2a3f1.png', 'hash' => 'bbb222', 'uploadDate' => '2026-07-19T10:00:00+00:00'],
			]),
		]);

		expect(UploadPreviewUrl::build('gallery', '', 'albums', 'trip', 'gallery', null, $object))
			->toBe('/imageworks/albums/trip/gallery/new-2a3f1.png?w=600&h=600&q=60&cache=bbb222');
	});

	test('walks card subpaths and emits a nested property path', function (): void {
		$object = new ObjectData('post-1', [
			'mycard' => new CardData([
				'image' => ['name' => 'hero.webp', 'hash' => 'ccc333', 'uploadDate' => '2026-07-19T10:00:00+00:00'],
			]),
		]);

		expect(UploadPreviewUrl::build('image', '', 'blog', 'post-1', 'mycard', 'image', $object))
			->toBe('/imageworks/blog/post-1/mycard/image.webp?w=600&h=600&q=60&cache=ccc333');
	});

	test('returns empty for non-image property types', function (): void {
		expect(UploadPreviewUrl::build('file', '', 'blog', 'post-1', 'attachment', null, new ObjectData('post-1', [])))->toBe('');
	});

	test('returns empty when the saved image data cannot be found', function (): void {
		expect(UploadPreviewUrl::build('image', '', 'blog', 'post-1', 'image', null, new ObjectData('post-1', [])))->toBe('');
	});
});
