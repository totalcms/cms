<?php

namespace Tests\Unit\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\FolderData;

#[CoversClass(FolderData::class)]
final class FolderDataTest extends TestCase
{
	public function testCreatesFolderWithNameAndFiles(): void
	{
		$files = [
			[
				'name' => 'document.pdf',
				'mime' => 'application/pdf',
				'size' => 1024000,
			],
			[
				'name' => 'image.jpg',
				'mime' => 'image/jpeg',
				'size' => 512000,
			],
		];

		$folder = new FolderData('my_folder', $files);

		$this->assertSame('my_folder', $folder->name);
		$this->assertCount(2, $folder->files);
		$this->assertInstanceOf(FileData::class, $folder->files[0]);
		$this->assertInstanceOf(FileData::class, $folder->files[1]);
		$this->assertSame('document.pdf', $folder->files[0]->name);
		$this->assertSame('image.jpg', $folder->files[1]->name);
	}

	public function testCreatesEmptyFolder(): void
	{
		$folder = new FolderData('empty_folder');

		$this->assertSame('empty_folder', $folder->name);
		$this->assertSame([], $folder->files);
	}

	public function testBuildsNestedFolderStructure(): void
	{
		$nestedStructure = [
			[
				'name' => 'file1.txt',
				'mime' => 'text/plain',
				'size' => 100,
			],
			[
				'name'  => 'subfolder',
				'mime'  => 'folder',
				'files' => [
					[
						'name' => 'nested_file.doc',
						'mime' => 'application/msword',
						'size' => 2000,
					],
					[
						'name'  => 'deep_folder',
						'mime'  => 'folder',
						'files' => [
							[
								'name' => 'deep_file.pdf',
								'mime' => 'application/pdf',
								'size' => 5000,
							],
						],
					],
				],
			],
		];

		$folder = new FolderData('root', $nestedStructure);

		$this->assertSame('root', $folder->name);
		$this->assertCount(2, $folder->files);

		// First item should be a file
		$this->assertInstanceOf(FileData::class, $folder->files[0]);
		$this->assertSame('file1.txt', $folder->files[0]->name);

		// Second item should be a folder
		$this->assertInstanceOf(FolderData::class, $folder->files[1]);
		$subfolder = $folder->files[1];
		$this->assertSame('subfolder', $subfolder->name);
		$this->assertCount(2, $subfolder->files);

		// Check nested structure
		$this->assertInstanceOf(FileData::class, $subfolder->files[0]);
		$this->assertInstanceOf(FolderData::class, $subfolder->files[1]);

		$deepFolder = $subfolder->files[1];
		$this->assertSame('deep_folder', $deepFolder->name);
		$this->assertCount(1, $deepFolder->files);
		$this->assertInstanceOf(FileData::class, $deepFolder->files[0]);
		$this->assertSame('deep_file.pdf', $deepFolder->files[0]->name);
	}

	public function testBuildFolderStaticMethod(): void
	{
		$files = [
			[
				'name' => 'standalone.txt',
				'mime' => 'text/plain',
				'size' => 200,
			],
			[
				'name'  => 'images',
				'mime'  => 'folder',
				'files' => [
					[
						'name' => 'photo.jpg',
						'mime' => 'image/jpeg',
						'size' => 1000000,
					],
				],
			],
		];

		$builtFiles = FolderData::buildFolder($files);

		$this->assertCount(2, $builtFiles);
		$this->assertInstanceOf(FileData::class, $builtFiles[0]);
		$this->assertInstanceOf(FolderData::class, $builtFiles[1]);
		$this->assertSame('standalone.txt', $builtFiles[0]->name);
		$this->assertSame('images', $builtFiles[1]->name);
	}

	public function testSkipsItemsWithoutMime(): void
	{
		$mixedFiles = [
			[
				'name' => 'valid.txt',
				'mime' => 'text/plain',
				'size' => 100,
			],
			[
				'name' => 'invalid.txt',
				'size' => 200,
				// Missing 'mime' key
			],
			[
				'name'  => 'valid_folder',
				'mime'  => 'folder',
				'files' => [],
			],
			[
				'name' => 'another_invalid',
				// Missing 'mime' key entirely
			],
		];

		$folder = new FolderData('test_folder', $mixedFiles);

		// Only items with mime should be included
		$this->assertCount(2, $folder->files);
		$this->assertInstanceOf(FileData::class, $folder->files[0]);
		$this->assertInstanceOf(FolderData::class, $folder->files[1]);
		$this->assertSame('valid.txt', $folder->files[0]->name);
		$this->assertSame('valid_folder', $folder->files[1]->name);
	}

	public function testTransformsCorrectly(): void
	{
		$files = [
			[
				'name' => 'transform_test.txt',
				'mime' => 'text/plain',
				'size' => 500,
			],
		];

		$folder      = new FolderData('transform_folder', $files);
		$transformed = $folder->transform();

		$this->assertIsArray($transformed);
		$this->assertArrayHasKey('name', $transformed);
		$this->assertArrayHasKey('mime', $transformed);
		$this->assertArrayHasKey('files', $transformed);

		$this->assertSame('transform_folder', $transformed['name']);
		$this->assertSame('folder', $transformed['mime']);
		$this->assertIsArray($transformed['files']);
		$this->assertCount(1, $transformed['files']);

		// Check that nested files are also transformed
		$this->assertIsArray($transformed['files'][0]);
		$this->assertSame('transform_test.txt', $transformed['files'][0]['name']);
	}

	public function testHandlesDangerousFolderNames(): void
	{
		$dangerousNames = [
			'../../../etc',
			'<script>alert("xss")</script>',
			'..\\..\\..\\windows\\system32',
			'javascript:void(0)',
			'${system("ls")}',
		];

		foreach ($dangerousNames as $name) {
			$folder = new FolderData($name);
			// FolderData stores names as-is, validation should happen elsewhere
			$this->assertSame($name, $folder->name);
		}
	}

	public function testHandlesDangerousFilesInFolder(): void
	{
		$dangerousFiles = [
			[
				'name' => '../../../etc/passwd',
				'mime' => 'text/plain',
				'size' => 1000,
			],
			[
				'name' => '<script>alert("folder_xss")</script>.txt',
				'mime' => 'text/plain',
				'size' => 500,
			],
			[
				'name'  => 'dangerous_folder',
				'mime'  => 'folder',
				'files' => [
					[
						'name' => 'malicious.exe',
						'mime' => 'application/x-executable',
						'size' => 1000000,
					],
				],
			],
		];

		$folder = new FolderData('danger_zone', $dangerousFiles);

		$this->assertCount(3, $folder->files);
		$this->assertStringContainsString('../../../etc/passwd', $folder->files[0]->name);
		$this->assertStringContainsString('<script>', $folder->files[1]->name);
		$this->assertInstanceOf(FolderData::class, $folder->files[2]);
	}

	public function testHandlesLargeFolderStructures(): void
	{
		$largeStructure = [];

		// Create 200 files
		for ($i = 0; $i < 200; $i++) {
			$largeStructure[] = [
				'name' => "file_{$i}.txt",
				'mime' => 'text/plain',
				'size' => $i * 100,
			];
		}

		// Create 20 nested folders
		for ($f = 0; $f < 20; $f++) {
			$nestedFiles = [];
			for ($n = 0; $n < 10; $n++) {
				$nestedFiles[] = [
					'name' => "nested_{$f}_{$n}.doc",
					'mime' => 'application/msword',
					'size' => $n * 1000,
				];
			}

			$largeStructure[] = [
				'name'  => "folder_{$f}",
				'mime'  => 'folder',
				'files' => $nestedFiles,
			];
		}

		$folder = new FolderData('large_folder', $largeStructure);

		$this->assertCount(220, $folder->files); // 200 files + 20 folders

		// Verify folder structure
		$folderCount = 0;
		foreach ($folder->files as $item) {
			if ($item instanceof FolderData) {
				$this->assertCount(10, $item->files);
				$folderCount++;
			}
		}
		$this->assertSame(20, $folderCount);
	}

	public function testHandlesUnicodeFolderNames(): void
	{
		$unicodeNames = [
			'папка', // Russian
			'文件夹', // Chinese
			'フォルダー', // Japanese
			'مجلد', // Arabic
			'φάκελος', // Greek
		];

		foreach ($unicodeNames as $name) {
			$folder = new FolderData($name);
			$this->assertSame($name, $folder->name);
		}
	}

	public function testHandlesUnicodeFilesInFolder(): void
	{
		$unicodeFiles = [
			[
				'name' => 'файл.txt', // Russian
				'mime' => 'text/plain',
				'size' => 100,
			],
			[
				'name' => 'ファイル.doc', // Japanese
				'mime' => 'application/msword',
				'size' => 200,
			],
		];

		$folder = new FolderData('unicode_folder', $unicodeFiles);

		$this->assertCount(2, $folder->files);
		$this->assertStringContainsString('файл', $folder->files[0]->name);
		$this->assertStringContainsString('ファイル', $folder->files[1]->name);
	}

	public function testBuildFolderPerformance(): void
	{
		// Test that buildFolder method performs well with large datasets
		$largeFileList = [];
		for ($i = 0; $i < 500; $i++) {
			$largeFileList[] = [
				'name' => "performance_test_{$i}.txt",
				'mime' => 'text/plain',
				'size' => $i,
			];
		}

		$start      = microtime(true);
		$builtFiles = FolderData::buildFolder($largeFileList);
		$time       = microtime(true) - $start;

		$this->assertLessThan(0.1, $time); // Should complete in under 100ms
		$this->assertCount(500, $builtFiles);
		$this->assertInstanceOf(FileData::class, $builtFiles[0]);
		$this->assertInstanceOf(FileData::class, $builtFiles[499]);
	}

	public function testTransformationPreservesStructure(): void
	{
		$files = [
			[
				'name'     => 'structure_test.txt',
				'mime'     => 'text/plain',
				'size'     => 1000,
				'comments' => 'Test file for structure preservation',
			],
			[
				'name'  => 'nested_structure',
				'mime'  => 'folder',
				'files' => [
					[
						'name' => 'inner.pdf',
						'mime' => 'application/pdf',
						'size' => 5000,
					],
				],
			],
		];

		$folder      = new FolderData('structure_folder', $files);
		$transformed = $folder->transform();

		$this->assertSame('structure_folder', $transformed['name']);
		$this->assertSame('folder', $transformed['mime']);
		$this->assertCount(2, $transformed['files']);

		// Check first file transformation
		$firstFile = $transformed['files'][0];
		$this->assertIsArray($firstFile);
		$this->assertSame('structure_test.txt', $firstFile['name']);
		$this->assertSame('text/plain', $firstFile['mime']);
		$this->assertSame(1000, $firstFile['size']);

		// Check nested folder transformation
		$nestedFolder = $transformed['files'][1];
		$this->assertIsArray($nestedFolder);
		$this->assertSame('nested_structure', $nestedFolder['name']);
		$this->assertSame('folder', $nestedFolder['mime']);
		$this->assertCount(1, $nestedFolder['files']);
		$this->assertSame('inner.pdf', $nestedFolder['files'][0]['name']);
	}
}
