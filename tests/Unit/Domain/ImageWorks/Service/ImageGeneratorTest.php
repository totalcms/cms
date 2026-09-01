<?php

declare(strict_types=1);

use Nyholm\Psr7\Stream;
use Psr\Log\NullLogger;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\WatermarkFactory;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

// ImageGenerator is what serves every /imageworks/ URL. These tests cover the
// part that decides WHICH file gets served — the gallery selection tokens
// (first/last/random/featured/by-name) and the property-type guards — rather
// than the Glide pipeline that resizes it. Picking the wrong image is a
// user-visible bug that no amount of correct resizing fixes.
//
// Which image was chosen is observed through the path handed to
// GlideFactory::originalImage(), since that path is built from the selected
// image's name.

function imageWorksImage(string $name, bool $featured = false): ImageData
{
	return new ImageData(['name' => $name, 'featured' => $featured, 'width' => 800, 'mime' => 'image/jpeg']);
}

/**
 * @param mixed  $property what PropertyFetcher returns
 * @param string $captured receives the path passed to originalImage()
 */
function imageWorksGenerator(mixed $property, ?string &$captured = null): ImageGenerator
{
	$fetcher = test()->createMock(PropertyFetcher::class);
	$fetcher->method('fetchProperty')->willReturn($property);

	$glide = test()->createMock(GlideFactory::class);
	$glide->method('originalImage')->willReturnCallback(
		function (string $path) use (&$captured): array {
			$captured = $path;

			return [
				// A real JPEG from the fixtures, so Content-Type and the body
				// are what a browser would actually receive.
				'stream'   => Stream::create((string)file_get_contents(__DIR__ . '/../../../../test-data/test-image.jpg')),
				'mimeType' => 'image/jpeg',
			];
		}
	);

	$filesystem = test()->createMock(StorageAdapterInterface::class);
	// generateCacheHeaders() catches this and falls back to time(); we are not
	// testing mtime propagation here.
	$filesystem->method('flysystem')->willThrowException(new RuntimeException('no flysystem in test'));
	$filesystem->method('fileSize')->willReturn(1234);

	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->presets = [];

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

	return new ImageGenerator(
		$filesystem,
		$fetcher,
		test()->createMock(PropertyMetaResolver::class),
		$glide,
		test()->createMock(WatermarkFactory::class),
		$config,
		$loggerFactory,
	);
}

describe('ImageGenerator property guards', function (): void {
	it('refuses to serve a property that is not an image', function (): void {
		$generator = imageWorksGenerator(new GalleryData([]));

		expect(fn () => $generator->generateImage('blog', 'post-1', 'title', []))
			->toThrow(UnexpectedValueException::class, 'Invalid image property found');
	});

	it('refuses a gallery request against a plain image property', function (): void {
		$generator = imageWorksGenerator(imageWorksImage('photo.jpg'));

		expect(fn () => $generator->generateGalleryImage('blog', 'post-1', 'gallery', 'first', []))
			->toThrow(UnexpectedValueException::class, 'Invalid gallery property found');
	});

	it('refuses an empty gallery rather than serving nothing', function (): void {
		$generator = imageWorksGenerator(new GalleryData([]));

		expect(fn () => $generator->generateGalleryImage('blog', 'post-1', 'gallery', 'first', []))
			->toThrow(UnexpectedValueException::class, 'Gallery has no images');
	});

	it('reports a missing gallery image by name', function (): void {
		$generator = imageWorksGenerator(new GalleryData([imageWorksImage('one.jpg')]));

		expect(fn () => $generator->generateGalleryImage('blog', 'post-1', 'gallery', 'nope', []))
			->toThrow(UnexpectedValueException::class, 'Gallery Image not found');
	});
});

describe('ImageGenerator gallery selection', function (): void {
	$gallery = fn (): GalleryData => new GalleryData([
		imageWorksImage('alpha.jpg'),
		imageWorksImage('beta.jpg', featured: true),
		imageWorksImage('gamma.jpg'),
	]);

	it('serves the first image for "first"', function () use ($gallery): void {
		$path      = null;
		$generator = imageWorksGenerator($gallery(), $path);

		$generator->generateGalleryImage('blog', 'post-1', 'gallery', 'first', []);

		expect($path)->toContain('alpha.jpg');
	});

	it('serves the last image for "last"', function () use ($gallery): void {
		$path      = null;
		$generator = imageWorksGenerator($gallery(), $path);

		$generator->generateGalleryImage('blog', 'post-1', 'gallery', 'last', []);

		expect($path)->toContain('gamma.jpg');
	});

	it('serves the flagged image for "featured"', function () use ($gallery): void {
		$path      = null;
		$generator = imageWorksGenerator($gallery(), $path);

		$generator->generateGalleryImage('blog', 'post-1', 'gallery', 'featured', []);

		expect($path)->toContain('beta.jpg');
	});

	it('falls back to some image when "featured" matches nothing', function (): void {
		// Documented fallback: a gallery with nothing flagged still serves an
		// image rather than 404ing.
		$path      = null;
		$generator = imageWorksGenerator(
			new GalleryData([imageWorksImage('one.jpg'), imageWorksImage('two.jpg')]),
			$path
		);

		$generator->generateGalleryImage('blog', 'post-1', 'gallery', 'featured', []);

		expect($path)->toMatch('/(one|two)\.jpg/');
	});

	it('matches a name without its extension', function () use ($gallery): void {
		// URLs address gallery images by basename: /gallery/beta.jpg asks for
		// "beta".
		$path      = null;
		$generator = imageWorksGenerator($gallery(), $path);

		$generator->generateGalleryImage('blog', 'post-1', 'gallery', 'beta', []);

		expect($path)->toContain('beta.jpg');
	});

	it('serves one of the gallery images for "random"', function () use ($gallery): void {
		$path      = null;
		$generator = imageWorksGenerator($gallery(), $path);

		$generator->generateGalleryImage('blog', 'post-1', 'gallery', 'random', []);

		expect($path)->toMatch('/(alpha|beta|gamma)\.jpg/');
	});

	it('handles a single-image gallery, where the random index has no range', function (): void {
		$path      = null;
		$generator = imageWorksGenerator(new GalleryData([imageWorksImage('only.jpg')]), $path);

		$generator->generateGalleryImage('blog', 'post-1', 'gallery', 'random', []);

		expect($path)->toContain('only.jpg');
	});
});

describe('ImageGenerator original image response', function (): void {
	it('serves the file with cache headers when no transform is requested', function (): void {
		$path      = null;
		$generator = imageWorksGenerator(imageWorksImage('photo.jpg'), $path);

		$response = $generator->generateImage('blog', 'post-1', 'image', []);

		expect($response->getStatusCode())->toBe(200);
		expect($response->getHeaderLine('Content-Type'))->toBe('image/jpeg');
		expect($response->getHeaderLine('Content-Length'))->toBe('1234');
		expect($response->getHeaderLine('ETag'))->not->toBe('');
		expect($response->getHeaderLine('Cache-Control'))->toContain('max-age');
		expect($path)->toContain('photo.jpg');
	});

	it('returns a real image body', function (): void {
		$generator = imageWorksGenerator(imageWorksImage('photo.jpg'));

		$body = (string)$generator->generateImage('blog', 'post-1', 'image', [])->getBody();

		expect(strlen($body))->toBeGreaterThan(0);
		// JPEG magic bytes — proof the fixture streamed through untouched.
		expect(substr($body, 0, 2))->toBe("\xFF\xD8");
	});

	it('builds the path from collection, id and property', function (): void {
		$path      = null;
		$generator = imageWorksGenerator(imageWorksImage('photo.jpg'), $path);

		$generator->generateImage('news', 'story-7', 'hero', []);

		expect($path)->toContain('news');
		expect($path)->toContain('story-7');
		expect($path)->toContain('hero');
	});
});
