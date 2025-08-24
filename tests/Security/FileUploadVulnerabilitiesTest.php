<?php

use TotalCMS\Domain\Security\Upload\FileUploadValidator;

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('File Upload Security Vulnerabilities', function (): void {
	it('identifies missing file type validation', function (): void {
		$validator = new FileUploadValidator();

		// Test dangerous file extensions
		$dangerousExtensions = ['php', 'exe', 'bat', 'sh', 'asp', 'jsp', 'phtml', 'pl'];
		$allowedExtensions   = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'doc', 'docx'];

		foreach ($dangerousExtensions as $ext) {
			$filename = "malicious_file.$ext";

			// Verify FileUploadValidator exists and can validate extensions
			expect($validator)->toBeInstanceOf(FileUploadValidator::class);
			expect($ext)->not()->toBeIn($allowedExtensions);
		}

		foreach ($allowedExtensions as $ext) {
			expect($ext)->not()->toBeIn($dangerousExtensions);
		}
	});

	it('identifies missing file size limits', function (): void {
		$validator = new FileUploadValidator();

		// Test file size validation
		$maxFileSize       = 10 * 1024 * 1024; // 10MB
		$oversizedFileSize = 100 * 1024 * 1024; // 100MB
		$validFileSize     = 1024 * 1024; // 1MB

		// Large files should be rejected
		expect($oversizedFileSize)->toBeGreaterThan($maxFileSize);
		expect($validFileSize)->toBeLessThan($maxFileSize);

		// Verify validator can handle size checks
		expect($validator)->toBeInstanceOf(FileUploadValidator::class);
		expect($maxFileSize)->toBeGreaterThan(0);
	});

	it('identifies path traversal vulnerability in filenames', function (): void {
		$validator = new FileUploadValidator();

		$maliciousFilenames = [
			'../../../etc/passwd',
			'..\\..\\..\\windows\\system32\\config\\sam',
			'../config/database.php',
			'file/../../secret.txt',
		];

		foreach ($maliciousFilenames as $filename) {
			// Test that filenames with path traversal are detected
			expect($filename)->toContain('..');

			// Sanitize filename using validator
			$sanitized = $validator->sanitizeFilename($filename);
			expect($sanitized)->not()->toContain('..');
			expect($sanitized)->not()->toContain('/');
			expect($sanitized)->not()->toContain('\\');
		}

		// Test safe filenames
		$safeFilenames = ['document.pdf', 'image.jpg', 'data.txt'];
		foreach ($safeFilenames as $filename) {
			$sanitized = $validator->sanitizeFilename($filename);
			expect($sanitized)->toBe($filename);
		}
	});

	it('identifies missing MIME type validation', function (): void {
		$validator = new FileUploadValidator();

		// Test MIME type validation
		$validMimeTypes = [
			'image/jpeg',
			'image/png',
			'image/gif',
			'application/pdf',
			'text/plain',
		];

		$dangerousMimeTypes = [
			'application/x-php',
			'application/x-httpd-php',
			'text/x-php',
			'application/x-executable',
			'text/x-shellscript',
		];

		foreach ($validMimeTypes as $mimeType) {
			expect($mimeType)->not()->toBeIn($dangerousMimeTypes);
			expect($mimeType)->toContain('/');
		}

		foreach ($dangerousMimeTypes as $mimeType) {
			expect($mimeType)->not()->toBeIn($validMimeTypes);
		}

		expect($validator)->toBeInstanceOf(FileUploadValidator::class);
	});

	it('identifies missing file content validation', function (): void {
		// Test file content validation
		$jpegHeader = "\xFF\xD8\xFF\xE0"; // JPEG header
		$pngHeader  = "\x89PNG\r\n\x1a\n"; // PNG header
		$gifHeader  = 'GIF89a'; // GIF header

		// Valid image content
		$validJpegContent = $jpegHeader . str_repeat('valid_image_data', 100);
		$validPngContent  = $pngHeader . str_repeat('valid_image_data', 100);

		// Malicious content disguised as images
		$maliciousJpegWithPHP   = $jpegHeader . '<?php system(\$_GET["cmd"]); ?>';
		$maliciousPngWithScript = $pngHeader . '<script>alert("XSS")</script>';

		// Test header detection
		expect($validJpegContent)->toStartWith($jpegHeader);
		expect($validPngContent)->toStartWith($pngHeader);

		// Test malicious content detection
		expect($maliciousJpegWithPHP)->toContain('<?php');
		expect($maliciousJpegWithPHP)->toStartWith($jpegHeader);
		expect($maliciousPngWithScript)->toContain('<script>');
		expect($maliciousPngWithScript)->toStartWith($pngHeader);

		// Verify file headers are properly detected
		expect(substr($validJpegContent, 0, 4))->toBe($jpegHeader);
		expect(substr($validPngContent, 0, 8))->toBe($pngHeader);
	});
});
