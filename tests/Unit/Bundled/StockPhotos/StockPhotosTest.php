<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use TotalCMS\Bundled\StockPhotos\DownloadAction;
use TotalCMS\Bundled\StockPhotos\PixabayProvider;
use TotalCMS\Bundled\StockPhotos\SearchAction;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;
use TotalCMS\Support\HttpResponse;
use TotalCMS\Support\PublicHttpClient;
use TotalCMS\Support\RemoteFileDownloader;

$dir = dirname(__DIR__, 4) . '/resources/extensions/totalcms/stock-photos';
require_once $dir . '/PhotoProvider.php';
require_once $dir . '/PixabayProvider.php';
require_once $dir . '/SearchAction.php';
require_once $dir . '/DownloadAction.php';

// The Pixabay provider and the extension's two admin routes, offline: the API
// answers from a recorded response and the image proxy from the fixture JPEG.

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

	$response = (new SearchAction($provider, new JsonRenderer(), 'vertical'))($request, $psr17->createResponse());
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
	$action     = new DownloadAction(new PixabayProvider(pixabayHttp(new HttpResponse(200, '')), stockPhotosCache(), 'k'), $downloader);
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
