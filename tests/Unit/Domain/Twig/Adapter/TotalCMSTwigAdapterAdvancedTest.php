<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;

final class TotalCMSTwigAdapterAdvancedTest extends TestCase
{
	public function testIsEncryptedPasswordDetectsEncryptedPasswords(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		// Use reflection to access private method
		$reflection = new \ReflectionClass(TotalCMSTwigAdapter::class);
		$method     = $reflection->getMethod('isEncryptedPassword');

		// Test plain password (should not be detected as encrypted)
		$result = $method->invoke($adapter, 'plainpassword');
		expect($result)->toBeFalse();

		// Test short string (should not be detected as encrypted)
		$result = $method->invoke($adapter, 'short');
		expect($result)->toBeFalse();

		// Test base64 string that's too short (should not be detected as encrypted)
		$result = $method->invoke($adapter, base64_encode('short'));
		expect($result)->toBeFalse();

		// Test valid base64 string of reasonable length (should be detected as encrypted)
		$longEncrypted = base64_encode('this is a long encrypted password data that should be detected');
		$result        = $method->invoke($adapter, $longEncrypted);
		expect($result)->toBeTrue();

		// Test invalid base64 (should not be detected as encrypted)
		$result = $method->invoke($adapter, 'invalid@base64#string!');
		expect($result)->toBeFalse();
	}

	public function testBuildImageworksGalleryAPIWithDynamicRoutes(): void
	{
		$baseApi    = '/api';
		$id         = 'gallery-123';
		$name       = 'first'; // Dynamic route
		$image      = [];
		$imageworks = ['w' => 300, 'h' => 200];
		$options    = ['collection' => 'photos', 'property' => 'images'];

		$result = TotalCMSTwigAdapter::buildImageworksGalleryAPI(
			$baseApi,
			$id,
			$name,
			$image,
			$imageworks,
			$options
		);

		expect($result)->toContain('/api/imageworks/photos/gallery-123/images/first');
		expect($result)->toContain('w=300');
		expect($result)->toContain('h=200');
		expect($result)->toContain('cache=');
	}

	public function testBuildImageworksGalleryAPIWithRegularFilename(): void
	{
		$baseApi    = '/api';
		$id         = 'gallery-123';
		$name       = 'photo.jpg';
		$image      = ['name' => 'photo.jpg', 'uploadDate' => '2024-01-15T12:30:45Z'];
		$imageworks = ['w' => 300];
		$options    = [];

		$result = TotalCMSTwigAdapter::buildImageworksGalleryAPI(
			$baseApi,
			$id,
			$name,
			$image,
			$imageworks,
			$options
		);

		expect($result)->toContain('/api/imageworks/gallery/gallery-123/gallery/photo.jpg');
		expect($result)->toContain('w=300');
		expect($result)->toContain('cache=');
	}

	public function testBuildImageworksGalleryAPIWithMissingUploadDate(): void
	{
		$baseApi    = '/api';
		$id         = 'gallery-123';
		$name       = 'photo.jpg';
		$image      = ['name' => 'photo.jpg']; // Missing uploadDate
		$imageworks = ['w' => 300];
		$options    = [];

		$result = TotalCMSTwigAdapter::buildImageworksGalleryAPI(
			$baseApi,
			$id,
			$name,
			$image,
			$imageworks,
			$options
		);

		// Should return empty string when uploadDate is missing for regular files
		expect($result)->toBe('');
	}

	public function testBuildImageworksGalleryAPIWithFormatConversion(): void
	{
		$baseApi    = '/api';
		$id         = 'gallery-123';
		$name       = 'photo.png';
		$image      = ['name' => 'photo.png', 'uploadDate' => '2024-01-15T12:30:45Z'];
		$imageworks = ['w' => 300, 'fm' => 'webp']; // Format conversion
		$options    = [];

		$result = TotalCMSTwigAdapter::buildImageworksGalleryAPI(
			$baseApi,
			$id,
			$name,
			$image,
			$imageworks,
			$options
		);

		expect($result)->toContain('/api/imageworks/gallery/gallery-123/gallery/photo.webp');
		expect($result)->toContain('w=300');
		expect($result)->not->toContain('fm=webp'); // fm should be removed after processing
	}

	public function testBuildImageworksGalleryAPIRemovesStacksPreviewParams(): void
	{
		$baseApi    = '/api';
		$id         = 'gallery-123';
		$name       = 'first';
		$image      = [];
		$imageworks = [
			'w'       => 300,
			'datadir' => '/some/path', // Should be removed
			'route'   => '/some/route',   // Should be removed
		];
		$options = [];

		$result = TotalCMSTwigAdapter::buildImageworksGalleryAPI(
			$baseApi,
			$id,
			$name,
			$image,
			$imageworks,
			$options
		);

		expect($result)->toContain('w=300');
		expect($result)->not->toContain('datadir');
		expect($result)->not->toContain('route');
	}

	public function testAltMethodWithValidImageData(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['data']);

		$adapter->method('data')
			->with('image', 'test-id', 'image')
			->willReturn(['alt' => 'Test alt text', 'filename' => 'test.jpg']);

		$result = $adapter->alt('test-id');

		expect($result)->toBe('Test alt text');
	}

	public function testAltMethodWithMissingAlt(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['data']);

		$adapter->method('data')
			->with('image', 'test-id', 'image')
			->willReturn(['filename' => 'test.jpg']); // No alt field

		$result = $adapter->alt('test-id');

		expect($result)->toBe('');
	}

	public function testAltMethodWithNonArrayData(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['data']);

		$adapter->method('data')
			->with('image', 'test-id', 'image')
			->willReturn('not an array');

		$result = $adapter->alt('test-id');

		expect($result)->toBe('');
	}

	public function testGalleryImageDataFindsCorrectImage(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['data']);

		$galleryData = [
			['name' => 'image1.jpg', 'alt' => 'Image 1'],
			['name' => 'image2.jpg', 'alt' => 'Image 2'],
			['name' => 'image3.jpg', 'alt' => 'Image 3'],
		];

		$adapter->method('data')
			->with('gallery', 'gallery-id', 'gallery')
			->willReturn($galleryData);

		$result = $adapter->galleryImageData('gallery-id', 'image2.jpg');

		expect($result)->toBe(['name' => 'image2.jpg', 'alt' => 'Image 2']);
	}

	public function testGalleryImageDataReturnsNullForNotFound(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['data']);

		$galleryData = [
			['name' => 'image1.jpg', 'alt' => 'Image 1'],
			['name' => 'image2.jpg', 'alt' => 'Image 2'],
		];

		$adapter->method('data')
			->with('gallery', 'gallery-id', 'gallery')
			->willReturn($galleryData);

		$result = $adapter->galleryImageData('gallery-id', 'nonexistent.jpg');

		expect($result)->toBeNull();
	}

	public function testGalleryImageDataReturnsNullForNonArrayData(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['data']);

		$adapter->method('data')
			->with('gallery', 'gallery-id', 'gallery')
			->willReturn('not an array');

		$result = $adapter->galleryImageData('gallery-id', 'image.jpg');

		expect($result)->toBeNull();
	}

	public function testGalleryAltReturnsCorrectAlt(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['galleryImageData']);

		$adapter->method('galleryImageData')
			->with('gallery-id', 'image.jpg', ['collection' => 'gallery', 'property' => 'gallery'])
			->willReturn(['name' => 'image.jpg', 'alt' => 'Gallery image alt']);

		$result = $adapter->galleryAlt('gallery-id', 'image.jpg');

		expect($result)->toBe('Gallery image alt');
	}

	public function testGalleryAltReturnsEmptyForMissingAlt(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['galleryImageData']);

		$adapter->method('galleryImageData')
			->with('gallery-id', 'image.jpg', ['collection' => 'gallery', 'property' => 'gallery'])
			->willReturn(['name' => 'image.jpg']); // No alt

		$result = $adapter->galleryAlt('gallery-id', 'image.jpg');

		// When alt is missing, the function falls back to returning the image name
		expect($result)->toBe('image.jpg');
	}

	public function testGalleryAltReturnsEmptyForNullImageData(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['galleryImageData']);

		$adapter->method('galleryImageData')
			->with('gallery-id', 'image.jpg', ['collection' => 'gallery', 'property' => 'gallery'])
			->willReturn(null);

		$result = $adapter->galleryAlt('gallery-id', 'image.jpg');

		expect($result)->toBe('');
	}

	public function testGalleryPathReturnsEmptyForNullOrEmptyId(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		expect($adapter->galleryPath(null, 'image.jpg'))->toBe('');
		expect($adapter->galleryPath('', 'image.jpg'))->toBe('');
	}

	public function testGalleryPathReturnsEmptyForNullOrEmptyName(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, []);

		expect($adapter->galleryPath('gallery-id', null))->toBe('');
		expect($adapter->galleryPath('gallery-id', ''))->toBe('');
	}

	public function testEmailMethodWithObfuscation(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['data']);

		$adapter->method('data')
			->with('email', 'test-id', 'email')
			->willReturn('test@example.com');

		// Test without obfuscation
		$result = $adapter->email('test-id', [], false);
		expect($result)->toBe('test@example.com');

		// Test with obfuscation
		$result = $adapter->email('test-id', [], true);
		expect($result)->not->toBe('test@example.com');
		expect($result)->toContain('&#'); // Should contain HTML entities
	}

	public function testSvgMethodWithValidData(): void
	{
		$adapter = $this->createPartialMock(TotalCMSTwigAdapter::class, ['data']);

		$adapter->method('data')
			->with('svg', 'test-id', 'svg')
			->willReturn('<svg>test</svg>');

		$result = $adapter->svg('test-id');

		expect($result)->toBe('<svg>test</svg>');
	}
}
