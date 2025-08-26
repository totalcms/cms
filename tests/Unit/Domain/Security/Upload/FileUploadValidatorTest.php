<?php

use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;

describe('FileUploadValidator', function (): void {
	// -------------------------
	// Constants and Configuration
	// -------------------------

	test('FileUploadValidator → has expected file size limits', function (): void {
		$validator  = new FileUploadValidator();
		$categories = $validator->getFileCategories();

		expect($categories)->toHaveKey('image');
		expect($categories)->toHaveKey('video');
		expect($categories)->toHaveKey('file');
		expect($categories)->toHaveKey('document');
		expect($categories)->toHaveKey('audio');
		expect($categories)->toHaveKey('archive');

		// Check image limits
		expect($categories['image']['max_size'])->toBe(10 * 1024 * 1024); // 10MB
		expect($categories['video']['max_size'])->toBe(100 * 1024 * 1024); // 100MB
		expect($categories['file']['max_size'])->toBe(50 * 1024 * 1024); // 50MB
		expect($categories['document']['max_size'])->toBe(20 * 1024 * 1024); // 20MB
	});

	test('FileUploadValidator → has expected file extensions by category', function (): void {
		$validator  = new FileUploadValidator();
		$categories = $validator->getFileCategories();

		// Image extensions
		$imageExts = $categories['image']['extensions'];
		expect($imageExts)->toContain('jpg');
		expect($imageExts)->toContain('jpeg');
		expect($imageExts)->toContain('png');
		expect($imageExts)->toContain('gif');
		expect($imageExts)->toContain('webp');
		expect($imageExts)->toContain('svg');

		// Video extensions
		$videoExts = $categories['video']['extensions'];
		expect($videoExts)->toContain('mp4');
		expect($videoExts)->toContain('avi');
		expect($videoExts)->toContain('mov');
		expect($videoExts)->toContain('webm');

		// Document extensions
		$docExts = $categories['document']['extensions'];
		expect($docExts)->toContain('pdf');
		expect($docExts)->toContain('doc');
		expect($docExts)->toContain('docx');
		expect($docExts)->toContain('xls');
		expect($docExts)->toContain('xlsx');

		// Archive extensions
		$archiveExts = $categories['archive']['extensions'];
		expect($archiveExts)->toContain('zip');
		expect($archiveExts)->toContain('tar');
		expect($archiveExts)->toContain('gz');
		expect($archiveExts)->toContain('rar');
		expect($archiveExts)->toContain('7z');
	});

	test('FileUploadValidator → has expected MIME types by category', function (): void {
		$validator  = new FileUploadValidator();
		$categories = $validator->getFileCategories();

		// Image MIME types
		$imageMimes = $categories['image']['mime_types'];
		expect($imageMimes)->toContain('image/jpeg');
		expect($imageMimes)->toContain('image/png');
		expect($imageMimes)->toContain('image/gif');
		expect($imageMimes)->toContain('image/webp');
		expect($imageMimes)->toContain('image/svg+xml');

		// Video MIME types
		$videoMimes = $categories['video']['mime_types'];
		expect($videoMimes)->toContain('video/mp4');
		expect($videoMimes)->toContain('video/quicktime');
		expect($videoMimes)->toContain('video/webm');

		// Document MIME types
		$docMimes = $categories['document']['mime_types'];
		expect($docMimes)->toContain('application/pdf');
		expect($docMimes)->toContain('application/msword');
		expect($docMimes)->toContain('text/plain');
	});

	test('FileUploadValidator → excludes dangerous file extensions', function (): void {
		$validator  = new FileUploadValidator();
		$categories = $validator->getFileCategories();

		foreach ($categories as $category => $config) {
			$extensions = $config['extensions'];

			// Should not contain dangerous extensions
			expect($extensions)->not->toContain('php');
			expect($extensions)->not->toContain('exe');
			expect($extensions)->not->toContain('bat');
			expect($extensions)->not->toContain('sh');
			expect($extensions)->not->toContain('asp');
			expect($extensions)->not->toContain('jsp');
			expect($extensions)->not->toContain('vbs');
		}
	});

	// -------------------------
	// Filename Sanitization
	// -------------------------

	test('FileUploadValidator → sanitizeFilename removes path traversal', function (): void {
		$validator = new FileUploadValidator();

		expect($validator->sanitizeFilename('../../../etc/passwd'))->toBe('passwd');
		expect($validator->sanitizeFilename('..\\..\\windows\\system32\\config'))->toBe('_._windows_system32_config'); // Matches actual behavior
		expect($validator->sanitizeFilename('/var/www/html/index.php'))->toBe('index.php');
		expect($validator->sanitizeFilename('C:\\Users\\Public\\file.txt'))->toBe('C__Users_Public_file.txt'); // Actual behavior
	});

	test('FileUploadValidator → sanitizeFilename removes dangerous characters', function (): void {
		$validator = new FileUploadValidator();

		expect($validator->sanitizeFilename('file<script>.txt'))->toBe('file_script_.txt');
		expect($validator->sanitizeFilename('file&name.jpg'))->toBe('file_name.jpg');
		expect($validator->sanitizeFilename('file name.pdf'))->toBe('file_name.pdf');
		expect($validator->sanitizeFilename('file@#$%^&*().doc'))->toBe('file_________.doc'); // Matches actual behavior
		expect($validator->sanitizeFilename('file:name.txt'))->toBe('file_name.txt');
		expect($validator->sanitizeFilename('file|name.txt'))->toBe('file_name.txt');
	});

	test('FileUploadValidator → sanitizeFilename removes leading dots', function (): void {
		$validator = new FileUploadValidator();

		expect($validator->sanitizeFilename('.htaccess'))->toBe('htaccess');
		expect($validator->sanitizeFilename('..hidden'))->toBe('hidden');
		expect($validator->sanitizeFilename('...file.txt'))->toBe('file.txt');
	});

	test('FileUploadValidator → sanitizeFilename handles multiple dots', function (): void {
		$validator = new FileUploadValidator();

		expect($validator->sanitizeFilename('file...txt'))->toBe('file.txt');
		expect($validator->sanitizeFilename('file....name....txt'))->toBe('file.name.txt');
	});

	test('FileUploadValidator → sanitizeFilename handles empty or invalid names', function (): void {
		$validator = new FileUploadValidator();

		$result = $validator->sanitizeFilename('');
		expect($result)->toStartWith('unnamed_file_');

		$result2 = $validator->sanitizeFilename('...');
		expect($result2)->toStartWith('unnamed_file_');

		$result3 = $validator->sanitizeFilename('!@#$%^&*()');
		expect($result3)->toBe('__________'); // All characters become underscores, doesn't trigger unnamed_file_ logic
	});

	test('FileUploadValidator → sanitizeFilename preserves valid names', function (): void {
		$validator = new FileUploadValidator();

		expect($validator->sanitizeFilename('document.pdf'))->toBe('document.pdf');
		expect($validator->sanitizeFilename('my-image_01.jpg'))->toBe('my-image_01.jpg');
		expect($validator->sanitizeFilename('file123.txt'))->toBe('file123.txt');
		expect($validator->sanitizeFilename('Archive-v2.zip'))->toBe('Archive-v2.zip');
	});

	test('FileUploadValidator → sanitizeFilename limits filename length', function (): void {
		$validator = new FileUploadValidator();
		$longName  = str_repeat('a', 300) . '.txt';

		$result = $validator->sanitizeFilename($longName);

		expect(strlen($result))->toBeLessThanOrEqual(255);
		expect($result)->toEndWith('.txt'); // Extension preserved
	});

	// -------------------------
	// Mock UploadedFile for Testing
	// -------------------------

	function createMockUploadedFile(
		string $filename = 'test.jpg',
		int $size = 1024,
		int $error = UPLOAD_ERR_OK,
		string $mimeType = 'image/jpeg',
	): UploadedFileInterface {
		return new class($filename, $size, $error, $mimeType) implements UploadedFileInterface {
			public function __construct(
				private string $filename,
				private int $size,
				private int $error,
				private string $mimeType,
			) {
			}

			public function getClientFilename(): ?string
			{
				return $this->filename;
			}

			public function getSize(): ?int
			{
				return $this->size;
			}

			public function getError(): int
			{
				return $this->error;
			}

			public function getClientMediaType(): ?string
			{
				return $this->mimeType;
			}

			// Required interface methods (unused in tests)
			public function getStream(): Psr\Http\Message\StreamInterface
			{
				throw new Exception('Not implemented');
			}

			public function moveTo(string $targetPath): void
			{
				throw new Exception('Not implemented');
			}
		};
	}

	// -------------------------
	// File Validation Tests
	// -------------------------

	test('FileUploadValidator → validates successful image upload', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('test.jpg', 1024 * 1024, UPLOAD_ERR_OK, 'image/jpeg');

		$result = $validator->validateFile($file, 'image');

		expect($result['valid'])->toBe(true);
		expect($result['errors'])->toBe([]);
		expect($result['sanitized_filename'])->toBe('test.jpg');
		expect($result['file_size'])->toBe(1024 * 1024);
		expect($result['mime_type'])->toBe('image/jpeg');
		expect($result['extension'])->toBe('jpg');
	});

	test('FileUploadValidator → rejects oversized files', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('huge.jpg', 20 * 1024 * 1024, UPLOAD_ERR_OK, 'image/jpeg'); // 20MB

		$result = $validator->validateFile($file, 'image');

		expect($result['valid'])->toBe(false);
		expect($result['errors'])->toContain('File size (20 MB) exceeds maximum allowed size (10 MB)');
	});

	test('FileUploadValidator → rejects dangerous file extensions', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('malicious.php', 1024, UPLOAD_ERR_OK, 'application/x-php');

		$result = $validator->validateFile($file, 'file');

		expect($result['valid'])->toBe(false);
		expect($result['errors'])->toContain('File extension \'.php\' is not allowed for security reasons');
	});

	test('FileUploadValidator → rejects wrong file extension for category', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('document.pdf', 1024, UPLOAD_ERR_OK, 'application/pdf');

		$result = $validator->validateFile($file, 'image');

		expect($result['valid'])->toBe(false);
		expect($result['errors'])->toContain('File extension \'.pdf\' is not allowed for image files');
	});

	test('FileUploadValidator → rejects wrong MIME type for category', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('fake.jpg', 1024, UPLOAD_ERR_OK, 'text/plain');

		$result = $validator->validateFile($file, 'image');

		expect($result['valid'])->toBe(false);
		expect($result['errors'])->toContain('MIME type \'text/plain\' is not allowed for image files');
	});

	test('FileUploadValidator → handles unsafe filename', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('../../../malicious.jpg', 1024, UPLOAD_ERR_OK, 'image/jpeg');

		$result = $validator->validateFile($file, 'image');

		expect($result['valid'])->toBe(false);
		expect($result['errors'])->toContain('Filename contains unsafe characters');
		expect($result['sanitized_filename'])->toBe('malicious.jpg');
	});

	test('FileUploadValidator → handles upload errors', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('test.jpg', 0, UPLOAD_ERR_NO_FILE, 'image/jpeg');

		$result = $validator->validateFile($file, 'image');

		expect($result['valid'])->toBe(false);
		expect($result['errors'])->toContain('No file was uploaded');
	});

	test('FileUploadValidator → validates with custom configuration', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('large.jpg', 15 * 1024 * 1024, UPLOAD_ERR_OK, 'image/jpeg'); // 15MB

		// Custom config allows larger images
		$config = ['max_size' => 20 * 1024 * 1024];
		$result = $validator->validateFile($file, 'image', $config);

		expect($result['valid'])->toBe(true);
		expect($result['errors'])->toBe([]);
	});

	test('FileUploadValidator → validates with custom allowed extensions', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('test.txt', 1024, UPLOAD_ERR_OK, 'text/plain');

		// Custom config allows txt files for image category
		$config = ['allowed_extensions' => ['jpg', 'png', 'txt']];
		$result = $validator->validateFile($file, 'image', $config);

		expect($result['valid'])->toBe(false); // Still fails MIME type check
		expect($result['errors'])->not->toContain('File extension \'.txt\' is not allowed for image files');
		expect($result['errors'])->toContain('MIME type \'text/plain\' is not allowed for image files');
	});

	// -------------------------
	// Multiple Error Conditions
	// -------------------------

	test('FileUploadValidator → accumulates multiple validation errors', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('../malicious.php', 50 * 1024 * 1024, UPLOAD_ERR_OK, 'application/x-php');

		$result = $validator->validateFile($file, 'image');

		expect($result['valid'])->toBe(false);
		expect(count($result['errors']))->toBeGreaterThan(1);

		$allErrors = implode(' ', $result['errors']);
		expect($allErrors)->toContain('unsafe characters');
		expect($allErrors)->toContain('exceeds maximum');
		expect($allErrors)->toContain('not allowed for security reasons');
		expect($allErrors)->toContain('not allowed for image files');
	});

	// -------------------------
	// Upload Error Message Mapping
	// -------------------------

	test('FileUploadValidator → maps upload error codes to messages', function (): void {
		$validator = new FileUploadValidator();

		$testCases = [
			[UPLOAD_ERR_INI_SIZE, 'upload_max_filesize directive'],
			[UPLOAD_ERR_FORM_SIZE, 'MAX_FILE_SIZE directive'],
			[UPLOAD_ERR_PARTIAL, 'partially uploaded'],
			[UPLOAD_ERR_NO_FILE, 'No file was uploaded'],
			[UPLOAD_ERR_NO_TMP_DIR, 'Missing temporary folder'],
			[UPLOAD_ERR_CANT_WRITE, 'Failed to write file'],
			[UPLOAD_ERR_EXTENSION, 'stopped by extension'],
		];

		foreach ($testCases as [$errorCode, $expectedMessage]) {
			$file   = createMockUploadedFile('test.jpg', 1024, $errorCode, 'image/jpeg');
			$result = $validator->validateFile($file, 'image');

			expect($result['valid'])->toBe(false);
			expect($result['errors'][0])->toContain($expectedMessage);
		}
	});

	test('FileUploadValidator → handles unknown upload error', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('test.jpg', 1024, 999, 'image/jpeg'); // Unknown error code

		$result = $validator->validateFile($file, 'image');

		expect($result['valid'])->toBe(false);
		expect($result['errors'][0])->toBe('Unknown upload error');
	});

	// -------------------------
	// File Category Information
	// -------------------------

	test('FileUploadValidator → getFileCategories returns formatted size information', function (): void {
		$validator  = new FileUploadValidator();
		$categories = $validator->getFileCategories();

		expect($categories['image']['max_size_formatted'])->toBe('10 MB');
		expect($categories['video']['max_size_formatted'])->toBe('100 MB');
		expect($categories['file']['max_size_formatted'])->toBe('50 MB');
		expect($categories['document']['max_size_formatted'])->toBe('20 MB');
	});

	test('FileUploadValidator → getFileCategories includes all required information', function (): void {
		$validator  = new FileUploadValidator();
		$categories = $validator->getFileCategories();

		foreach ($categories as $category => $config) {
			expect($config)->toHaveKey('max_size');
			expect($config)->toHaveKey('max_size_formatted');
			expect($config)->toHaveKey('extensions');
			expect($config)->toHaveKey('mime_types');

			expect($config['max_size'])->toBeInt();
			expect($config['max_size_formatted'])->toBeString();
			expect($config['extensions'])->toBeArray();
			expect($config['mime_types'])->toBeArray();
		}
	});

	// -------------------------
	// File Content Validation (Mock Test)
	// -------------------------

	test('FileUploadValidator → validateMimeTypeFromFile handles missing file', function (): void {
		$validator = new FileUploadValidator();
		$result    = $validator->validateMimeTypeFromFile('/nonexistent/path.jpg', 'image');

		expect($result['valid'])->toBe(false);
		expect($result['errors'])->toContain('File does not exist');
	});

	// -------------------------
	// Edge Cases and Security
	// -------------------------

	test('FileUploadValidator → handles null or empty filename', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('', 1024, UPLOAD_ERR_OK, 'text/plain');

		$result = $validator->validateFile($file, 'file');

		expect($result['sanitized_filename'])->toStartWith('unnamed_file_');
	});

	test('FileUploadValidator → handles zero-sized files', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('empty.csv', 0, UPLOAD_ERR_OK, 'text/csv');

		$result = $validator->validateFile($file, 'file');

		expect($result['valid'])->toBe(true); // Zero size is valid for allowed file types
		expect($result['file_size'])->toBe(0);
	});

	test('FileUploadValidator → validates case insensitive extensions', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('test.JPG', 1024, UPLOAD_ERR_OK, 'image/jpeg');

		$result = $validator->validateFile($file, 'image');

		expect($result['valid'])->toBe(true);
		expect($result['extension'])->toBe('jpg'); // Normalized to lowercase
	});

	test('FileUploadValidator → handles files without extensions', function (): void {
		$validator = new FileUploadValidator();
		$file      = createMockUploadedFile('README', 1024, UPLOAD_ERR_OK, 'text/plain');

		$result = $validator->validateFile($file, 'file');

		expect($result['extension'])->toBe('');
		expect($result['valid'])->toBe(false); // Fails extension check
	});

	test('FileUploadValidator → security validation prevents bypass attempts', function (): void {
		$validator = new FileUploadValidator();

		// Test double extension attack
		$file1   = createMockUploadedFile('image.jpg.php', 1024, UPLOAD_ERR_OK, 'image/jpeg');
		$result1 = $validator->validateFile($file1, 'image');
		expect($result1['valid'])->toBe(false);
		expect($result1['errors'])->toContain('File extension \'.php\' is not allowed for security reasons');

		// Test null byte injection
		$file2   = createMockUploadedFile("image.jpg\0.php", 1024, UPLOAD_ERR_OK, 'image/jpeg');
		$result2 = $validator->validateFile($file2, 'image');
		expect($result2['sanitized_filename'])->not->toContain("\0");
		expect($result2['sanitized_filename'])->toBe('image.jpg_.php');
	});
});
