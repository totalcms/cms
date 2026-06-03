<?php

declare(strict_types=1);

use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\DepotSaver;
use TotalCMS\Domain\Property\Service\FileSaver;
use TotalCMS\Domain\Property\Service\GallerySaver;
use TotalCMS\Domain\Property\Service\ImageSaver;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

/** Drop a real file into a property's storage directory (the upload destination). */
function seedPropertyFile(string $collection, string $id, string $property, string $filename, string $contents, string $subpath = ''): void
{
	$dir = objectFilesPath($collection, $id) . '/' . $property . ($subpath !== '' ? '/' . $subpath : '');
	if (!is_dir($dir)) {
		mkdir($dir, 0777, true);
	}
	file_put_contents($dir . '/' . $filename, $contents);
}

/** A tiny but valid multi-color JPEG, written to a property's storage dir. */
function seedPropertyJpeg(string $collection, string $id, string $property, string $filename, int $w = 24, int $h = 24): void
{
	$img = imagecreatetruecolor($w, $h);
	imagefilledrectangle($img, 0, 0, (int)($w / 2), $h, imagecolorallocate($img, 200, 30, 30));
	imagefilledrectangle($img, (int)($w / 2), 0, $w, $h, imagecolorallocate($img, 30, 30, 200));
	ob_start();
	imagejpeg($img, null, 90);
	$bytes = (string)ob_get_clean();
	unset($img);
	seedPropertyFile($collection, $id, $property, $filename, $bytes);
}

it('rebuilds a file property from an orphaned file', function (): void {
	seedPropertyFile('docs', 'obj1', 'attachment', 'doc.pdf', '%PDF-1.4 fake pdf content');

	$saver  = $this->app->getContainer()->get(FileSaver::class);
	$result = $saver->rebuildFromStorage('docs', 'obj1', 'attachment');

	expect($result)->toBeInstanceOf(FileData::class);
	expect($result->name)->toBe('doc.pdf');
	expect($result->transform()['size'])->toBeGreaterThan(0);
});

it('returns null when a file property has no files on disk', function (): void {
	$saver = $this->app->getContainer()->get(FileSaver::class);
	expect($saver->rebuildFromStorage('docs', 'empty', 'attachment'))->toBeNull();
});

it('rebuilds an image property (dimensions + mime) from an orphaned image', function (): void {
	seedPropertyJpeg('blog', 'p1', 'photo', 'pic.jpg', 24, 16);

	$saver  = $this->app->getContainer()->get(ImageSaver::class);
	$result = $saver->rebuildFromStorage('blog', 'p1', 'photo');

	expect($result)->toBeInstanceOf(ImageData::class);
	expect($result->name)->toBe('pic.jpg');
	$t = $result->transform();
	expect($t['width'])->toBe(24);
	expect($t['height'])->toBe(16);
	expect($t['mime'])->toBe('image/jpeg');
});

it('rebuilds a gallery property from multiple orphaned images, ordered by name', function (): void {
	seedPropertyJpeg('blog', 'g1', 'photos', 'b-second.jpg', 20, 10);
	seedPropertyJpeg('blog', 'g1', 'photos', 'a-first.jpg', 30, 12);

	$saver  = $this->app->getContainer()->get(GallerySaver::class);
	$result = $saver->rebuildFromStorage('blog', 'g1', 'photos');

	expect($result)->toBeInstanceOf(GalleryData::class);
	expect($result->images)->toHaveCount(2);
	expect($result->images[0]->name)->toBe('a-first.jpg'); // sorted by filename
	expect($result->images[0]->transform()['width'])->toBe(30);
	expect($result->images[1]->name)->toBe('b-second.jpg');
});

it('rebuilds a depot property tree (root files + nested folders)', function (): void {
	seedPropertyFile('docs', 'd1', 'archive', 'a.pdf', '%PDF-1.4 root');
	seedPropertyFile('docs', 'd1', 'archive', 'b.pdf', '%PDF-1.4 nested', 'sub');

	$saver  = $this->app->getContainer()->get(DepotSaver::class);
	$result = $saver->rebuildFromStorage('docs', 'd1', 'archive');

	expect($result)->toBeInstanceOf(DepotData::class);

	$top = $result->transform()['files'];

	$rootFiles = array_values(array_filter($top, fn (array $e): bool => ($e['mime'] ?? '') !== 'folder' && ($e['name'] ?? '') === 'a.pdf'));
	expect($rootFiles)->toHaveCount(1);

	$subFolder = array_values(array_filter($top, fn (array $e): bool => ($e['mime'] ?? '') === 'folder' && ($e['name'] ?? '') === 'sub'));
	expect($subFolder)->toHaveCount(1);
	expect(array_column($subFolder[0]['files'], 'name'))->toContain('b.pdf');
});
