<?php

declare(strict_types=1);

namespace Tests\Unit\ImageWorks\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Support\Config;

final class ImageCacheServiceTest extends TestCase
{
	private ImageCacheService $imageCacheService;
	private string $tempDir;
	private Config $config;

	protected function setUp(): void
	{
		// Create temporary directory for testing
		$this->tempDir = sys_get_temp_dir() . '/totalcms_test_' . uniqid();
		mkdir($this->tempDir, 0777, true);

		$this->config = new Config([
			'env'        => 'test',
			'template'   => '/tmp',
			'dashboard'  => [],
			'datadir'    => $this->tempDir,
			'tmpdir'     => '/tmp',
			'cache'      => [],
			'logger'     => [],
			'sentry'     => [],
			'error'      => [],
			'domain'     => 'test.com',
			'api'        => 'http://test.com/api',
			'locale'     => 'en_US',
			'session'    => [],
			'auth'       => [],
			'debug'      => false,
			'notfound'   => '/404',
			'htmlclean'  => [],
			'timezone'   => 'UTC',
			'imageworks' => [],
		]);

		$this->imageCacheService = new ImageCacheService($this->config);
	}

	protected function tearDown(): void
	{
		// Clean up temporary directory
		if (is_dir($this->tempDir)) {
			$this->removeDirectory($this->tempDir);
		}
	}

	private function removeDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) {
			$path = $dir . '/' . $file;
			if (is_dir($path)) {
				$this->removeDirectory($path);
			} else {
				unlink($path);
			}
		}
		rmdir($dir);
	}

	public function testClearCollectionImageCacheWithNonExistentCollection(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Collection directory does not exist:');

		$this->imageCacheService->clearCollectionImageCache('nonexistent');
	}

	public function testClearCollectionImageCacheWithEmptyCollection(): void
	{
		// Create empty collection directory
		$collectionPath = $this->tempDir . '/empty_collection';
		mkdir($collectionPath);

		$result = $this->imageCacheService->clearCollectionImageCache('empty_collection');

		$this->assertTrue($result);
	}

	public function testClearCollectionImageCacheWithSingleCacheDirectory(): void
	{
		// Create collection with one .cache directory
		$collectionPath = $this->tempDir . '/test_collection';
		mkdir($collectionPath);

		$cachePath = $collectionPath . '/.cache';
		mkdir($cachePath);

		// Add some cache files
		file_put_contents($cachePath . '/image1.jpg', 'cached image data');
		file_put_contents($cachePath . '/image2.jpg', 'cached image data');

		$this->assertDirectoryExists($cachePath);
		$this->assertFileExists($cachePath . '/image1.jpg');

		$result = $this->imageCacheService->clearCollectionImageCache('test_collection');

		$this->assertTrue($result);
		$this->assertDirectoryDoesNotExist($cachePath);
	}

	public function testClearCollectionImageCacheWithMultipleCacheDirectories(): void
	{
		// Create collection with nested structure and multiple .cache directories
		$collectionPath = $this->tempDir . '/multi_cache_collection';
		mkdir($collectionPath);

		// Root level cache
		$rootCache = $collectionPath . '/.cache';
		mkdir($rootCache);
		file_put_contents($rootCache . '/root_image.jpg', 'root cached image');

		// Nested cache directories
		$subDir = $collectionPath . '/subfolder';
		mkdir($subDir);
		$subCache = $subDir . '/.cache';
		mkdir($subCache);
		file_put_contents($subCache . '/sub_image.jpg', 'sub cached image');

		// Deep nested cache
		$deepDir = $subDir . '/deep';
		mkdir($deepDir);
		$deepCache = $deepDir . '/.cache';
		mkdir($deepCache);
		file_put_contents($deepCache . '/deep_image.jpg', 'deep cached image');

		// Verify setup
		$this->assertDirectoryExists($rootCache);
		$this->assertDirectoryExists($subCache);
		$this->assertDirectoryExists($deepCache);

		$result = $this->imageCacheService->clearCollectionImageCache('multi_cache_collection');

		$this->assertTrue($result);
		$this->assertDirectoryDoesNotExist($rootCache);
		$this->assertDirectoryDoesNotExist($subCache);
		$this->assertDirectoryDoesNotExist($deepCache);

		// Parent directories should still exist
		$this->assertDirectoryExists($collectionPath);
		$this->assertDirectoryExists($subDir);
		$this->assertDirectoryExists($deepDir);
	}

	public function testClearCollectionImageCacheIgnoresNonCacheDirectories(): void
	{
		// Create collection with mixed directories
		$collectionPath = $this->tempDir . '/mixed_collection';
		mkdir($collectionPath);

		// Create .cache directory (should be removed)
		$cachePath = $collectionPath . '/.cache';
		mkdir($cachePath);
		file_put_contents($cachePath . '/cached.jpg', 'cached');

		// Create other directories (should be preserved)
		$imagesDir = $collectionPath . '/images';
		mkdir($imagesDir);
		file_put_contents($imagesDir . '/original.jpg', 'original');

		$assetsDir = $collectionPath . '/assets';
		mkdir($assetsDir);
		file_put_contents($assetsDir . '/asset.css', 'styles');

		// Create files in root (should be preserved)
		file_put_contents($collectionPath . '/data.json', '{}');

		$result = $this->imageCacheService->clearCollectionImageCache('mixed_collection');

		$this->assertTrue($result);

		// Cache should be removed
		$this->assertDirectoryDoesNotExist($cachePath);

		// Other directories and files should remain
		$this->assertDirectoryExists($imagesDir);
		$this->assertDirectoryExists($assetsDir);
		$this->assertFileExists($imagesDir . '/original.jpg');
		$this->assertFileExists($assetsDir . '/asset.css');
		$this->assertFileExists($collectionPath . '/data.json');
	}

	public function testClearCollectionImageCacheWithSymlinks(): void
	{
		// Create collection directory
		$collectionPath = $this->tempDir . '/symlink_collection';
		mkdir($collectionPath);

		// Create actual cache directory
		$actualCache = $collectionPath . '/.cache';
		mkdir($actualCache);
		file_put_contents($actualCache . '/real_image.jpg', 'real cached image');

		// Create symlink to another directory (should be handled safely)
		$externalDir = $this->tempDir . '/external';
		mkdir($externalDir);
		file_put_contents($externalDir . '/external_file.txt', 'external content');

		// Only create symlink if symlink function exists
		if (function_exists('symlink') && !is_windows()) {
			$symlinkPath = $collectionPath . '/external_link';
			symlink($externalDir, $symlinkPath);
			$this->assertDirectoryExists($symlinkPath);
		}

		$result = $this->imageCacheService->clearCollectionImageCache('symlink_collection');

		$this->assertTrue($result);
		$this->assertDirectoryDoesNotExist($actualCache);

		// External directory should still exist (symlink target preserved)
		$this->assertDirectoryExists($externalDir);
		$this->assertFileExists($externalDir . '/external_file.txt');
	}

	public function testClearCollectionImageCacheWithLargeNumberOfFiles(): void
	{
		// Create collection with many cache files
		$collectionPath = $this->tempDir . '/large_collection';
		mkdir($collectionPath);

		$cachePath = $collectionPath . '/.cache';
		mkdir($cachePath);

		// Create many cache files
		$fileCount = 100;
		for ($i = 0; $i < $fileCount; $i++) {
			file_put_contents($cachePath . "/image_{$i}.jpg", "cached image data {$i}");
		}

		// Verify files were created
		$this->assertEquals($fileCount, count(glob($cachePath . '/*.jpg')));

		$result = $this->imageCacheService->clearCollectionImageCache('large_collection');

		$this->assertTrue($result);
		$this->assertDirectoryDoesNotExist($cachePath);
	}

	public function testClearCollectionImageCacheWithNestedSubdirectories(): void
	{
		// Create complex nested structure
		$collectionPath = $this->tempDir . '/nested_collection';
		mkdir($collectionPath);

		// Create nested structure: collection/year/month/.cache
		$yearPath = $collectionPath . '/2024';
		mkdir($yearPath);

		$monthPath = $yearPath . '/01';
		mkdir($monthPath);

		$cachePath = $monthPath . '/.cache';
		mkdir($cachePath);
		file_put_contents($cachePath . '/january_image.jpg', 'january cache');

		// Another month
		$anotherMonth = $yearPath . '/02';
		mkdir($anotherMonth);
		$anotherCache = $anotherMonth . '/.cache';
		mkdir($anotherCache);
		file_put_contents($anotherCache . '/february_image.jpg', 'february cache');

		$result = $this->imageCacheService->clearCollectionImageCache('nested_collection');

		$this->assertTrue($result);
		$this->assertDirectoryDoesNotExist($cachePath);
		$this->assertDirectoryDoesNotExist($anotherCache);

		// Parent structure should remain
		$this->assertDirectoryExists($yearPath);
		$this->assertDirectoryExists($monthPath);
		$this->assertDirectoryExists($anotherMonth);
	}

	public function testClearCollectionImageCacheWithReadOnlyFiles(): void
	{
		// Skip this test on Windows as chmod behaves differently
		if (is_windows()) {
			$this->markTestSkipped('Skipping read-only file test on Windows');
		}

		$collectionPath = $this->tempDir . '/readonly_collection';
		mkdir($collectionPath);

		$cachePath = $collectionPath . '/.cache';
		mkdir($cachePath);

		// Create a read-only file
		$readOnlyFile = $cachePath . '/readonly_image.jpg';
		file_put_contents($readOnlyFile, 'readonly cache');
		chmod($readOnlyFile, 0444); // Read-only

		$result = $this->imageCacheService->clearCollectionImageCache('readonly_collection');

		$this->assertTrue($result);
		$this->assertDirectoryDoesNotExist($cachePath);
	}

	public function testClearCollectionImageCachePreservesHiddenNonCacheDirectories(): void
	{
		$collectionPath = $this->tempDir . '/hidden_dirs_collection';
		mkdir($collectionPath);

		// Create .cache directory (should be removed)
		$cachePath = $collectionPath . '/.cache';
		mkdir($cachePath);
		file_put_contents($cachePath . '/cached.jpg', 'cached');

		// Create other hidden directories (should be preserved)
		$gitDir = $collectionPath . '/.git';
		mkdir($gitDir);
		file_put_contents($gitDir . '/config', 'git config');

		$htaccessDir = $collectionPath . '/.htaccess_backup';
		mkdir($htaccessDir);
		file_put_contents($htaccessDir . '/backup', 'htaccess backup');

		$result = $this->imageCacheService->clearCollectionImageCache('hidden_dirs_collection');

		$this->assertTrue($result);

		// Only .cache should be removed
		$this->assertDirectoryDoesNotExist($cachePath);
		$this->assertDirectoryExists($gitDir);
		$this->assertDirectoryExists($htaccessDir);
		$this->assertFileExists($gitDir . '/config');
		$this->assertFileExists($htaccessDir . '/backup');
	}

	public function testClearCollectionImageCacheHandlesEmptyCacheDirectories(): void
	{
		$collectionPath = $this->tempDir . '/empty_cache_collection';
		mkdir($collectionPath);

		// Create empty .cache directories
		$rootCache = $collectionPath . '/.cache';
		mkdir($rootCache);

		$subDir = $collectionPath . '/sub';
		mkdir($subDir);
		$subCache = $subDir . '/.cache';
		mkdir($subCache);

		$this->assertDirectoryExists($rootCache);
		$this->assertDirectoryExists($subCache);

		$result = $this->imageCacheService->clearCollectionImageCache('empty_cache_collection');

		$this->assertTrue($result);
		$this->assertDirectoryDoesNotExist($rootCache);
		$this->assertDirectoryDoesNotExist($subCache);
		$this->assertDirectoryExists($subDir);
	}
}

/**
 * Helper function to detect Windows OS
 */
function is_windows(): bool
{
	return PHP_OS_FAMILY === 'Windows';
}