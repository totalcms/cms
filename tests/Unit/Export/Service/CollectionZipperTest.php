<?php

declare(strict_types = 1);

namespace Tests\Unit\Export\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Export\Service\CollectionZipper;
use TotalCMS\Support\Config;

final class CollectionZipperTest extends TestCase
{
	private CollectionZipper $collectionZipper;
	private \PHPUnit\Framework\MockObject\MockObject $config;
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/collection_zipper_test_' . uniqid();
		mkdir($this->tempDir);

		// Create mock config (now that Config is no longer final)
		$this->config          = $this->createMock(Config::class);
		$this->config->datadir = $this->tempDir;

		$this->collectionZipper = new CollectionZipper($this->config);
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

	private function createTestCollection(string $name): string
	{
		$collectionPath = $this->tempDir . DIRECTORY_SEPARATOR . $name;
		mkdir($collectionPath);

		// Create some test files
		file_put_contents($collectionPath . DIRECTORY_SEPARATOR . 'collection.json', '{"id": "' . $name . '"}');
		file_put_contents($collectionPath . DIRECTORY_SEPARATOR . 'test-object.json', '{"id": "test"}');

		return $collectionPath;
	}

	public function testGetZipFilename(): void
	{
		$collectionName = 'test-collection';
		$filename       = $this->collectionZipper->getZipFilename($collectionName);

		$this->assertEquals('collection-test-collection.zip', $filename);
		$this->assertStringStartsWith('collection-', $filename);
		$this->assertStringEndsWith('.zip', $filename);
		$this->assertStringContainsString($collectionName, $filename);
	}

	public function testGetZipFilenameWithSpecialCharacters(): void
	{
		$collectionName = 'special-chars-collection_with-symbols';
		$filename       = $this->collectionZipper->getZipFilename($collectionName);

		$this->assertEquals('collection-special-chars-collection_with-symbols.zip', $filename);
		$this->assertStringContainsString($collectionName, $filename);
	}

	public function testCreateCollectionZipWithValidCollection(): void
	{
		$collectionName = 'test-collection';
		$this->createTestCollection($collectionName);

		$zipPath = $this->collectionZipper->createCollectionZip($collectionName);

		$this->assertFileExists($zipPath);
		$this->assertStringEndsWith('.zip', $zipPath);
		$this->assertStringContainsString($collectionName, $zipPath);

		// Verify zip can be opened
		$zip    = new \ZipArchive();
		$result = $zip->open($zipPath);
		$this->assertTrue($result);

		// Check that zip contains files (structure may vary)
		$this->assertGreaterThan(0, $zip->numFiles);

		$zip->close();
		unlink($zipPath);
	}

	public function testCreateCollectionZipThrowsExceptionForNonexistentCollection(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Collection directory not found:');

		$this->collectionZipper->createCollectionZip('nonexistent-collection');
	}
}
