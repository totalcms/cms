<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\AuthTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\CollectionTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;

final class TotalCMSTwigAdapterStaticTest extends TestCase
{
	public function testBuildImageworksAPIStaticMethod(): void
	{
		// Test the static method that doesn't require dependencies
		$api   = '/api';
		$id    = 'test-id';
		$image = [
			'name'       => 'test.jpg',
			'uploadDate' => '2024-01-15T12:30:45Z',
		];
		$imageworks = ['w' => 300, 'h' => 200];
		$options    = ['collection' => 'photos', 'property' => 'gallery'];

		$result = MediaTwigAdapter::buildImageworksAPI($api, $id, $image, $imageworks, $options);

		expect($result)->toContain('/api/imageworks/photos/test-id/gallery.jpg');
		expect($result)->toContain('w=300');
		expect($result)->toContain('h=200');
		expect($result)->toContain('cache=');
	}

	public function testBuildImageworksAPIWithEmptyImage(): void
	{
		$result = MediaTwigAdapter::buildImageworksAPI('/api', 'test-id', []);

		expect($result)->toBe('');
	}

	public function testBuildImageworksAPIWithImageMissingName(): void
	{
		$image = ['size' => 12345, 'uploadDate' => '2024-01-15T12:30:45Z'];

		$result = MediaTwigAdapter::buildImageworksAPI('/api', 'test-id', $image);

		expect($result)->toBe('');
	}

	public function testBuildImageworksAPIWithFormatConversion(): void
	{
		$image = [
			'name'       => 'test.png',
			'uploadDate' => '2024-01-15T12:30:45Z',
		];
		$imageworks = ['w' => 300, 'fm' => 'webp'];

		$result = MediaTwigAdapter::buildImageworksAPI('/api', 'test-id', $image, $imageworks);

		// Should convert to webp extension and remove fm parameter
		expect($result)->toContain('/api/imageworks/image/test-id/image.webp');
		expect($result)->not->toContain('fm=webp');
		expect($result)->toContain('w=300');
	}

	public function testBuildImageworksAPIDefaultsToJpgForUnknownExtension(): void
	{
		$image = [
			'name'       => 'test.unknown',
			'uploadDate' => '2024-01-15T12:30:45Z',
		];

		$result = MediaTwigAdapter::buildImageworksAPI('/api', 'test-id', $image);

		expect($result)->toContain('/api/imageworks/image/test-id/image.jpg');
	}

	public function testBuildImageworksAPIPreservesValidExtensions(): void
	{
		$validExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

		foreach ($validExtensions as $ext) {
			$image = [
				'name'       => "test.$ext",
				'uploadDate' => '2024-01-15T12:30:45Z',
			];

			$result = MediaTwigAdapter::buildImageworksAPI('/api', 'test-id', $image);

			expect($result)->toContain("/api/imageworks/image/test-id/image.$ext");
		}
	}

	public function testBuildImageworksAPIRemovesStacksPreviewParams(): void
	{
		$image = [
			'name'       => 'test.jpg',
			'uploadDate' => '2024-01-15T12:30:45Z',
		];
		$imageworks = [
			'w'       => 300,
			'datadir' => '/some/path',  // Should be removed
			'route'   => '/some/route',    // Should be removed
		];

		$result = MediaTwigAdapter::buildImageworksAPI('/api', 'test-id', $image, $imageworks);

		expect($result)->toContain('w=300');
		expect($result)->not->toContain('datadir');
		expect($result)->not->toContain('route');
	}

	public function testBuildImageworksAPICacheBustingFromUploadDate(): void
	{
		$image = [
			'name'       => 'test.jpg',
			'uploadDate' => '2024-01-15T12:30:45Z',
		];

		$result = MediaTwigAdapter::buildImageworksAPI('/api', 'test-id', $image);

		// Cache should be reversed uploadDate with non-word characters removed
		$expectedCache = strrev((string)preg_replace('/\W+/', '', '2024-01-15T12:30:45Z'));
		expect($result)->toContain("cache=$expectedCache");
	}

	public function testBuildImageworksAPIHandlesExistingQueryParams(): void
	{
		$api   = '/api/imageworks/image/test-id/image.jpg?existing=param';
		$image = [
			'name'       => 'test.jpg',
			'uploadDate' => '2024-01-15T12:30:45Z',
		];
		$imageworks = ['w' => 300];

		$result = MediaTwigAdapter::buildImageworksAPI($api, 'test-id', $image, $imageworks);

		// Should preserve existing params and add new ones
		expect($result)->toContain('existing=param');
		expect($result)->toContain('w=300');
	}

	public function testBuildImageworksAPIWithCustomCollectionAndProperty(): void
	{
		$image = [
			'name'       => 'photo.jpg',
			'uploadDate' => '2024-01-15T12:30:45Z',
		];
		$options = [
			'collection' => 'portfolio',
			'property'   => 'featured',
		];

		$result = MediaTwigAdapter::buildImageworksAPI('/api', 'gallery-123', $image, [], $options);

		expect($result)->toContain('/api/imageworks/portfolio/gallery-123/featured.jpg');
	}

	public function testBuildImageworksGalleryAPIStaticMethod(): void
	{
		// Test the static gallery API method
		$baseApi    = '/api';
		$id         = 'gallery-123';
		$name       = 'photo.jpg';
		$image      = ['name' => 'photo.jpg', 'uploadDate' => '2024-01-15T12:30:45Z'];
		$imageworks = ['w' => 300, 'h' => 200];
		$options    = ['collection' => 'photos', 'property' => 'images'];

		$result = MediaTwigAdapter::buildImageworksGalleryAPI(
			$baseApi,
			$id,
			$name,
			$image,
			$imageworks,
			$options
		);

		expect($result)->toContain('/api/imageworks/photos/gallery-123/images/photo.jpg');
		expect($result)->toContain('w=300');
		expect($result)->toContain('h=200');
	}

	public function testBuildImageworksGalleryAPIWithDynamicRoute(): void
	{
		// Test with dynamic routes (first, last, random)
		$dynamicRoutes = ['first', 'last', 'random'];

		foreach ($dynamicRoutes as $route) {
			$result = MediaTwigAdapter::buildImageworksGalleryAPI(
				'/api',
				'gallery-123',
				$route,
				[],  // Empty image array for dynamic routes
				['w' => 200],
				[]
			);

			expect($result)->toContain("/api/imageworks/gallery/gallery-123/gallery/$route");
			expect($result)->toContain('w=200');
		}
	}

	public function testBuildImageworksGalleryAPIHandlesMissingUploadDate(): void
	{
		$result = MediaTwigAdapter::buildImageworksGalleryAPI(
			'/api',
			'gallery-123',
			'photo.jpg',
			['name' => 'photo.jpg'], // Missing uploadDate
			['w'    => 300],
			[]
		);

		// Should return empty string when uploadDate is missing for regular files
		expect($result)->toBe('');
	}

	public function testBuildImageworksGalleryAPIWithFormatConversion(): void
	{
		$image      = ['name' => 'photo.png', 'uploadDate' => '2024-01-15T12:30:45Z'];
		$imageworks = ['w' => 300, 'fm' => 'webp'];

		$result = MediaTwigAdapter::buildImageworksGalleryAPI(
			'/api',
			'gallery-123',
			'photo.png',
			$image,
			$imageworks,
			[]
		);

		expect($result)->toContain('/api/imageworks/gallery/gallery-123/gallery/photo.webp');
		expect($result)->not->toContain('fm=webp');
	}

	public function testImagePathMethodLogic(): void
	{
		$adapter = $this->createPartialMock(MediaTwigAdapter::class, []);

		// Test null ID handling (returns early before accessing config)
		expect($adapter->imagePath(null))->toBe('');
		expect($adapter->imagePath(''))->toBe('');

		// Test with valid image data using array input (needs 'id' key for array path)
		$object = [
			'id'    => 'test-id',
			'image' => [
				'name'       => 'test.jpg',
				'size'       => 12345,
				'uploadDate' => '2024-01-15T12:30:45Z',
			],
		];

		$config      = $this->createMock(\TotalCMS\Support\Config::class);
		$config->api = '/api';

		$loggerFactory = $this->createMock(\TotalCMS\Factory\LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new \Psr\Log\NullLogger());

		$fullAdapter = new MediaTwigAdapter(
			$this->createMock(\TotalCMS\Domain\Object\Service\ObjectFetcher::class),
			$config,
			$loggerFactory,
		);

		$result = $fullAdapter->imagePath($object);
		expect($result)->toBeString();
		expect($result)->toContain('/api/imageworks/image/');

		// Test with zero-size image (should return empty)
		$zeroSizeObject = [
			'id'    => 'test-id',
			'image' => [
				'name'       => 'test.jpg',
				'size'       => 0,
				'uploadDate' => '2024-01-15T12:30:45Z',
			],
		];
		expect($fullAdapter->imagePath($zeroSizeObject))->toBe('');
	}

	public function testGalleryPathMethodEdgeCases(): void
	{
		$adapter = $this->createPartialMock(MediaTwigAdapter::class, []);

		// Test null/empty ID handling
		expect($adapter->galleryPath(null, 'image.jpg'))->toBe('');
		expect($adapter->galleryPath('', 'image.jpg'))->toBe('');

		// Test null/empty name handling
		expect($adapter->galleryPath('gallery-id', null))->toBe('');
		expect($adapter->galleryPath('gallery-id', ''))->toBe('');
	}

	public function testPrettyUrlEdgeCases(): void
	{
		$adapter = $this->createPartialMock(CollectionTwigAdapter::class, []);

		$reflection     = new \ReflectionClass(CollectionTwigAdapter::class);
		$configProp     = $reflection->getProperty('config');
		$config         = $this->createMock(\TotalCMS\Support\Config::class);
		$config->domain = 'example.com';
		$configProp->setValue($adapter, $config);

		// Test various URL formats
		expect($adapter->prettyUrl(''))->toBe('/');
		expect($adapter->prettyUrl('/'))->toBe('/');
		expect($adapter->prettyUrl('/page'))->toBe('/page/');
		expect($adapter->prettyUrl('/page/'))->toBe('/page/');
		expect($adapter->prettyUrl('/page.html'))->toBe('/page.html/'); // .html is not .php
		expect($adapter->prettyUrl('/category/page.php'))->toBe('/category/');

		// Test with domain
		expect($adapter->prettyUrl('/page', true))->toBe('https://example.com/page/');
		expect($adapter->prettyUrl('/', true))->toBe('https://example.com/');
	}

	public function testDownloadUrlParameterHandling(): void
	{
		$adapter = $this->createPartialMock(MediaTwigAdapter::class, []);

		$reflection  = new \ReflectionClass(MediaTwigAdapter::class);
		$configProp  = $reflection->getProperty('config');
		$config      = $this->createMock(\TotalCMS\Support\Config::class);
		$config->api = '/api';
		$configProp->setValue($adapter, $config);

		// Test basic download URL
		expect($adapter->download('test-id'))->toBe('/api/download/file/test-id/file');

		// Test with collection and property options
		$result = $adapter->download('test-id', [
			'collection' => 'documents',
			'property'   => 'attachment',
		]);
		expect($result)->toBe('/api/download/documents/test-id/attachment');

		// Test with password (should contain encrypted pwd parameter)
		$result = $adapter->download('test-id', ['pwd' => 'secret123']);
		expect($result)->toContain('/api/download/file/test-id/file?pwd=');
		expect($result)->not->toContain('secret123'); // Password should be encrypted
	}

	public function testStreamUrlParameterHandling(): void
	{
		$adapter = $this->createPartialMock(MediaTwigAdapter::class, []);

		$reflection  = new \ReflectionClass(MediaTwigAdapter::class);
		$configProp  = $reflection->getProperty('config');
		$config      = $this->createMock(\TotalCMS\Support\Config::class);
		$config->api = '/api';
		$configProp->setValue($adapter, $config);

		// Test basic stream URL
		expect($adapter->stream('test-id'))->toBe('/api/stream/file/test-id/file');

		// Test with collection and property options
		$result = $adapter->stream('test-id', [
			'collection' => 'videos',
			'property'   => 'video',
		]);
		expect($result)->toBe('/api/stream/videos/test-id/video');
	}

	public function testDepotUrlParameterHandling(): void
	{
		$adapter = $this->createPartialMock(MediaTwigAdapter::class, []);

		$reflection  = new \ReflectionClass(MediaTwigAdapter::class);
		$configProp  = $reflection->getProperty('config');
		$config      = $this->createMock(\TotalCMS\Support\Config::class);
		$config->api = '/api';
		$configProp->setValue($adapter, $config);

		// Test depot download
		expect($adapter->depotDownload('depot-id', 'file.pdf'))
			->toBe('/api/download/depot/depot-id/depot/file.pdf');

		// Test depot stream
		expect($adapter->depotStream('depot-id', 'file.mp4'))
			->toBe('/api/stream/depot/depot-id/depot/file.mp4');

		// Test with path in filename
		$result = $adapter->depotDownload('depot-id', 'subfolder/file.pdf');
		expect($result)->toContain('/api/download/depot/depot-id/depot/file.pdf');
		expect($result)->toContain('path=subfolder');
	}

	public function testLoginUrlGeneration(): void
	{
		$adapter = $this->createPartialMock(AuthTwigAdapter::class, []);

		$reflection  = new \ReflectionClass(AuthTwigAdapter::class);
		$configProp  = $reflection->getProperty('config');
		$config      = $this->createMock(\TotalCMS\Support\Config::class);
		$config->api = '/api';
		$configProp->setValue($adapter, $config);

		expect($adapter->login())->toBe('/api/login');
		expect($adapter->login('admin'))->toBe('/api/login/admin');
		expect($adapter->login(''))->toBe('/api/login');
	}

	public function testProcessJobQueueCommandGeneration(): void
	{
		$config      = $this->createMock(\TotalCMS\Support\Config::class);
		$config->env = 'prod';

		$adapter = new AdminTwigAdapter(
			$config,
			$this->createMock(AuthTwigAdapter::class),
			$this->createMock(\TotalCMS\Domain\Collection\Service\CollectionLister::class),
			$this->createMock(\TotalCMS\Domain\Schema\Service\SchemaLister::class),
			$this->createMock(\TotalCMS\Domain\Template\Service\TemplateLister::class),
			$this->createMock(\TotalCMS\Domain\JobQueue\Service\JobManager::class),
			$this->createMock(\TotalCMS\Domain\Cache\Service\DevModeManager::class),
			$this->createMock(\TotalCMS\Domain\Collection\Service\CollectionEditionService::class),
			$this->createMock(\TotalCMS\Domain\Cache\CacheReporter::class),
			$this->createMock(\TotalCMS\Domain\License\Service\LicenseStatus::class),
			$this->createMock(\TotalCMS\Domain\Index\Service\IndexReader::class),
			$this->createMock(\TotalCMS\Infrastructure\Diagnostics\ServerChecker::class),
			$this->createMock(\TotalCMS\Infrastructure\Diagnostics\LogAnalyzer::class),
			$this->createMock(\TotalCMS\Domain\ImageWorks\Service\ImageCacheService::class),
			$this->createMock(\TotalCMS\Domain\Cache\CacheSizingAdvisor::class),
		);

		// Mock $_SERVER for test
		$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';

		$command = $adapter->processJobQueueCommand();

		expect($command)->toBeString();
		expect($command)->toContain('tcms');
		expect($command)->toContain('jobs:process');

		// Clean up
		unset($_SERVER['DOCUMENT_ROOT']);
	}
}
