<?php

namespace Tests\Unit\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\FolderData;
use TotalCMS\Domain\Property\Data\PasswordData;

#[CoversClass(DepotData::class)]
final class DepotDataTest extends TestCase
{
	public function testCreatesDepotWithAllProperties(): void
	{
		$depotConfig = [
			'protected' => true,
			'password'  => 'secret123',
			'files'     => [
				[
					'name' => 'document.pdf',
					'mime' => 'application/pdf',
					'size' => 1024000,
				],
				[
					'name'  => 'images',
					'mime'  => 'folder',
					'files' => [
						[
							'name' => 'photo.jpg',
							'mime' => 'image/jpeg',
							'size' => 512000,
						],
					],
				],
			],
		];

		$depot = new DepotData($depotConfig);

		$this->assertTrue($depot->protected);
		$this->assertInstanceOf(PasswordData::class, $depot->password);
		$this->assertTrue(password_verify('secret123', $depot->password->hash));
		$this->assertIsArray($depot->files);
		$this->assertCount(2, $depot->files);
		$this->assertInstanceOf(FileData::class, $depot->files[0]);
		$this->assertInstanceOf(FolderData::class, $depot->files[1]);
	}

	public function testUsesDefaultsForMissingProperties(): void
	{
		$depot = new DepotData();

		$this->assertTrue($depot->protected); // Default is protected
		$this->assertInstanceOf(PasswordData::class, $depot->password);
		$this->assertSame('', $depot->password->hash); // Empty password
		$this->assertSame([], $depot->files); // Empty files array
	}

	public function testHandlesMixedFileAndFolderStructure(): void
	{
		$depotConfig = [
			'files' => [
				// Regular file
				[
					'name' => 'readme.txt',
					'mime' => 'text/plain',
					'size' => 1024,
				],
				// Folder with nested structure
				[
					'name'  => 'documents',
					'mime'  => 'folder',
					'files' => [
						[
							'name' => 'report.docx',
							'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
							'size' => 512000,
						],
						[
							'name'  => 'archived',
							'mime'  => 'folder',
							'files' => [
								[
									'name' => 'old_report.doc',
									'mime' => 'application/msword',
									'size' => 256000,
								],
							],
						],
					],
				],
				// Another regular file
				[
					'name' => 'config.json',
					'mime' => 'application/json',
					'size' => 2048,
				],
			],
		];

		$depot = new DepotData($depotConfig);

		$this->assertCount(3, $depot->files);
		$this->assertInstanceOf(FileData::class, $depot->files[0]); // readme.txt
		$this->assertInstanceOf(FolderData::class, $depot->files[1]); // documents folder
		$this->assertInstanceOf(FileData::class, $depot->files[2]); // config.json

		// Check folder structure
		$documentsFolder = $depot->files[1];
		$this->assertSame('documents', $documentsFolder->name);
		$this->assertCount(2, $documentsFolder->files);
		$this->assertInstanceOf(FileData::class, $documentsFolder->files[0]); // report.docx
		$this->assertInstanceOf(FolderData::class, $documentsFolder->files[1]); // archived folder
	}

	public function testTransformsCorrectly(): void
	{
		$depotConfig = [
			'protected' => false,
			'password'  => 'test123',
			'files'     => [
				[
					'name' => 'test.txt',
					'mime' => 'text/plain',
					'size' => 100,
				],
			],
		];

		$depot       = new DepotData($depotConfig);
		$transformed = $depot->transform();

		$this->assertIsArray($transformed);
		$this->assertArrayHasKey('protected', $transformed);
		$this->assertArrayHasKey('password', $transformed);
		$this->assertArrayHasKey('files', $transformed);

		$this->assertFalse($transformed['protected']);
		$this->assertIsString($transformed['password']); // Password hash
		$this->assertIsArray($transformed['files']);
		$this->assertCount(1, $transformed['files']);
	}

	public function testSerializesToJsonCorrectly(): void
	{
		$depotConfig = [
			'protected' => true,
			'files'     => [
				[
					'name' => 'serialize_test.txt',
					'mime' => 'text/plain',
					'size' => 50,
				],
			],
		];

		$depot = new DepotData($depotConfig);
		$json  = (string)$depot;

		$this->assertIsString($json);
		$this->assertStringContainsString('"protected":true', $json);
		$this->assertStringContainsString('"serialize_test.txt"', $json);

		$decoded = json_decode($json, true);
		$this->assertIsArray($decoded);
		$this->assertArrayHasKey('protected', $decoded);
		$this->assertArrayHasKey('files', $decoded);
	}

	public function testHandlesDangerousFilenames(): void
	{
		$dangerousFiles = [
			[
				'name' => '../../../etc/passwd',
				'mime' => 'text/plain',
				'size' => 1000,
			],
			[
				'name' => '<script>alert("xss")</script>.txt',
				'mime' => 'text/plain',
				'size' => 500,
			],
			[
				'name'  => 'evil_folder',
				'mime'  => 'folder',
				'files' => [
					[
						'name' => '..\\..\\..\\windows\\system32\\hosts',
						'mime' => 'text/plain',
						'size' => 2000,
					],
				],
			],
		];

		$depot = new DepotData(['files' => $dangerousFiles]);

		$this->assertCount(3, $depot->files);
		// DepotData stores filenames as-is, validation should happen elsewhere
		$this->assertStringContainsString('../../../etc/passwd', $depot->files[0]->name);
		$this->assertStringContainsString('<script>', $depot->files[1]->name);
		$this->assertInstanceOf(FolderData::class, $depot->files[2]);
	}

	public function testHandlesDangerousMimeTypes(): void
	{
		$dangerousFiles = [
			[
				'name' => 'executable.exe',
				'mime' => 'application/x-executable',
				'size' => 1000000,
			],
			[
				'name' => 'script.php',
				'mime' => 'application/x-php',
				'size' => 5000,
			],
			[
				'name' => 'page.html',
				'mime' => 'text/html',
				'size' => 3000,
			],
		];

		$depot = new DepotData(['files' => $dangerousFiles]);

		$this->assertCount(3, $depot->files);
		$this->assertSame('application/x-executable', $depot->files[0]->mime);
		$this->assertSame('application/x-php', $depot->files[1]->mime);
		$this->assertSame('text/html', $depot->files[2]->mime);
	}

	public function testPasswordSecurity(): void
	{
		$depot = new DepotData(['password' => 'securePassword123']);

		$this->assertNotSame('securePassword123', $depot->password->hash);
		$this->assertStringStartsWith('$2y$', $depot->password->hash);
		$this->assertTrue(password_verify('securePassword123', $depot->password->hash));

		// Test transformation doesn't expose plain password
		$transformed = $depot->transform();
		$this->assertNotSame('securePassword123', $transformed['password']);
	}

	public function testHandlesEmptyPassword(): void
	{
		$depot = new DepotData(['password' => '']);

		$this->assertSame('', $depot->password->hash);
	}

	public function testFilesWithoutMimeAreSkipped(): void
	{
		$mixedFiles = [
			[
				'name' => 'valid.txt',
				'mime' => 'text/plain',
				'size' => 100,
			],
			[
				'name' => 'invalid_no_mime.txt',
				'size' => 200,
				// Missing 'mime' key
			],
			[
				'name' => 'another_valid.pdf',
				'mime' => 'application/pdf',
				'size' => 300,
			],
		];

		$depot = new DepotData(['files' => $mixedFiles]);

		// Only files with MIME types should be included
		$this->assertCount(2, $depot->files);
		$this->assertSame('valid.txt', $depot->files[0]->name);
		$this->assertSame('another_valid.pdf', $depot->files[1]->name);
	}

	public function testHandlesLargeFileStructures(): void
	{
		$largeStructure = [];

		// Create 100 files and 10 folders with nested content
		for ($i = 0; $i < 100; $i++) {
			$largeStructure[] = [
				'name' => "file_{$i}.txt",
				'mime' => 'text/plain',
				'size' => $i * 1000,
			];
		}

		for ($f = 0; $f < 10; $f++) {
			$folderFiles = [];
			for ($i = 0; $i < 20; $i++) {
				$folderFiles[] = [
					'name' => "folder_{$f}_file_{$i}.doc",
					'mime' => 'application/msword',
					'size' => $i * 500,
				];
			}

			$largeStructure[] = [
				'name'  => "folder_{$f}",
				'mime'  => 'folder',
				'files' => $folderFiles,
			];
		}

		$depot = new DepotData(['files' => $largeStructure]);

		$this->assertCount(110, $depot->files); // 100 files + 10 folders

		// Check that folders contain correct number of files
		$folderCount = 0;
		foreach ($depot->files as $item) {
			if ($item instanceof FolderData) {
				$this->assertCount(20, $item->files);
				$folderCount++;
			}
		}
		$this->assertSame(10, $folderCount);
	}

	public function testAcceptsSettingsParameter(): void
	{
		$settings = ['maxSize' => 1048576, 'allowedTypes' => ['pdf', 'txt']];
		$depot    = new DepotData([], $settings);

		$this->assertSame($settings, $depot->settings);
	}

	public function testUsesEmptyArrayAsDefaultSettings(): void
	{
		$depot = new DepotData();
		$this->assertSame([], $depot->settings);
	}

	public function testHandlesUnicodeFilenames(): void
	{
		$unicodeFiles = [
			[
				'name' => 'документ.pdf', // Russian
				'mime' => 'application/pdf',
				'size' => 1000,
			],
			[
				'name' => '文档.txt', // Chinese
				'mime' => 'text/plain',
				'size' => 500,
			],
			[
				'name'  => 'フォルダ', // Japanese folder
				'mime'  => 'folder',
				'files' => [
					[
						'name' => 'ファイル.doc',
						'mime' => 'application/msword',
						'size' => 2000,
					],
				],
			],
		];

		$depot = new DepotData(['files' => $unicodeFiles]);

		$this->assertCount(3, $depot->files);
		$this->assertStringContainsString('документ', $depot->files[0]->name);
		$this->assertStringContainsString('文档', $depot->files[1]->name);
		$this->assertInstanceOf(FolderData::class, $depot->files[2]);
		$this->assertStringContainsString('フォルダ', $depot->files[2]->name);
	}

	public function testProtectionFlagHandling(): void
	{
		// Test explicit true
		$protectedDepot = new DepotData(['protected' => true]);
		$this->assertTrue($protectedDepot->protected);

		// Test explicit false
		$unprotectedDepot = new DepotData(['protected' => false]);
		$this->assertFalse($unprotectedDepot->protected);

		// Test default (should be true)
		$defaultDepot = new DepotData();
		$this->assertTrue($defaultDepot->protected);
	}

	public function testTransformationIncludesAllRequiredFields(): void
	{
		$depot = new DepotData([
			'protected' => true,
			'password'  => 'test',
			'files'     => [
				[
					'name' => 'test.txt',
					'mime' => 'text/plain',
					'size' => 100,
				],
			],
		]);

		$transformed = $depot->transform();

		$this->assertArrayHasKey('protected', $transformed);
		$this->assertArrayHasKey('password', $transformed);
		$this->assertArrayHasKey('files', $transformed);

		$this->assertIsBool($transformed['protected']);
		$this->assertIsString($transformed['password']);
		$this->assertIsArray($transformed['files']);
	}
}
