<?php

use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\PasswordData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\FolderData;

describe('DepotData', function (): void {
	test('DepotData → creates with empty depot', function (): void {
		$depot = new DepotData();
		
		expect($depot->depot)->toBe([]);
		expect($depot->settings)->toBe([]);
		expect($depot->protected)->toBe(true); // Default
		expect($depot->password)->toBeInstanceOf(PasswordData::class);
		expect($depot->files)->toBe([]);
	});

	test('DepotData → creates with settings', function (): void {
		$settings = ['maxSize' => 1000000, 'allowedTypes' => ['pdf', 'jpg']];
		$depot = new DepotData([], $settings);
		
		expect($depot->settings)->toBe($settings);
	});

	test('DepotData → creates with depot data', function (): void {
		$depotData = [
			'protected' => false,
			'password' => 'secret123',
			'files' => [
				[
					'name' => 'document.pdf',
					'mime' => 'application/pdf',
					'size' => 1024,
				],
				[
					'name' => 'images',
					'mime' => 'folder',
					'files' => [
						[
							'name' => 'photo.jpg',
							'mime' => 'image/jpeg',
							'size' => 2048,
						],
					],
				],
			],
		];
		
		$depot = new DepotData($depotData);
		
		expect($depot->protected)->toBe(false);
		expect($depot->password)->toBeInstanceOf(PasswordData::class);
		expect(count($depot->files))->toBe(2);
		expect($depot->files[0])->toBeInstanceOf(FileData::class);
		expect($depot->files[1])->toBeInstanceOf(FolderData::class);
	});

	test('DepotData → uses default values for missing fields', function (): void {
		$depotData = [
			// Only some fields provided
			'protected' => false,
		];
		
		$depot = new DepotData($depotData);
		
		expect($depot->protected)->toBe(false);
		expect($depot->password)->toBeInstanceOf(PasswordData::class);
		expect($depot->files)->toBe([]); // Empty files array
	});

	test('DepotData → handles empty password', function (): void {
		$depotData = [
			'password' => '',
		];
		
		$depot = new DepotData($depotData);
		
		expect($depot->password)->toBeInstanceOf(PasswordData::class);
		// Empty password should result in empty hash
		expect($depot->password->hash)->toBe('');
	});

	test('DepotData → processes password through PasswordData', function (): void {
		$depotData = [
			'password' => 'mypassword',
		];
		
		$depot = new DepotData($depotData);
		
		expect($depot->password)->toBeInstanceOf(PasswordData::class);
		// Password should be hashed
		expect($depot->password->hash)->not->toBe('mypassword');
		expect($depot->password->hash)->not->toBe('');
	});

	test('DepotData → processes files through FolderData::buildFolder', function (): void {
		$depotData = [
			'files' => [
				[
					'name' => 'test.txt',
					'mime' => 'text/plain',
				],
				[
					'name' => 'invalid-item',
					// No mime - should be skipped
				],
				[
					'name' => 'subfolder',
					'mime' => 'folder',
					'files' => [
						[
							'name' => 'nested.jpg',
							'mime' => 'image/jpeg',
						],
					],
				],
			],
		];
		
		$depot = new DepotData($depotData);
		
		// Should skip invalid item, resulting in 2 files
		expect(count($depot->files))->toBe(2);
		expect($depot->files[0])->toBeInstanceOf(FileData::class);
		expect($depot->files[1])->toBeInstanceOf(FolderData::class);
		expect($depot->files[0]->name)->toBe('test.txt');
		expect($depot->files[1]->name)->toBe('subfolder');
	});

	test('DepotData → transform returns correct structure', function (): void {
		$depotData = [
			'protected' => false,
			'password' => 'secret',
			'files' => [
				[
					'name' => 'file.txt',
					'mime' => 'text/plain',
					'size' => 500,
				],
			],
		];
		
		$depot = new DepotData($depotData);
		$result = $depot->transform();
		
		expect($result)->toHaveKey('protected', false);
		expect($result)->toHaveKey('password'); // Hash value
		expect($result)->toHaveKey('files');
		expect($result['files'])->toBeArray();
		expect(count($result['files']))->toBe(1);
		expect($result['files'][0])->toHaveKey('name', 'file.txt');
	});

	test('DepotData → transform handles empty files', function (): void {
		$depot = new DepotData(['protected' => true, 'password' => 'test']);
		$result = $depot->transform();
		
		expect($result['protected'])->toBe(true);
		expect($result['files'])->toBe([]);
	});

	test('DepotData → transform includes nested file structure', function (): void {
		$depotData = [
			'files' => [
				[
					'name' => 'root-file.txt',
					'mime' => 'text/plain',
				],
				[
					'name' => 'folder',
					'mime' => 'folder',
					'files' => [
						[
							'name' => 'nested.pdf',
							'mime' => 'application/pdf',
						],
					],
				],
			],
		];
		
		$depot = new DepotData($depotData);
		$result = $depot->transform();
		
		expect(count($result['files']))->toBe(2);
		expect($result['files'][0]['name'])->toBe('root-file.txt');
		expect($result['files'][1]['name'])->toBe('folder');
		expect($result['files'][1]['mime'])->toBe('folder');
		expect(count($result['files'][1]['files']))->toBe(1);
		expect($result['files'][1]['files'][0]['name'])->toBe('nested.pdf');
	});

	test('DepotData → __toString converts to JSON', function (): void {
		$depotData = [
			'protected' => false,
			'password' => 'secret',
			'files' => [
				[
					'name' => 'document.txt',
					'mime' => 'text/plain',
					'size' => 1000,
				],
			],
		];
		
		$depot = new DepotData($depotData);
		$json = (string)$depot;
		
		expect($json)->toBeString();
		expect($json)->not->toBe('');
		
		$decoded = json_decode($json, true);
		expect($decoded)->toBeArray();
		expect($decoded['protected'])->toBe(false);
		expect($decoded)->toHaveKey('password');
		expect($decoded)->toHaveKey('files');
		expect(count($decoded['files']))->toBe(1);
	});

	test('DepotData → handles boolean protected variations', function (): void {
		$protectedDepot = new DepotData(['protected' => true]);
		$unprotectedDepot = new DepotData(['protected' => false]);
		$defaultDepot = new DepotData(); // Should default to true
		
		expect($protectedDepot->protected)->toBe(true);
		expect($unprotectedDepot->protected)->toBe(false);
		expect($defaultDepot->protected)->toBe(true);
	});

	test('DepotData → handles complex depot structure', function (): void {
		$complexDepot = [
			'protected' => true,
			'password' => 'complex123',
			'files' => [
				[
					'name' => 'readme.txt',
					'mime' => 'text/plain',
					'size' => 500,
					'comments' => 'Important instructions',
				],
				[
					'name' => 'media',
					'mime' => 'folder',
					'files' => [
						[
							'name' => 'banner.jpg',
							'mime' => 'image/jpeg',
							'size' => 15000,
						],
						[
							'name' => 'videos',
							'mime' => 'folder',
							'files' => [
								[
									'name' => 'intro.mp4',
									'mime' => 'video/mp4',
									'size' => 500000,
								],
							],
						],
					],
				],
				[
					'name' => 'archive.zip',
					'mime' => 'application/zip',
					'size' => 1024000,
					'protected' => true,
					'password' => 'archive-secret',
				],
			],
		];
		
		$depot = new DepotData($complexDepot);
		
		expect($depot->protected)->toBe(true);
		expect(count($depot->files))->toBe(3);
		
		// Check file types
		expect($depot->files[0])->toBeInstanceOf(FileData::class);
		expect($depot->files[1])->toBeInstanceOf(FolderData::class);
		expect($depot->files[2])->toBeInstanceOf(FileData::class);
		
		// Check nested structure
		$mediaFolder = $depot->files[1];
		expect(count($mediaFolder->files))->toBe(2);
		expect($mediaFolder->files[0])->toBeInstanceOf(FileData::class);
		expect($mediaFolder->files[1])->toBeInstanceOf(FolderData::class);
		
		$videosFolder = $mediaFolder->files[1];
		expect(count($videosFolder->files))->toBe(1);
		expect($videosFolder->files[0]->name)->toBe('intro.mp4');
	});

	test('DepotData → transform maps files correctly', function (): void {
		$depotData = [
			'files' => [
				[
					'name' => 'file1.txt',
					'mime' => 'text/plain',
				],
				[
					'name' => 'file2.jpg',
					'mime' => 'image/jpeg',
				],
			],
		];
		
		$depot = new DepotData($depotData);
		$result = $depot->transform();
		
		// Each file should have been transformed through its transform() method
		expect(count($result['files']))->toBe(2);
		foreach ($result['files'] as $fileResult) {
			expect($fileResult)->toHaveKey('name');
			expect($fileResult)->toHaveKey('mime');
			expect($fileResult)->toHaveKey('protected'); // FileData adds this
		}
	});
});