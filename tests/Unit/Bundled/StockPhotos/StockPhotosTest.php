<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Bundled\StockPhotos\DownloadAction;
use TotalCMS\Bundled\StockPhotos\Extension;
use TotalCMS\Bundled\StockPhotos\GalleryProvider;
use TotalCMS\Bundled\StockPhotos\LibraryAction;
use TotalCMS\Bundled\StockPhotos\PhotoSources;
use TotalCMS\Bundled\StockPhotos\PixabayProvider;
use TotalCMS\Bundled\StockPhotos\SearchAction;
use TotalCMS\Bundled\StockPhotos\SourcesAction;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;
use TotalCMS\Support\PublicHttpClient;
use TotalCMS\Support\RemoteFileDownloader;

$dir = dirname(__DIR__, 4) . '/resources/extensions/totalcms/stock-photos';
require_once $dir . '/Extension.php';

// The Pixabay and gallery sources and the extension's admin routes, offline:
// the API answers from a recorded response, the image proxy from the fixture
// JPEG, and galleries from a stubbed PropertyFetcher.

function pixabayHit(array $overrides = []): array
{
	return $overrides + [
		'id'            => 195893,
		'pageURL'       => 'https://pixabay.com/photos/blossom-bloom-flower-195893/',
		'tags'          => 'blossom, bloom, flower',
		'previewURL'    => 'https://cdn.pixabay.com/photo/2013/10/15/09/12/flower-195893_150.jpg',
		'webformatURL'  => 'https://pixabay.com/get/35bbf209e13e39d2_640.jpg',
		'largeImageURL' => 'https://pixabay.com/get/ed6a99fd0a76647_1280.jpg',
		'imageWidth'    => 4000,
		'imageHeight'   => 2250,
		'user_id'       => 48777,
		'user'          => 'Josch13',
	];
}

/**
 * The sources, as an editor who may (or may not) read the gallery collection
 * sees them.
 *
 * @param list<\TotalCMS\Bundled\StockPhotos\PhotoProvider> $providers
 */
function stockPhotoSources(array $providers, bool $canReadGalleries = true): PhotoSources
{
	$config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->auth = ['enable' => true];

	$access = test()->createMock(AccessManager::class);
	$access->method('userData')->willReturn(['id' => 'editor@example.com']);
	$control = test()->createMock(AccessControlService::class);
	$control->method('canAccessCollection')->willReturn($canReadGalleries);

	return new PhotoSources($providers, $config, $access, $control);
}

/** A gallery source whose gallery holds $images. */
function libraryGallery(array $images, string $id = 'restaurant'): GalleryProvider
{
	$fetcher = test()->createMock(PropertyFetcher::class);
	$fetcher->method('fetchProperty')->willReturn(new GalleryData($images));

	return new GalleryProvider($id, $fetcher, '');
}

function libraryImage(string $name, array $overrides = []): array
{
	return $overrides + [
		'name'       => $name,
		'alt'        => 'A plate of food',
		'width'      => 2400,
		'height'     => 1600,
		'mime'       => 'image/jpeg',
		'tags'       => [],
		'link'       => '',
		'focalpoint' => ['x' => 50, 'y' => 50],
		'credit'     => [],
		'uploadDate' => '2026-10-30T00:00:00+00:00',
	];
}

/** A CacheManager kept in memory, skipping the parent's dependencies. */
function stockPhotosCache(): CacheManager
{
	return new class extends CacheManager {
		/** @var array<string,mixed> */
		public array $store = [];

		public function __construct()
		{
		}

		public function getData(string $key): mixed
		{
			return $this->store[$key] ?? null;
		}

		public function storeData(string $key, mixed $data, int $ttl = self::DEFAULT_TTL): bool
		{
			$this->store[$key] = $data;

			return true;
		}
	};
}

/** An HTTP client that records each URL and answers with $reply. */
function pixabayHttp(HttpResponse $reply): HttpClientInterface
{
	return new class($reply) implements HttpClientInterface {
		/** @var list<string> */
		public array $urls = [];

		public function __construct(private HttpResponse $reply)
		{
		}

		public function request(string $method, string $url, array $options = []): HttpResponse
		{
			$this->urls[] = $url;

			return $this->reply;
		}
	};
}

test('a Pixabay hit becomes a photo with alt from its tags and a full credit', function (): void {
	$http     = pixabayHttp(new HttpResponse(200, (string)json_encode(['totalHits' => 1, 'hits' => [pixabayHit()]])));
	$provider = new PixabayProvider($http, stockPhotosCache(), 'secret-key');

	$result = $provider->search('flower', 1, 'horizontal');

	expect($result['total'])->toBe(1)
		->and($result['photos'][0])->toBe([
			'id'       => '195893',
			'thumb'    => 'https://cdn.pixabay.com/photo/2013/10/15/09/12/flower-195893_150.jpg',
			'preview'  => 'https://pixabay.com/get/35bbf209e13e39d2_640.jpg',
			'download' => 'https://pixabay.com/get/ed6a99fd0a76647_1280.jpg',
			'filename' => 'blossom-bloom-flower-pixabay-195893',
			'width'    => 4000,
			'height'   => 2250,
			'alt'      => 'Blossom, bloom, flower',
			'credit'   => [
				'author'    => 'Josch13',
				'authorUrl' => 'https://pixabay.com/users/Josch13-48777/',
				'source'    => 'Pixabay',
				'sourceUrl' => 'https://pixabay.com/photos/blossom-bloom-flower-195893/',
				'license'   => 'Pixabay Content License',
			],
		]);

	parse_str((string)parse_url($http->urls[0], PHP_URL_QUERY), $query);
	expect($query)->toMatchArray(['key' => 'secret-key', 'q' => 'flower', 'orientation' => 'horizontal', 'safesearch' => 'true', 'image_type' => 'photo']);
});

test('a key with full API access downloads the original, else the biggest it has', function (): void {
	$hits = [
		pixabayHit(['id' => 1, 'fullHDURL' => 'https://pixabay.com/get/a_1920.jpg', 'imageURL' => 'https://pixabay.com/get/a_original.jpg']),
		pixabayHit(['id' => 2, 'fullHDURL' => 'https://pixabay.com/get/b_1920.jpg']),
		pixabayHit(['id' => 3]),
	];
	$http   = pixabayHttp(new HttpResponse(200, (string)json_encode(['totalHits' => 3, 'hits' => $hits])));
	$photos = (new PixabayProvider($http, stockPhotosCache(), 'k'))->search('flower', 1, 'all')['photos'];

	expect(array_column($photos, 'download'))->toBe([
		'https://pixabay.com/get/a_original.jpg',
		'https://pixabay.com/get/b_1920.jpg',
		'https://pixabay.com/get/ed6a99fd0a76647_1280.jpg',
	]);
});

test('the same search is answered from the cache, as Pixabay requires', function (): void {
	$http     = pixabayHttp(new HttpResponse(200, (string)json_encode(['totalHits' => 1, 'hits' => [pixabayHit()]])));
	$cache    = stockPhotosCache();
	$provider = new PixabayProvider($http, $cache, 'secret-key');

	$provider->search('flower', 1, 'all');
	$again = $provider->search('flower', 1, 'all');

	expect($http->urls)->toHaveCount(1)
		->and($again['photos'][0]['id'])->toBe('195893');

	// The key is not part of what is cached.
	expect((string)json_encode($cache->store))->not->toContain('secret-key');
});

test('no key or a refused request is a clear error, and a missing key sends nothing', function (): void {
	$http = pixabayHttp(new HttpResponse(400, '[ERROR 400] "key" is invalid.'));

	expect(fn () => (new PixabayProvider($http, stockPhotosCache(), ''))->search('flower', 1, 'all'))
		->toThrow(RuntimeException::class, 'Add your Pixabay API key');
	expect($http->urls)->toBe([]);

	expect(fn () => (new PixabayProvider($http, stockPhotosCache(), 'bad'))->search('flower', 1, 'all'))
		->toThrow(RuntimeException::class, 'Pixabay refused the search');
});

test('only https Pixabay hosts count as Pixabay images', function (): void {
	$provider = new PixabayProvider(pixabayHttp(new HttpResponse(200, '')), stockPhotosCache(), 'k');

	expect($provider->servesImage('https://pixabay.com/get/a_1280.jpg'))->toBeTrue()
		->and($provider->servesImage('https://cdn.pixabay.com/photo/a.jpg'))->toBeTrue()
		->and($provider->servesImage('http://pixabay.com/get/a.jpg'))->toBeFalse()
		->and($provider->servesImage('https://pixabay.com.evil.example/a.jpg'))->toBeFalse()
		->and($provider->servesImage('https://evilpixabay.com/a.jpg'))->toBeFalse()
		->and($provider->servesImage('https://169.254.169.254/latest'))->toBeFalse();
});

test('the search route points each download at the proxy and keeps the key server-side', function (): void {
	$http     = pixabayHttp(new HttpResponse(200, (string)json_encode(['totalHits' => 1, 'hits' => [pixabayHit()]])));
	$provider = new PixabayProvider($http, stockPhotosCache(), 'secret-key');
	$psr17    = new Psr17Factory();
	$request  = $psr17->createServerRequest('GET', '/admin/ext/totalcms/stock-photos/search?q=flower')->withQueryParams(['q' => 'flower', 'orientation' => 'sideways']);

	$response = (new SearchAction(stockPhotoSources([$provider]), new JsonRenderer(), 'vertical'))($request, $psr17->createResponse());
	$body     = json_decode((string)$response->getBody(), true);

	expect($response->getStatusCode())->toBe(200)
		->and($body['orientation'])->toBe('vertical')
		->and($body['provider']['label'])->toBe('Pixabay')
		->and($body['provider']['license'])->toBe('https://pixabay.com/service/license-summary/')
		->and($body['photos'][0]['download'])->toBe('/admin/ext/totalcms/stock-photos/download?url=' . rawurlencode('https://pixabay.com/get/ed6a99fd0a76647_1280.jpg'))
		->and((string)$response->getBody())->not->toContain('secret-key');
});

test('the download proxy returns a Pixabay image and refuses any other host', function (): void {
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->tmpdir  = sys_get_temp_dir() . '/stock-photos-' . uniqid();
	$config->maxDownloadSize = 10;
	$fetched         = [];
	$public          = new PublicHttpClient(
		static fn (string $host): array => ['93.184.216.34'],
		static function (string $url) use (&$fetched): array {
			$fetched[] = $url;

			return ['status' => 200, 'location' => '', 'body' => (string)file_get_contents(dirname(__DIR__, 3) . '/test-data/test-image.jpg')];
		},
	);
	$downloader = new RemoteFileDownloader(pixabayHttp(new HttpResponse(200, '')), $config, $public);
	$action     = new DownloadAction(stockPhotoSources([new PixabayProvider(pixabayHttp(new HttpResponse(200, '')), stockPhotosCache(), 'k')]), $downloader);
	$psr17      = new Psr17Factory();
	$get        = fn (string $url) => $action($psr17->createServerRequest('GET', '/download')->withQueryParams(['url' => $url]), $psr17->createResponse());

	$image = $get('https://pixabay.com/get/ed6a99fd0a76647_1280.jpg');
	expect($image->getStatusCode())->toBe(200)
		->and($image->getHeaderLine('Content-Type'))->toBe('image/jpeg')
		->and(strlen((string)$image->getBody()))->toBeGreaterThan(1000);

	expect($get('https://example.com/a.jpg')->getStatusCode())->toBe(400)
		->and($get('http://127.0.0.1/a.jpg')->getStatusCode())->toBe(400)
		->and($fetched)->toHaveCount(1);
});

test('the galleries setting is read as a list, from either shape, without blanks or repeats', function (): void {
	expect(Extension::galleryIds(['restaurant', ' team ', '', 'restaurant']))->toBe(['restaurant', 'team'])
		->and(Extension::galleryIds('restaurant, team,,'))->toBe(['restaurant', 'team'])
		->and(Extension::galleryIds('["team","restaurant"]'))->toBe(['team', 'restaurant'])
		->and(Extension::galleryIds(null))->toBe([]);
});

test('a gallery lists every photo on an empty search and filters by words and shape', function (): void {
	$gallery = libraryGallery([
		libraryImage('burrata.jpg', ['alt' => 'Burrata with basil', 'tags' => ['starter'], 'credit' => ['author' => 'Nadin Sh']]),
		libraryImage('scallops.jpg', ['alt' => 'Seared scallops', 'width' => 2400, 'height' => 3200, 'credit' => ['author' => 'Nadin Sh']]),
		libraryImage('dining-room.jpg', ['alt' => 'The dining room', 'tags' => ['interior']]),
	]);

	$names = fn (array $result): array => array_column($result['photos'], 'id');

	expect($gallery->browses())->toBeTrue()
		->and($names($gallery->search('', 1, 'all')))->toBe(['burrata.jpg', 'scallops.jpg', 'dining-room.jpg'])
		->and($names($gallery->search('nadin', 1, 'all')))->toBe(['burrata.jpg', 'scallops.jpg'])
		->and($names($gallery->search('STARTER basil', 1, 'all')))->toBe(['burrata.jpg'])
		->and($names($gallery->search('dining', 1, 'all')))->toBe(['dining-room.jpg'])
		->and($names($gallery->search('', 1, 'vertical')))->toBe(['scallops.jpg'])
		->and($names($gallery->search('', 1, 'horizontal')))->toBe(['burrata.jpg', 'dining-room.jpg'])
		->and($gallery->search('lobster', 1, 'all')['total'])->toBe(0);
});

test('a gallery photo carries its details and downloads its original from the library route', function (): void {
	$credit = ['author' => 'Nadin Sh', 'source' => 'Pexels', 'license' => 'Pexels License'];
	$photo  = libraryGallery([libraryImage('burrata.jpg', [
		'alt'        => 'Burrata with basil',
		'tags'       => ['starter', 'cheese'],
		'link'       => '/menu',
		'focalpoint' => ['x' => 30, 'y' => 60],
		'credit'     => $credit,
		'featured'   => true,
	])])->search('', 1, 'all')['photos'][0];

	expect($photo)->toMatchArray([
		'filename'   => 'burrata.jpg',
		'download'   => 'ext/totalcms/stock-photos/library?gallery=restaurant&name=burrata.jpg',
		'alt'        => 'Burrata with basil',
		'tags'       => ['starter', 'cheese'],
		'link'       => '/menu',
		'focalpoint' => ['x' => 30, 'y' => 60],
		'credit'     => $credit,
	])
		->and($photo)->not->toHaveKey('featured')
		->and($photo['thumb'])->toStartWith('/imageworks/gallery/restaurant/gallery/burrata.jpg');
});

test('a gallery pages its photos thirty at a time', function (): void {
	$images  = array_map(fn (int $i): array => libraryImage("photo-$i.jpg"), range(1, 35));
	$gallery = libraryGallery($images);

	expect($gallery->search('', 1, 'all')['photos'])->toHaveCount(30)
		->and($gallery->search('', 2, 'all')['photos'])->toHaveCount(5)
		->and($gallery->search('', 2, 'all')['total'])->toBe(35);
});

test('an editor who cannot read galleries sees only the stock service', function (): void {
	$pixabay = new PixabayProvider(pixabayHttp(new HttpResponse(200, '')), stockPhotosCache(), 'k');
	$gallery = libraryGallery([libraryImage('a.jpg')]);

	$ids = fn (PhotoSources $sources): array => array_map(fn ($provider): string => $provider->id(), $sources->available());

	expect($ids(stockPhotoSources([$pixabay, $gallery])))->toBe(['pixabay', 'gallery:restaurant'])
		->and($ids(stockPhotoSources([$pixabay, $gallery], canReadGalleries: false)))->toBe(['pixabay'])
		->and(stockPhotoSources([$gallery], canReadGalleries: false)->gallery('restaurant'))->toBeNull();
});

test('the sources route lists the tabs, and search lists a gallery without a query or the proxy', function (): void {
	$sources = stockPhotoSources([libraryGallery([libraryImage('burrata.jpg')])]);
	$psr17   = new Psr17Factory();

	$list = json_decode((string)(new SourcesAction($sources, new JsonRenderer()))($psr17->createServerRequest('GET', '/sources'), $psr17->createResponse())->getBody(), true);
	expect($list['sources'])->toBe([[
		'id'      => 'gallery:restaurant',
		'label'   => 'Restaurant',
		'url'     => 'collections/gallery/restaurant',
		'license' => '',
		'browses' => true,
	]]);

	$search  = new SearchAction($sources, new JsonRenderer());
	$request = $psr17->createServerRequest('GET', '/admin/ext/totalcms/stock-photos/search');
	$body    = json_decode((string)$search($request->withQueryParams(['source' => 'gallery:restaurant']), $psr17->createResponse())->getBody(), true);

	expect($body['total'])->toBe(1)
		->and($body['photos'][0]['download'])->toBe('ext/totalcms/stock-photos/library?gallery=restaurant&name=burrata.jpg');

	expect($search($request->withQueryParams(['source' => 'pixabay', 'q' => 'flower']), $psr17->createResponse())->getStatusCode())->toBe(404);
});

test('the library route serves a listed image byte for byte and nothing else', function (): void {
	$config          = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->datadir = sys_get_temp_dir() . '/stock-library-' . uniqid();
	$config->auth    = ['enable' => false];
	mkdir("{$config->datadir}/gallery/restaurant/gallery", 0755, true);
	$jpeg = (string)file_get_contents(dirname(__DIR__, 3) . '/test-data/test-image.jpg');
	file_put_contents("{$config->datadir}/gallery/restaurant/gallery/burrata.jpg", $jpeg);
	file_put_contents("{$config->datadir}/gallery/restaurant/gallery/unlisted.jpg", $jpeg);
	file_put_contents("{$config->datadir}/secret.txt", 'secret');

	$action = new LibraryAction(stockPhotoSources([libraryGallery([libraryImage('burrata.jpg')])]), $config);
	$psr17  = new Psr17Factory();
	$get    = fn (array $query) => $action($psr17->createServerRequest('GET', '/library')->withQueryParams($query), $psr17->createResponse());

	$image = $get(['gallery' => 'restaurant', 'name' => 'burrata.jpg']);
	expect($image->getStatusCode())->toBe(200)
		->and($image->getHeaderLine('Content-Type'))->toBe('image/jpeg')
		->and((string)$image->getBody())->toBe($jpeg);

	expect($get(['gallery' => 'restaurant', 'name' => 'unlisted.jpg'])->getStatusCode())->toBe(404)
		->and($get(['gallery' => 'restaurant', 'name' => '../../../secret.txt'])->getStatusCode())->toBe(404)
		->and($get(['gallery' => 'other', 'name' => 'burrata.jpg'])->getStatusCode())->toBe(404);

	array_map('unlink', glob("{$config->datadir}/gallery/restaurant/gallery/*") ?: []);
	@unlink("{$config->datadir}/secret.txt");
});
