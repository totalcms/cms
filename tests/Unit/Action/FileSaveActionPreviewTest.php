<?php

use TotalCMS\Action\Property\File\FileSaveAction;
use TotalCMS\Domain\Media\Service\HeicConverter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Data\CardData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\SaverFactory;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;

function createPreviewAction(string $apiBase): FileSaveAction
{
	$config         = test()->createMock(Config::class);
	$config->tmpdir = sys_get_temp_dir() . '/totalcms-test-' . uniqid();
	$config->api    = $apiBase;

	return new FileSaveAction(
		test()->createMock(JsonRenderer::class),
		test()->createMock(SaverFactory::class),
		$config,
		test()->createMock(HeicConverter::class),
		test()->createMock(HttpClientInterface::class),
		new FileUploadValidator(),
	);
}

/** @param array<string,string> $args */
function buildPreviewUrl(string $apiBase, string $type, array $args, ?string $subpath, ObjectData $object): string
{
	$action = createPreviewAction($apiBase);
	$method = new ReflectionMethod($action, 'buildPreviewUrl');

	$result = $method->invoke($action, $type, $args, $subpath, $object);
	expect($result)->toBeString();

	return $result;
}

describe('FileSaveAction preview URL', function (): void {
	test('builds the public ImageWorks URL for an image upload (no /api prefix)', function (): void {
		$object = new ObjectData('post-1', [
			'image' => new ImageData(['name' => 'photo.jpg', 'hash' => 'abc123', 'uploadDate' => '2026-07-19T10:00:00+00:00']),
		]);

		$url = buildPreviewUrl(
			'',
			'image',
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'image'],
			null,
			$object,
		);

		expect($url)->toBe('/imageworks/blog/post-1/image.jpg?w=600&h=600&q=60&cache=abc123');
	});

	test('prefixes the configured base path on subdirectory installs', function (): void {
		$object = new ObjectData('post-1', [
			'image' => new ImageData(['name' => 'photo.jpg', 'hash' => 'abc123', 'uploadDate' => '2026-07-19T10:00:00+00:00']),
		]);

		$url = buildPreviewUrl(
			'/cms',
			'image',
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'image'],
			null,
			$object,
		);

		expect($url)->toBe('/cms/imageworks/blog/post-1/image.jpg?w=600&h=600&q=60&cache=abc123');
	});

	test('builds the gallery URL for the newest (last) image', function (): void {
		$object = new ObjectData('trip', [
			'gallery' => new GalleryData([
				['name' => 'old.jpg', 'hash' => 'aaa111', 'uploadDate' => '2026-07-01T10:00:00+00:00'],
				['name' => 'new-2a3f1.png', 'hash' => 'bbb222', 'uploadDate' => '2026-07-19T10:00:00+00:00'],
			]),
		]);

		$url = buildPreviewUrl(
			'',
			'gallery',
			['collection' => 'albums', 'id' => 'trip', 'property' => 'gallery'],
			null,
			$object,
		);

		expect($url)->toBe('/imageworks/albums/trip/gallery/new-2a3f1.png?w=600&h=600&q=60&cache=bbb222');
	});

	test('walks card subpaths and emits a nested property path', function (): void {
		$object = new ObjectData('post-1', [
			'mycard' => new CardData([
				'image' => ['name' => 'hero.webp', 'hash' => 'ccc333', 'uploadDate' => '2026-07-19T10:00:00+00:00'],
			]),
		]);

		$url = buildPreviewUrl(
			'',
			'image',
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'mycard'],
			'image',
			$object,
		);

		expect($url)->toBe('/imageworks/blog/post-1/mycard/image.webp?w=600&h=600&q=60&cache=ccc333');
	});

	test('returns empty for non-image property types', function (): void {
		$object = new ObjectData('post-1', []);

		$url = buildPreviewUrl(
			'',
			'file',
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'attachment'],
			null,
			$object,
		);

		expect($url)->toBe('');
	});

	test('returns empty when the saved image data cannot be found', function (): void {
		$object = new ObjectData('post-1', []);

		$url = buildPreviewUrl(
			'',
			'image',
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'image'],
			null,
			$object,
		);

		expect($url)->toBe('');
	});
});
