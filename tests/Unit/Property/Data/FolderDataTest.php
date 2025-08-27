<?php

use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\FolderData;

describe('FolderData', function (): void {
	test('FolderData → creates with name and empty files', function (): void {
		$folder = new FolderData('my-folder');

		expect($folder->name)->toBe('my-folder');
		expect($folder->files)->toBe([]);
	});

	test('FolderData → creates with files array', function (): void {
		$filesData = [
			[
				'name' => 'document.pdf',
				'mime' => 'application/pdf',
				'size' => 1024,
			],
			[
				'name' => 'image.jpg',
				'mime' => 'image/jpeg',
				'size' => 2048,
			],
		];

		$folder = new FolderData('documents', $filesData);

		expect($folder->name)->toBe('documents');
		expect(count($folder->files))->toBe(2);
		expect($folder->files[0])->toBeInstanceOf(FileData::class);
		expect($folder->files[1])->toBeInstanceOf(FileData::class);
		expect($folder->files[0]->name)->toBe('document.pdf');
		expect($folder->files[1]->name)->toBe('image.jpg');
	});

	test('FolderData → buildFolder creates FileData instances', function (): void {
		$filesData = [
			[
				'name' => 'test.txt',
				'mime' => 'text/plain',
				'size' => 500,
			],
			[
				'name' => 'photo.png',
				'mime' => 'image/png',
				'size' => 1500,
			],
		];

		$files = FolderData::buildFolder($filesData);

		expect(count($files))->toBe(2);
		expect($files[0])->toBeInstanceOf(FileData::class);
		expect($files[1])->toBeInstanceOf(FileData::class);
		expect($files[0]->name)->toBe('test.txt');
		expect($files[1]->name)->toBe('photo.png');
	});

	test('FolderData → buildFolder creates nested FolderData instances', function (): void {
		$filesData = [
			[
				'name' => 'file1.txt',
				'mime' => 'text/plain',
			],
			[
				'name'  => 'subfolder',
				'mime'  => 'folder',
				'files' => [
					[
						'name' => 'nested-file.jpg',
						'mime' => 'image/jpeg',
					],
				],
			],
		];

		$files = FolderData::buildFolder($filesData);

		expect(count($files))->toBe(2);
		expect($files[0])->toBeInstanceOf(FileData::class);
		expect($files[1])->toBeInstanceOf(FolderData::class);
		expect($files[0]->name)->toBe('file1.txt');
		expect($files[1]->name)->toBe('subfolder');
		expect(count($files[1]->files))->toBe(1);
		expect($files[1]->files[0])->toBeInstanceOf(FileData::class);
		expect($files[1]->files[0]->name)->toBe('nested-file.jpg');
	});

	test('FolderData → buildFolder skips items without mime type', function (): void {
		$filesData = [
			[
				'name' => 'valid-file.txt',
				'mime' => 'text/plain',
			],
			[
				'name' => 'invalid-item',
				// No mime field
			],
			[
				'name' => 'another-valid.jpg',
				'mime' => 'image/jpeg',
			],
		];

		$files = FolderData::buildFolder($filesData);

		expect(count($files))->toBe(2);
		expect($files[0]->name)->toBe('valid-file.txt');
		expect($files[1]->name)->toBe('another-valid.jpg');
	});

	test('FolderData → buildFolder handles empty array', function (): void {
		$files = FolderData::buildFolder([]);

		expect($files)->toBe([]);
	});

	test('FolderData → buildFolder handles mixed content types', function (): void {
		$filesData = [
			[
				'name' => 'document.pdf',
				'mime' => 'application/pdf',
				'size' => 2048,
			],
			[
				'name'  => 'images',
				'mime'  => 'folder',
				'files' => [
					[
						'name' => 'photo1.jpg',
						'mime' => 'image/jpeg',
					],
					[
						'name' => 'photo2.png',
						'mime' => 'image/png',
					],
				],
			],
			[
				'name' => 'video.mp4',
				'mime' => 'video/mp4',
				'size' => 10240,
			],
		];

		$files = FolderData::buildFolder($filesData);

		expect(count($files))->toBe(3);
		expect($files[0])->toBeInstanceOf(FileData::class);
		expect($files[1])->toBeInstanceOf(FolderData::class);
		expect($files[2])->toBeInstanceOf(FileData::class);

		// Check the nested folder
		$imagesFolder = $files[1];
		expect($imagesFolder->name)->toBe('images');
		expect(count($imagesFolder->files))->toBe(2);
		expect($imagesFolder->files[0]->name)->toBe('photo1.jpg');
		expect($imagesFolder->files[1]->name)->toBe('photo2.png');
	});

	test('FolderData → transform returns correct structure', function (): void {
		$filesData = [
			[
				'name' => 'test.txt',
				'mime' => 'text/plain',
				'size' => 100,
			],
		];

		$folder = new FolderData('test-folder', $filesData);
		$result = $folder->transform();

		expect($result)->toHaveKey('name', 'test-folder');
		expect($result)->toHaveKey('mime', 'folder');
		expect($result)->toHaveKey('files');
		expect($result['files'])->toBeArray();
		expect(count($result['files']))->toBe(1);
		expect($result['files'][0])->toHaveKey('name', 'test.txt');
	});

	test('FolderData → transform handles nested structure', function (): void {
		$filesData = [
			[
				'name' => 'file.txt',
				'mime' => 'text/plain',
			],
			[
				'name'  => 'subfolder',
				'mime'  => 'folder',
				'files' => [
					[
						'name' => 'nested.jpg',
						'mime' => 'image/jpeg',
					],
				],
			],
		];

		$folder = new FolderData('root', $filesData);
		$result = $folder->transform();

		expect($result['name'])->toBe('root');
		expect($result['mime'])->toBe('folder');
		expect(count($result['files']))->toBe(2);

		// Check nested folder transformation
		$nestedFolder = $result['files'][1];
		expect($nestedFolder['name'])->toBe('subfolder');
		expect($nestedFolder['mime'])->toBe('folder');
		expect(count($nestedFolder['files']))->toBe(1);
		expect($nestedFolder['files'][0]['name'])->toBe('nested.jpg');
	});

	test('FolderData → transform handles empty folder', function (): void {
		$folder = new FolderData('empty-folder');
		$result = $folder->transform();

		expect($result)->toHaveKey('name', 'empty-folder');
		expect($result)->toHaveKey('mime', 'folder');
		expect($result)->toHaveKey('files', []);
	});

	test('FolderData → handles complex nested structure', function (): void {
		$complexStructure = [
			[
				'name' => 'root-file.txt',
				'mime' => 'text/plain',
			],
			[
				'name'  => 'level1',
				'mime'  => 'folder',
				'files' => [
					[
						'name' => 'level1-file.pdf',
						'mime' => 'application/pdf',
					],
					[
						'name'  => 'level2',
						'mime'  => 'folder',
						'files' => [
							[
								'name' => 'deep-file.jpg',
								'mime' => 'image/jpeg',
							],
						],
					],
				],
			],
		];

		$folder = new FolderData('root', $complexStructure);

		expect(count($folder->files))->toBe(2);
		expect($folder->files[0]->name)->toBe('root-file.txt');
		expect($folder->files[1]->name)->toBe('level1');

		$level1Folder = $folder->files[1];
		expect(count($level1Folder->files))->toBe(2);
		expect($level1Folder->files[0]->name)->toBe('level1-file.pdf');
		expect($level1Folder->files[1]->name)->toBe('level2');

		$level2Folder = $level1Folder->files[1];
		expect(count($level2Folder->files))->toBe(1);
		expect($level2Folder->files[0]->name)->toBe('deep-file.jpg');
	});

	test('FolderData → buildFolder is static and reusable', function (): void {
		$filesData1 = [
			['name' => 'file1.txt', 'mime' => 'text/plain'],
		];
		$filesData2 = [
			['name' => 'file2.jpg', 'mime' => 'image/jpeg'],
		];

		$files1 = FolderData::buildFolder($filesData1);
		$files2 = FolderData::buildFolder($filesData2);

		expect(count($files1))->toBe(1);
		expect(count($files2))->toBe(1);
		expect($files1[0]->name)->toBe('file1.txt');
		expect($files2[0]->name)->toBe('file2.jpg');
	});

	test('FolderData → handles various folder names', function (): void {
		$folderNames = [
			'simple',
			'with-dashes',
			'with_underscores',
			'with spaces',
			'with.dots',
			'123-numeric',
		];

		foreach ($folderNames as $name) {
			$folder = new FolderData($name);
			expect($folder->name)->toBe($name);
			expect($folder->transform()['name'])->toBe($name);
		}
	});
});
