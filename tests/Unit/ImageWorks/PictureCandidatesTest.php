<?php

declare(strict_types=1);

use TotalCMS\Domain\ImageWorks\Service\PictureCandidates;
use TotalCMS\Support\Config;

function pictureCandidatesConfig(array $imageworks): Config
{
	$config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->imageworks = $imageworks;

	return $config;
}

test('widths drop sizes wider than the image and add the image width', function (): void {
	$list = [480, 768, 1024, 1440, 1920];

	expect(PictureCandidates::widths($list, ['width' => 1600, 'height' => 900], []))->toBe([480, 768, 1024, 1440, 1600]);
	expect(PictureCandidates::widths($list, ['width' => 1000, 'height' => 900], []))->toBe([480, 768, 1000]);
});

test('a base w caps the widths', function (): void {
	expect(PictureCandidates::widths([480, 768, 1024], ['width' => 1600, 'height' => 900], ['w' => 800]))->toBe([480, 768]);
});

test('an unknown source width uses the list as given', function (): void {
	expect(PictureCandidates::widths([768, 480], ['width' => 0], []))->toBe([480, 768]);
});

test('formats add native once', function (): void {
	expect(PictureCandidates::formats(['avif', 'webp'], ['name' => 'a.jpg']))->toBe(['avif', 'webp', 'jpg']);
	expect(PictureCandidates::formats(['avif', 'webp', 'jpg'], ['name' => 'a.jpg']))->toBe(['avif', 'webp', 'jpg']);
});

test('gif images get only their own format', function (): void {
	expect(PictureCandidates::formats(['avif', 'webp'], ['name' => 'a.gif']))->toBe(['gif']);
});

test('nativeFormat falls back to jpg', function (): void {
	expect(PictureCandidates::nativeFormat(['name' => 'a.PNG']))->toBe('png');
	expect(PictureCandidates::nativeFormat(['name' => 'a.tiff']))->toBe('jpg');
});

test('settings merge options over imageworks.picture over defaults', function (): void {
	expect(PictureCandidates::settings(null))->toBe(PictureCandidates::DEFAULTS);

	$config = pictureCandidatesConfig(['picture' => ['widths' => [300, 600], 'sizes' => '50vw']]);
	expect(PictureCandidates::settings($config, ['formats' => ['WEBP', 'bogus'], 'sizes' => '10vw']))->toBe([
		'widths'  => [300, 600],
		'formats' => ['webp'],
		'sizes'   => '10vw',
	]);
});
