<?php

use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\FolderData;
use TotalCMS\Domain\Property\Service\DepotPropertyManager;

describe('DepotPropertyManager', function (): void {
	test('DepotPropertyManager → addFile adds file to root', function (): void {
		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);

		$file = new FileData(['name' => 'test.pdf', 'mime' => 'application/pdf', 'size' => 1024]);
		$manager->addFile($file);

		expect(count($depot->files))->toBe(1);
		expect($depot->files[0])->toBeInstanceOf(FileData::class);
		expect($depot->files[0]->name)->toBe('test.pdf');
	});

	test('DepotPropertyManager → addFile adds file to subpath', function (): void {
		$depotData = [
			'files' => [
				[
					'name'  => 'documents',
					'mime'  => 'folder',
					'files' => [],
				],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$file = new FileData(['name' => 'report.pdf', 'mime' => 'application/pdf', 'size' => 2048]);
		$manager->addFile($file, 'documents');

		expect(count($depot->files))->toBe(1);
		expect($depot->files[0])->toBeInstanceOf(FolderData::class);
		expect(count($depot->files[0]->files))->toBe(1);
		expect($depot->files[0]->files[0]->name)->toBe('report.pdf');
	});

	test('DepotPropertyManager → addFile creates folder if not exists', function (): void {
		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);

		$file = new FileData(['name' => 'doc.pdf', 'mime' => 'application/pdf', 'size' => 512]);
		$manager->addFile($file, 'new-folder');

		expect(count($depot->files))->toBe(1);
		expect($depot->files[0])->toBeInstanceOf(FolderData::class);
		expect($depot->files[0]->name)->toBe('new-folder');
		expect(count($depot->files[0]->files))->toBe(1);
		expect($depot->files[0]->files[0]->name)->toBe('doc.pdf');
	});

	test('DepotPropertyManager → addFile creates nested folders', function (): void {
		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);

		$file = new FileData(['name' => 'deep.txt', 'mime' => 'text/plain', 'size' => 100]);
		$manager->addFile($file, 'level1/level2/level3');

		expect(count($depot->files))->toBe(1);
		expect($depot->files[0]->name)->toBe('level1');

		$level1 = $depot->files[0];
		expect(count($level1->files))->toBe(1);
		expect($level1->files[0]->name)->toBe('level2');

		$level2 = $level1->files[0];
		expect(count($level2->files))->toBe(1);
		expect($level2->files[0]->name)->toBe('level3');

		$level3 = $level2->files[0];
		expect(count($level3->files))->toBe(1);
		expect($level3->files[0]->name)->toBe('deep.txt');
	});

	test('DepotPropertyManager → createFolder creates folder at root', function (): void {
		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);

		$manager->createFolder('images');

		expect(count($depot->files))->toBe(1);
		expect($depot->files[0])->toBeInstanceOf(FolderData::class);
		expect($depot->files[0]->name)->toBe('images');
	});

	test('DepotPropertyManager → createFolder creates nested path', function (): void {
		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);

		$manager->createFolder('documents/reports/2024');

		expect(count($depot->files))->toBe(1);
		expect($depot->files[0]->name)->toBe('documents');
		expect(count($depot->files[0]->files))->toBe(1);
		expect($depot->files[0]->files[0]->name)->toBe('reports');
		expect(count($depot->files[0]->files[0]->files))->toBe(1);
		expect($depot->files[0]->files[0]->files[0]->name)->toBe('2024');
	});

	test('DepotPropertyManager → createFolder uses existing folder', function (): void {
		$depotData = [
			'files' => [
				[
					'name'  => 'existing',
					'mime'  => 'folder',
					'files' => [
						['name' => 'file.txt', 'mime' => 'text/plain'],
					],
				],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$manager->createFolder('existing/new-subfolder');

		expect(count($depot->files))->toBe(1);
		expect($depot->files[0]->name)->toBe('existing');
		// Should have original file plus new subfolder
		expect(count($depot->files[0]->files))->toBe(2);
	});

	test('DepotPropertyManager → fetchFile retrieves file from root', function (): void {
		$depotData = [
			'files' => [
				['name' => 'readme.txt', 'mime' => 'text/plain', 'size' => 256],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$file = $manager->fetchFile('readme.txt');

		expect($file)->toBeInstanceOf(FileData::class);
		expect($file->name)->toBe('readme.txt');
		expect($file->size)->toBe(256);
	});

	test('DepotPropertyManager → fetchFile retrieves file from subfolder', function (): void {
		$depotData = [
			'files' => [
				[
					'name'  => 'docs',
					'mime'  => 'folder',
					'files' => [
						['name' => 'guide.pdf', 'mime' => 'application/pdf', 'size' => 4096],
					],
				],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$file = $manager->fetchFile('guide.pdf', 'docs');

		expect($file)->toBeInstanceOf(FileData::class);
		expect($file->name)->toBe('guide.pdf');
	});

	test('DepotPropertyManager → fetchFile returns null for non-existent file', function (): void {
		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);

		$file = $manager->fetchFile('missing.txt');

		expect($file)->toBeNull();
	});

	test('DepotPropertyManager → fetchFile searches recursively without subpath', function (): void {
		$depotData = [
			'files' => [
				[
					'name'  => 'folder1',
					'mime'  => 'folder',
					'files' => [
						[
							'name'  => 'folder2',
							'mime'  => 'folder',
							'files' => [
								['name' => 'deep-file.txt', 'mime' => 'text/plain', 'size' => 100],
							],
						],
					],
				],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		// Without subpath, should search recursively
		$file = $manager->fetchFile('deep-file.txt');

		expect($file)->toBeInstanceOf(FileData::class);
		expect($file->name)->toBe('deep-file.txt');
	});

	test('DepotPropertyManager → fileExists returns true for existing file', function (): void {
		$depotData = [
			'files' => [
				['name' => 'test.txt', 'mime' => 'text/plain'],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		expect($manager->fileExists('test.txt'))->toBe(true);
	});

	test('DepotPropertyManager → fileExists returns false for non-existent file', function (): void {
		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);

		expect($manager->fileExists('missing.txt'))->toBe(false);
	});

	test('DepotPropertyManager → fileExists checks specific subpath', function (): void {
		$depotData = [
			'files' => [
				[
					'name'  => 'folder',
					'mime'  => 'folder',
					'files' => [
						['name' => 'file.txt', 'mime' => 'text/plain'],
					],
				],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		expect($manager->fileExists('file.txt', 'folder'))->toBe(true);
		expect($manager->fileExists('file.txt', 'wrong-folder'))->toBe(false);
	});

	test('DepotPropertyManager → deleteFile removes file from root', function (): void {
		$depotData = [
			'files' => [
				['name' => 'delete-me.txt', 'mime' => 'text/plain'],
				['name' => 'keep-me.txt', 'mime' => 'text/plain'],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$manager->deleteFile('delete-me.txt');

		expect(count($depot->files))->toBe(1);
		expect($depot->files[1]->name)->toBe('keep-me.txt');
	});

	test('DepotPropertyManager → deleteFile removes file from subfolder', function (): void {
		$depotData = [
			'files' => [
				[
					'name'  => 'folder',
					'mime'  => 'folder',
					'files' => [
						['name' => 'file1.txt', 'mime' => 'text/plain'],
						['name' => 'file2.txt', 'mime' => 'text/plain'],
					],
				],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$manager->deleteFile('file1.txt', 'folder');

		expect(count($depot->files[0]->files))->toBe(1);
		expect($depot->files[0]->files[1]->name)->toBe('file2.txt');
	});

	test('DepotPropertyManager → moveFile moves file between folders', function (): void {
		$depotData = [
			'files' => [
				[
					'name'  => 'source',
					'mime'  => 'folder',
					'files' => [
						['name' => 'moveme.txt', 'mime' => 'text/plain', 'size' => 100],
					],
				],
				[
					'name'  => 'destination',
					'mime'  => 'folder',
					'files' => [],
				],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$manager->moveFile('moveme.txt', 'source', 'destination');

		// Source folder should be empty
		expect(count($depot->files[0]->files))->toBe(0);
		// Destination folder should have the file
		expect(count($depot->files[1]->files))->toBe(1);
		expect($depot->files[1]->files[0]->name)->toBe('moveme.txt');
	});

	test('DepotPropertyManager → moveFile creates destination folder if not exists', function (): void {
		$depotData = [
			'files' => [
				['name' => 'file.txt', 'mime' => 'text/plain', 'size' => 50],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$manager->moveFile('file.txt', '', 'new-folder');

		// Root should have one folder now (original file was removed, folder was added)
		// Note: unset() doesn't reindex, so we use array_values
		$files = array_values($depot->files);
		expect(count($files))->toBe(1);
		expect($files[0])->toBeInstanceOf(FolderData::class);
		expect($files[0]->name)->toBe('new-folder');
		expect(count($files[0]->files))->toBe(1);
		expect($files[0]->files[0]->name)->toBe('file.txt');
	});

	test('DepotPropertyManager → renameFolder renames folder at root', function (): void {
		$depotData = [
			'files' => [
				[
					'name'  => 'old-name',
					'mime'  => 'folder',
					'files' => [
						['name' => 'file.txt', 'mime' => 'text/plain'],
					],
				],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$manager->renameFolder('old-name', 'new-name');

		expect($depot->files[0]->name)->toBe('new-name');
		// Files inside should still exist
		expect(count($depot->files[0]->files))->toBe(1);
		expect($depot->files[0]->files[0]->name)->toBe('file.txt');
	});

	test('DepotPropertyManager → renameFolder renames nested folder', function (): void {
		$depotData = [
			'files' => [
				[
					'name'  => 'parent',
					'mime'  => 'folder',
					'files' => [
						[
							'name'  => 'child',
							'mime'  => 'folder',
							'files' => [
								['name' => 'nested.txt', 'mime' => 'text/plain'],
							],
						],
					],
				],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$manager->renameFolder('parent/child', 'renamed-child');

		expect($depot->files[0]->name)->toBe('parent');
		expect($depot->files[0]->files[0]->name)->toBe('renamed-child');
		expect($depot->files[0]->files[0]->files[0]->name)->toBe('nested.txt');
	});

	test('DepotPropertyManager → patchMeta updates file metadata', function (): void {
		$depotData = [
			'files' => [
				[
					'name'     => 'file.pdf',
					'mime'     => 'application/pdf',
					'size'     => 1000,
					'comments' => 'Original comments',
				],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$manager->patchMeta('file.pdf', ['comments' => 'Updated comments', 'download' => 'custom-name.pdf']);

		expect($depot->files[0]->comments)->toBe('Updated comments');
		expect($depot->files[0]->download)->toBe('custom-name.pdf');
		// Original fields should remain
		expect($depot->files[0]->size)->toBe(1000);
	});

	test('DepotPropertyManager → patchMeta updates file in subfolder', function (): void {
		$depotData = [
			'files' => [
				[
					'name'  => 'folder',
					'mime'  => 'folder',
					'files' => [
						['name' => 'doc.txt', 'mime' => 'text/plain', 'comments' => ''],
					],
				],
			],
		];
		$depot   = new DepotData($depotData);
		$manager = new DepotPropertyManager($depot);

		$manager->patchMeta('doc.txt', ['comments' => 'New comment'], 'folder');

		expect($depot->files[0]->files[0]->comments)->toBe('New comment');
	});

	test('DepotPropertyManager → handles empty path strings', function (): void {
		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);

		$file = new FileData(['name' => 'root.txt', 'mime' => 'text/plain']);
		$manager->addFile($file, '');

		expect(count($depot->files))->toBe(1);
		expect($depot->files[0]->name)->toBe('root.txt');
	});

	test('DepotPropertyManager → handles path with leading/trailing slashes', function (): void {
		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);

		$manager->createFolder('/folder/');

		expect(count($depot->files))->toBe(1);
		expect($depot->files[0]->name)->toBe('folder');
	});

	test('DepotPropertyManager → returns reference to depot', function (): void {
		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);

		$file   = new FileData(['name' => 'test.txt', 'mime' => 'text/plain']);
		$result = $manager->addFile($file);

		expect($result)->toBe($depot);
	});

	test('DepotPropertyManager → handles deeply nested operations', function (): void {
		$depot   = new DepotData();
		$manager = new DepotPropertyManager($depot);

		// Create a 5-level deep structure
		$file = new FileData(['name' => 'deep.txt', 'mime' => 'text/plain', 'size' => 10]);
		$manager->addFile($file, 'a/b/c/d/e');

		// Navigate to verify
		$current = $depot->files[0];
		expect($current->name)->toBe('a');

		$current = $current->files[0];
		expect($current->name)->toBe('b');

		$current = $current->files[0];
		expect($current->name)->toBe('c');

		$current = $current->files[0];
		expect($current->name)->toBe('d');

		$current = $current->files[0];
		expect($current->name)->toBe('e');

		expect($current->files[0]->name)->toBe('deep.txt');
	});
});
