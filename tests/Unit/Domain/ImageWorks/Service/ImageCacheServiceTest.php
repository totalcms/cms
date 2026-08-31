<?php

declare(strict_types=1);

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Support\Config;

// ImageCacheService deletes directories under the data dir, which is where the
// customer's images live. It runs against a real temp filesystem here rather
// than a mocked one: the property worth proving is that it removes .cache and
// nothing else, and a mocked filesystem would only prove the calls it was told
// to expect.

/**
 * Lay out a data dir:
 *   {collection}/{object}/{property}/photo.jpg
 *   {collection}/{object}/{property}/.cache/{n}.jpg
 *
 * @param array<string,int> $objects object id => number of cached derivatives
 */
function imageCacheTree(string $datadir, string $collection, array $objects): void
{
	foreach ($objects as $object => $cachedFiles) {
		$dir = "$datadir/$collection/$object/image";
		mkdir($dir, 0700, true);
		file_put_contents("$dir/photo.jpg", 'original-bytes');

		if ($cachedFiles > 0) {
			mkdir("$dir/.cache", 0700, true);
			for ($i = 0; $i < $cachedFiles; $i++) {
				file_put_contents("$dir/.cache/derivative-$i.jpg", str_repeat('x', 1024));
			}
		}
	}
}

/** @param array<string,mixed> $cached what getComputedData() returns */
function imageCacheService(string $datadir, mixed $cached = null, ?CacheManager &$cache = null): ImageCacheService
{
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = $datadir;

	$cache = test()->createMock(CacheManager::class);
	$cache->method('getComputedData')->willReturn($cached);
	$cache->method('storeComputedData')->willReturn(true);
	$cache->method('clearComputedData')->willReturn(true);

	return new ImageCacheService($config, $cache);
}

beforeEach(function (): void {
	$this->datadir = sys_get_temp_dir() . '/tcms-imgcache-' . uniqid('', true);
	mkdir($this->datadir, 0700, true);
});

afterEach(function (): void {
	exec('rm -rf ' . escapeshellarg($this->datadir));
});

describe('ImageCacheService::clearCollectionImageCache', function (): void {
	it('removes .cache directories and leaves the originals untouched', function (): void {
		// The assertion that matters. Everything else in this class is
		// reporting; this is the part that deletes customer data.
		imageCacheTree($this->datadir, 'blog', ['post-1' => 3, 'post-2' => 2]);

		imageCacheService($this->datadir)->clearCollectionImageCache('blog');

		expect(is_dir($this->datadir . '/blog/post-1/image/.cache'))->toBeFalse();
		expect(is_dir($this->datadir . '/blog/post-2/image/.cache'))->toBeFalse();
		expect(file_exists($this->datadir . '/blog/post-1/image/photo.jpg'))->toBeTrue();
		expect(file_exists($this->datadir . '/blog/post-2/image/photo.jpg'))->toBeTrue();
		expect(file_get_contents($this->datadir . '/blog/post-1/image/photo.jpg'))->toBe('original-bytes');
	});

	it('leaves other collections alone', function (): void {
		imageCacheTree($this->datadir, 'blog', ['post-1' => 2]);
		imageCacheTree($this->datadir, 'gallery', ['shoot-1' => 2]);

		imageCacheService($this->datadir)->clearCollectionImageCache('blog');

		expect(is_dir($this->datadir . '/blog/post-1/image/.cache'))->toBeFalse();
		expect(is_dir($this->datadir . '/gallery/shoot-1/image/.cache'))->toBeTrue();
	});

	it('succeeds on a collection that has nothing cached', function (): void {
		imageCacheTree($this->datadir, 'blog', ['post-1' => 0]);

		expect(imageCacheService($this->datadir)->clearCollectionImageCache('blog'))->toBeTrue();
		expect(file_exists($this->datadir . '/blog/post-1/image/photo.jpg'))->toBeTrue();
	});

	it('refuses a collection that does not exist', function (): void {
		expect(fn () => imageCacheService($this->datadir)->clearCollectionImageCache('nope'))
			->toThrow(RuntimeException::class, 'Collection directory does not exist');
	});

	it('invalidates the cached stats, which are now wrong', function (): void {
		imageCacheTree($this->datadir, 'blog', ['post-1' => 1]);

		$cache   = null;
		$service = imageCacheService($this->datadir, null, $cache);
		$cache->expects(test()->atLeastOnce())->method('clearComputedData')->with('image_cache_stats');

		$service->clearCollectionImageCache('blog');
	});
});

describe('ImageCacheService::getCollectionImageCacheStats', function (): void {
	it('counts cache directories, files and bytes', function (): void {
		imageCacheTree($this->datadir, 'blog', ['post-1' => 3, 'post-2' => 2]);

		$stats = imageCacheService($this->datadir)->getCollectionImageCacheStats('blog');

		expect($stats['exists'])->toBeTrue();
		expect($stats['collection'])->toBe('blog');
		expect($stats['cache_directories'])->toBe(2);
		expect($stats['cached_files'])->toBe(5);
		expect($stats['total_size_bytes'])->toBe(5 * 1024);
		expect($stats['total_size_mb'])->toBe(round(5 * 1024 / 1024 / 1024, 2));
	});

	it('reports a missing collection without throwing', function (): void {
		// The admin screen asks for stats on whatever it lists; a collection
		// that has gone away must not break the page.
		$stats = imageCacheService($this->datadir)->getCollectionImageCacheStats('nope');

		expect($stats['exists'])->toBeFalse();
		expect($stats['cached_files'])->toBe(0);
		expect($stats['total_size_bytes'])->toBe(0);
	});

	it('reports zeros for a collection with no cache', function (): void {
		imageCacheTree($this->datadir, 'blog', ['post-1' => 0]);

		$stats = imageCacheService($this->datadir)->getCollectionImageCacheStats('blog');

		expect($stats['exists'])->toBeTrue();
		expect($stats['cache_directories'])->toBe(0);
		expect($stats['cached_files'])->toBe(0);
	});
});

describe('ImageCacheService::getAllCollectionImageCacheStats', function (): void {
	it('returns the cached figures without touching disk', function (): void {
		$precomputed = [['collection' => 'blog', 'cached_files' => 99]];

		$stats = imageCacheService($this->datadir, $precomputed)->getAllCollectionImageCacheStats();

		expect($stats)->toBe($precomputed);
	});

	it('recomputes and stores when the cache is empty', function (): void {
		imageCacheTree($this->datadir, 'blog', ['post-1' => 2]);

		$cache   = null;
		$service = imageCacheService($this->datadir, null, $cache);
		$cache->expects(test()->once())->method('storeComputedData')
			->with('image_cache_stats', test()->anything(), 3 * 60 * 60)
			->willReturn(true);

		$stats = $service->getAllCollectionImageCacheStats();

		expect($stats)->toHaveCount(1);
		expect($stats[0]['collection'])->toBe('blog');
	});

	it('bypasses a populated cache when asked to refresh', function (): void {
		imageCacheTree($this->datadir, 'blog', ['post-1' => 2]);
		$stale = [['collection' => 'stale', 'cached_files' => 1]];

		$stats = imageCacheService($this->datadir, $stale)->getAllCollectionImageCacheStats(forceRefresh: true);

		expect($stats[0]['collection'])->toBe('blog');
	});

	it('lists only collections that actually have cached files', function (): void {
		// The admin screen is a list of things worth clearing; a collection with
		// nothing cached is noise.
		imageCacheTree($this->datadir, 'blog', ['post-1' => 2]);
		imageCacheTree($this->datadir, 'pages', ['home' => 0]);

		$stats = imageCacheService($this->datadir)->getAllCollectionImageCacheStats();

		expect(array_column($stats, 'collection'))->toBe(['blog']);
	});

	it('sorts collections by name', function (): void {
		imageCacheTree($this->datadir, 'zebra', ['a' => 1]);
		imageCacheTree($this->datadir, 'alpha', ['a' => 1]);
		imageCacheTree($this->datadir, 'mango', ['a' => 1]);

		$stats = imageCacheService($this->datadir)->getAllCollectionImageCacheStats();

		expect(array_column($stats, 'collection'))->toBe(['alpha', 'mango', 'zebra']);
	});
});

describe('ImageCacheService::clearAllCollectionImageCaches', function (): void {
	it('clears every collection and counts what it processed', function (): void {
		imageCacheTree($this->datadir, 'blog', ['post-1' => 2]);
		imageCacheTree($this->datadir, 'gallery', ['shoot-1' => 3]);

		$result = imageCacheService($this->datadir)->clearAllCollectionImageCaches();

		expect($result['collections_processed'])->toBe(2);
		expect($result['errors'])->toBe([]);
		expect(is_dir($this->datadir . '/blog/post-1/image/.cache'))->toBeFalse();
		expect(is_dir($this->datadir . '/gallery/shoot-1/image/.cache'))->toBeFalse();
		expect(file_exists($this->datadir . '/blog/post-1/image/photo.jpg'))->toBeTrue();
	});

	it('always reports zero cache directories cleared', function (): void {
		// Documents a reporting gap rather than endorsing it: the summary
		// declares a `cache_directories_cleared` key that nothing ever
		// increments, because clearCollectionImageCache() returns a bool rather
		// than a count. The number is always 0 no matter how much was removed.
		imageCacheTree($this->datadir, 'blog', ['post-1' => 5, 'post-2' => 5]);

		$result = imageCacheService($this->datadir)->clearAllCollectionImageCaches();

		expect($result['collections_processed'])->toBe(1);
		expect($result['cache_directories_cleared'])->toBe(0);
	});

	it('refuses to run when the data directory is missing', function (): void {
		$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->datadir = $this->datadir . '/gone';
		$service         = new ImageCacheService($config, test()->createMock(CacheManager::class));

		expect(fn () => $service->clearAllCollectionImageCaches())
			->toThrow(RuntimeException::class, 'Data directory does not exist');
	});
});
