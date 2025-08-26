<?php

declare(strict_types=1);

namespace Tests\Unit\ImageWorks\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Support\Config;

class ImageCacheServiceTest extends TestCase
{
	private ImageCacheService $service;
	private \PHPUnit\Framework\MockObject\MockObject $mockConfig;
	private string $testDataDir;

	protected function setUp(): void
	{
		$this->mockConfig = $this->createMock(Config::class);

		// Create a temporary directory for testing
		$this->testDataDir = sys_get_temp_dir() . '/totalcms-test-' . uniqid();
		mkdir($this->testDataDir);
		$this->mockConfig->datadir = $this->testDataDir;

		$this->service = new ImageCacheService($this->mockConfig);
	}

	protected function tearDown(): void
	{
		// Clean up test directory
		if (is_dir($this->testDataDir)) {
			$this->removeDirectory($this->testDataDir);
		}
	}

	private function removeDirectory(string $dir): bool
	{
		if (!is_dir($dir)) {
			return false;
		}

		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) {
			$path = $dir . '/' . $file;
			is_dir($path) ? $this->removeDirectory($path) : unlink($path);
		}

		return rmdir($dir);
	}

	public function testClearCollectionImageCacheThrowsExceptionWhenCollectionNotExists(): void
	{
		$collection = 'nonexistent';

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Collection directory does not exist:');

		$this->service->clearCollectionImageCache($collection);
	}

	public function testClearCollectionImageCacheWithEmptyCollection(): void
	{
		$collection     = 'empty-collection';
		$collectionPath = $this->testDataDir . '/' . $collection;

		// Create empty collection directory
		mkdir($collectionPath);

		$result = $this->service->clearCollectionImageCache($collection);

		$this->assertTrue($result);
	}

	public function testClearCollectionImageCacheRemovesCacheDirectories(): void
	{
		$collection     = 'test-collection';
		$collectionPath = $this->testDataDir . '/' . $collection;

		// Create collection structure with cache directories
		$this->createTestStructure($collectionPath, [
			'object1' => [
				'property1' => [
					'.cache' => [
						'cached_image.jpg'   => 'cached content',
						'another_cached.png' => 'more content',
					],
					'original.jpg' => 'original content',
				],
			],
			'object2' => [
				'property2' => [
					'.cache' => [
						'thumb.jpg' => 'thumbnail',
					],
				],
			],
		]);

		$result = $this->service->clearCollectionImageCache($collection);

		$this->assertTrue($result);

		// Verify cache directories are removed but original files remain
		$this->assertDirectoryDoesNotExist($collectionPath . '/object1/property1/.cache');
		$this->assertDirectoryDoesNotExist($collectionPath . '/object2/property2/.cache');
		$this->assertFileExists($collectionPath . '/object1/property1/original.jpg');
	}

	public function testGetCollectionImageCacheStatsForNonExistentCollection(): void
	{
		$collection = 'nonexistent';

		$stats = $this->service->getCollectionImageCacheStats($collection);

		$this->assertIsArray($stats);
		$this->assertEquals($collection, $stats['collection']);
		$this->assertEquals(0, $stats['cache_directories']);
		$this->assertEquals(0, $stats['cached_files']);
		$this->assertEquals(0, $stats['total_size_bytes']);
		$this->assertFalse($stats['exists']); // Should be false for non-existent collection
	}

	public function testGetCollectionImageCacheStatsWithEmptyCollection(): void
	{
		$collection     = 'empty-collection';
		$collectionPath = $this->testDataDir . '/' . $collection;

		// Create empty collection directory
		mkdir($collectionPath);

		$stats = $this->service->getCollectionImageCacheStats($collection);

		$this->assertIsArray($stats);
		$this->assertEquals($collection, $stats['collection']);
		$this->assertEquals(0, $stats['cache_directories']);
		$this->assertEquals(0, $stats['cached_files']);
		$this->assertEquals(0, $stats['total_size_bytes']);
		$this->assertTrue($stats['exists']);
	}

	public function testConstructorAcceptsConfig(): void
	{
		// Test that constructor properly accepts Config dependency
		$config  = $this->createMock(Config::class);
		$service = new ImageCacheService($config);

		$this->assertInstanceOf(ImageCacheService::class, $service);
	}

	public function testServiceIsReadonly(): void
	{
		// Test that the service class is readonly
		$reflection = new \ReflectionClass(ImageCacheService::class);
		$this->assertTrue($reflection->isReadOnly());
	}

	/**
	 * Helper method to create test directory structure.
	 */
	private function createTestStructure(string $basePath, array $structure): void
	{
		foreach ($structure as $name => $content) {
			$path = $basePath . '/' . $name;

			if (is_array($content)) {
				mkdir($path, 0755, true);
				$this->createTestStructure($path, $content);
			} else {
				// Create directory if it doesn't exist
				$dir = dirname($path);
				if (!is_dir($dir)) {
					mkdir($dir, 0755, true);
				}
				file_put_contents($path, $content);
			}
		}
	}
}
