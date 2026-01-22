<?php

declare(strict_types=1);

namespace Tests\Unit\Export\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Export\Service\ObjectZipper;
use TotalCMS\Support\Config;

final class ObjectZipperTest extends TestCase
{
	private ObjectZipper $objectZipper;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/object_zipper_test_' . uniqid();
		mkdir($this->tempDir);

		$this->config          = $this->createMock(Config::class);
		$this->config->datadir = $this->tempDir;

		$this->objectZipper = new ObjectZipper($this->config);
	}

	protected function tearDown(): void
	{
		if (is_dir($this->tempDir)) {
			$this->removeDirectory($this->tempDir);
		}
	}

	private function removeDirectory(string $dir): void
	{
		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) {
			$filePath = $dir . '/' . $file;
			is_dir($filePath) ? $this->removeDirectory($filePath) : unlink($filePath);
		}
		rmdir($dir);
	}

	private function createTestObject(string $collection, string $id, bool $withAssets = false, bool $withCache = false): void
	{
		$collectionPath = $this->tempDir . DIRECTORY_SEPARATOR . $collection;
		if (!is_dir($collectionPath)) {
			mkdir($collectionPath);
		}

		// Create object JSON file
		file_put_contents($collectionPath . DIRECTORY_SEPARATOR . $id . '.json', '{"id": "' . $id . '", "title": "Test Object"}');

		// Create assets folder if requested
		if ($withAssets) {
			$assetsPath = $collectionPath . DIRECTORY_SEPARATOR . $id;
			mkdir($assetsPath);
			file_put_contents($assetsPath . DIRECTORY_SEPARATOR . 'image.jpg', 'fake image content');
			file_put_contents($assetsPath . DIRECTORY_SEPARATOR . 'document.pdf', 'fake pdf content');

			// Create subfolder with files
			mkdir($assetsPath . DIRECTORY_SEPARATOR . 'gallery');
			file_put_contents($assetsPath . DIRECTORY_SEPARATOR . 'gallery' . DIRECTORY_SEPARATOR . 'photo1.jpg', 'photo 1');
			file_put_contents($assetsPath . DIRECTORY_SEPARATOR . 'gallery' . DIRECTORY_SEPARATOR . 'photo2.jpg', 'photo 2');
		}

		// Create .cache folder if requested
		if ($withCache) {
			$assetsPath = $collectionPath . DIRECTORY_SEPARATOR . $id;
			if (!is_dir($assetsPath)) {
				mkdir($assetsPath);
			}
			$cachePath = $assetsPath . DIRECTORY_SEPARATOR . '.cache';
			mkdir($cachePath);
			file_put_contents($cachePath . DIRECTORY_SEPARATOR . 'cached-file.tmp', 'cached content');
		}
	}

	public function testGetZipFilename(): void
	{
		$collection = 'blog';
		$id         = 'my-post';
		$filename   = $this->objectZipper->getZipFilename($collection, $id);

		$this->assertEquals('blog--my-post.zip', $filename);
		$this->assertStringEndsWith('.zip', $filename);
		$this->assertStringContainsString($collection, $filename);
		$this->assertStringContainsString($id, $filename);
	}

	public function testGetZipFilenameWithSpecialCharacters(): void
	{
		$collection = 'special-collection';
		$id         = 'object_with-symbols';
		$filename   = $this->objectZipper->getZipFilename($collection, $id);

		$this->assertEquals('special-collection--object_with-symbols.zip', $filename);
	}

	public function testCreateObjectZipWithJsonOnly(): void
	{
		$collection = 'blog';
		$id         = 'test-post';
		$this->createTestObject($collection, $id, withAssets: false);

		$zipPath = $this->objectZipper->createObjectZip($collection, $id);

		$this->assertFileExists($zipPath);
		$this->assertStringEndsWith('.zip', $zipPath);

		// Verify zip can be opened and contains only the JSON file
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($zipPath));
		$this->assertEquals(1, $zip->numFiles);
		$this->assertEquals($id . '.json', $zip->getNameIndex(0));

		$zip->close();
		unlink($zipPath);
	}

	public function testCreateObjectZipWithAssets(): void
	{
		$collection = 'blog';
		$id         = 'test-post';
		$this->createTestObject($collection, $id, withAssets: true);

		$zipPath = $this->objectZipper->createObjectZip($collection, $id);

		$this->assertFileExists($zipPath);

		// Verify zip contains JSON and assets
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($zipPath));
		$this->assertGreaterThan(1, $zip->numFiles);

		// Collect all file names in the zip
		$zipFiles = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$zipFiles[] = $zip->getNameIndex($i);
		}

		// Check that JSON file exists
		$this->assertContains($id . '.json', $zipFiles);

		// Check that asset files exist (normalize path separators)
		$normalizedFiles = array_map(fn($f) => str_replace('\\', '/', $f), $zipFiles);
		$this->assertContains($id . '/image.jpg', $normalizedFiles);
		$this->assertContains($id . '/document.pdf', $normalizedFiles);
		$this->assertContains($id . '/gallery/photo1.jpg', $normalizedFiles);

		$zip->close();
		unlink($zipPath);
	}

	public function testCreateObjectZipExcludesCacheDirectory(): void
	{
		$collection = 'blog';
		$id         = 'test-post';
		$this->createTestObject($collection, $id, withAssets: true, withCache: true);

		$zipPath = $this->objectZipper->createObjectZip($collection, $id);

		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($zipPath));

		// Verify .cache files are NOT in the zip
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$filename = $zip->getNameIndex($i);
			$this->assertStringNotContainsString('.cache', $filename);
		}

		$zip->close();
		unlink($zipPath);
	}

	public function testCreateObjectZipThrowsExceptionForNonexistentObject(): void
	{
		$collectionPath = $this->tempDir . DIRECTORY_SEPARATOR . 'blog';
		mkdir($collectionPath);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Object not found: blog/nonexistent');

		$this->objectZipper->createObjectZip('blog', 'nonexistent');
	}

	public function testCreateObjectZipWithEmptyAssetsFolder(): void
	{
		$collection = 'blog';
		$id         = 'test-post';

		// Create object with empty assets folder
		$collectionPath = $this->tempDir . DIRECTORY_SEPARATOR . $collection;
		mkdir($collectionPath);
		file_put_contents($collectionPath . DIRECTORY_SEPARATOR . $id . '.json', '{"id": "' . $id . '"}');
		mkdir($collectionPath . DIRECTORY_SEPARATOR . $id);

		$zipPath = $this->objectZipper->createObjectZip($collection, $id);

		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($zipPath));

		// Should only contain the JSON file (empty folder not added)
		$this->assertEquals(1, $zip->numFiles);
		$this->assertEquals($id . '.json', $zip->getNameIndex(0));

		$zip->close();
		unlink($zipPath);
	}

	public function testCreateObjectZipWithOnlyCacheInAssetsFolder(): void
	{
		$collection = 'blog';
		$id         = 'test-post';

		// Create object with only .cache in assets folder
		$collectionPath = $this->tempDir . DIRECTORY_SEPARATOR . $collection;
		mkdir($collectionPath);
		file_put_contents($collectionPath . DIRECTORY_SEPARATOR . $id . '.json', '{"id": "' . $id . '"}');
		$assetsPath = $collectionPath . DIRECTORY_SEPARATOR . $id;
		mkdir($assetsPath);
		mkdir($assetsPath . DIRECTORY_SEPARATOR . '.cache');
		file_put_contents($assetsPath . DIRECTORY_SEPARATOR . '.cache' . DIRECTORY_SEPARATOR . 'cached.tmp', 'cache');

		$zipPath = $this->objectZipper->createObjectZip($collection, $id);

		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($zipPath));

		// Should only contain the JSON file (.cache folder ignored)
		$this->assertEquals(1, $zip->numFiles);
		$this->assertEquals($id . '.json', $zip->getNameIndex(0));

		$zip->close();
		unlink($zipPath);
	}
}
