<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use TotalCMS\Domain\ImageWorks\Exception\RenderBusyException;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;
use TotalCMS\Domain\ImageWorks\Service\ImageParamValidator;
use TotalCMS\Domain\ImageWorks\Service\ImageRenderParams;
use TotalCMS\Domain\ImageWorks\Service\ImageResponder;
use TotalCMS\Domain\ImageWorks\Service\ImageSourceResolver;
use TotalCMS\Domain\ImageWorks\Service\RenderSlots;
use TotalCMS\Domain\ImageWorks\Service\WatermarkFactory;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

// The generator as the public /imageworks/ routes drive it, over real Glide
// and a real filesystem: what lands in the cache folder is the thing under
// test. A cache file per distinct request is exactly what let one scanner
// pin a server, so these count files, not just pixels.

const PUBLIC_ROUTE_SOURCE_W = 2224;
const PUBLIC_ROUTE_SOURCE_H = 1245;

function publicRouteGenerator(string $root, ?RenderSlots $slots = null, array $imageworks = []): ImageGenerator
{
	$storage = new StorageFilesystemAdapter(new Filesystem(new LocalFilesystemAdapter($root)));

	$config             = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->imageworks = $imageworks + [
		'sizeStep' => 50,
		'presets'  => [],
		'defaults' => [],
		'picture'  => ['widths' => [480, 768, 1024, 1440, 1920]],
	];
	$config->presets = [];

	$fetcher = test()->createMock(PropertyFetcher::class);
	$fetcher->method('fetchProperty')->willReturn(new ImageData([
		'name'   => 'photo.jpg',
		'width'  => PUBLIC_ROUTE_SOURCE_W,
		'height' => PUBLIC_ROUTE_SOURCE_H,
		'mime'   => 'image/jpeg',
	]));

	$meta = test()->createMock(PropertyMetaResolver::class);
	$meta->method('resolveSettings')->willReturn([]);

	$loggerFactory = test()->createMock(LoggerFactory::class);
	$loggerFactory->method('channelLogger')->willReturn(new NullLogger());

	$watermarks = test()->createMock(WatermarkFactory::class);
	$watermarks->method('prepare')->willReturn([]);

	$glide = new GlideFactory($storage, $config);

	return new ImageGenerator(
		new ImageSourceResolver($fetcher),
		new ImageRenderParams($meta, $config, editionsFor()),
		new ImageResponder($storage, $glide),
		$glide,
		$watermarks,
		$config,
		$slots,
	);
}

/** What the route does with a query string: validate, add fm, render. */
function publicRouteFetch(ImageGenerator $generator, array $query, bool $limitRenders = true): array
{
	$params       = ImageParamValidator::validate($query);
	$params['fm'] = 'jpg';
	$request      = (new ServerRequestFactory())->createServerRequest('GET', '/imageworks/blog/post-1/image.jpg');
	$body         = (string)$generator->forPublicRoute($limitRenders)->generateImage('blog', 'post-1', 'image', $params, $request)->getBody();
	$info         = getimagesizefromstring($body);
	expect($info)->not->toBeFalse();

	return ['width' => $info[0], 'height' => $info[1]];
}

function publicRouteCacheFiles(string $root): int
{
	return count(glob($root . '/blog/post-1/image/' . GlideFactory::CACHEDIR . '/*') ?: []);
}

beforeEach(function (): void {
	$this->root = tcmsTestTempDir('tcms-imageworks-route');
	mkdir($this->root . '/blog/post-1/image', 0700, true);
	copy(dirname(__DIR__, 2) . '/test-data/test-image.jpg', $this->root . '/blog/post-1/image/photo.jpg');
});

afterEach(function (): void {
	exec('rm -rf ' . escapeshellarg($this->root));
});

describe('ImageWorks public route', function (): void {
	it('rounds a width up to the ladder', function (): void {
		$out = publicRouteFetch(publicRouteGenerator($this->root), ['w' => '333']);

		expect($out['width'])->toBe(350);
	});

	it('serves one cache file for every width that rounds to the same rung', function (): void {
		$generator = publicRouteGenerator($this->root);

		foreach (['301', '0320', '333', '349', '350'] as $width) {
			publicRouteFetch($generator, ['w' => $width, 'cache' => bin2hex(random_bytes(4))]);
		}

		expect(publicRouteCacheFiles($this->root))->toBe(1);
	});

	it('keeps a picture width exact', function (): void {
		expect(publicRouteFetch(publicRouteGenerator($this->root), ['w' => '768'])['width'])->toBe(768);
	});

	it('keeps exact sizes when sizeStep is 0', function (): void {
		$out = publicRouteFetch(publicRouteGenerator($this->root, null, ['sizeStep' => 0]), ['w' => '333']);

		// Glide's contain fit rounds 333 wide to 332; what matters is that it did not become 350.
		expect($out['width'])->toBeGreaterThanOrEqual(332)->toBeLessThanOrEqual(333);
	});

	it('keeps exact sizes for in-process callers', function (): void {
		$body = (string)publicRouteGenerator($this->root)->generateImage('blog', 'post-1', 'image', ['w' => 333, 'fm' => 'jpg'])->getBody();

		expect(getimagesizefromstring($body)[0])->toBeGreaterThanOrEqual(332)->toBeLessThanOrEqual(333);
	});

	it('turns a miss away with busy when every render slot is held', function (): void {
		$dir = $this->root . '/slots';
		mkdir($dir);
		$held = fopen($dir . '/slot-0.lock', 'c');
		flock($held, LOCK_EX);

		$generator = publicRouteGenerator($this->root, new RenderSlots($dir, 1, 0));

		expect(fn () => publicRouteFetch($generator, ['w' => '400']))->toThrow(RenderBusyException::class);
		expect(publicRouteCacheFiles($this->root))->toBe(0);

		fclose($held);
	});

	it('serves a cached file without waiting for a slot', function (): void {
		$dir = $this->root . '/slots';
		mkdir($dir);

		$generator = publicRouteGenerator($this->root, new RenderSlots($dir, 1, 0));
		publicRouteFetch($generator, ['w' => '400']);

		$held = fopen($dir . '/slot-0.lock', 'c');
		flock($held, LOCK_EX);

		expect(publicRouteFetch($generator, ['w' => '400'])['width'])->toBe(400);

		fclose($held);
	});

	it('lets the warmer render without a slot', function (): void {
		$dir = $this->root . '/slots';
		mkdir($dir);
		$held = fopen($dir . '/slot-0.lock', 'c');
		flock($held, LOCK_EX);

		$generator = publicRouteGenerator($this->root, new RenderSlots($dir, 1, 0));

		expect(publicRouteFetch($generator, ['w' => '400'], limitRenders: false)['width'])->toBe(400);

		fclose($held);
	});
});
