<?php

namespace Tests\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;

/**
 * Test File Upload Security Validation.
 */
#[CoversClass(FileUploadValidator::class)]
final class FileUploadValidatorTest extends TestCase
{
	private FileUploadValidator $validator;

	protected function setUp(): void
	{
		parent::setUp();
		$this->validator = new FileUploadValidator();
	}

	/**
	 * Create a mock uploaded file for testing.
	 */
	private function createMockUploadedFile(
		string $filename = 'test.jpg',
		int $size = 1024,
		int $error = UPLOAD_ERR_OK,
		string $mimeType = 'image/jpeg',
	): UploadedFileInterface {
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getClientFilename')->willReturn($filename);
		$file->method('getSize')->willReturn($size);
		$file->method('getError')->willReturn($error);
		$file->method('getClientMediaType')->willReturn($mimeType);

		return $file;
	}

	public function testValidImageFilePassesValidation(): void
	{
		$file = $this->createMockUploadedFile('photo.jpg', 5 * 1024 * 1024, UPLOAD_ERR_OK, 'image/jpeg');

		$result = $this->validator->validateFile($file, 'image');

		$this->assertTrue($result['valid']);
		$this->assertEmpty($result['errors']);
		$this->assertEquals('photo.jpg', $result['sanitized_filename']);
		$this->assertEquals(5 * 1024 * 1024, $result['file_size']);
		$this->assertEquals('image/jpeg', $result['mime_type']);
		$this->assertEquals('jpg', $result['extension']);
	}

	public function testFileTooBigFailsValidation(): void
	{
		// 15MB image (over 10MB limit)
		$file = $this->createMockUploadedFile('large.jpg', 15 * 1024 * 1024, UPLOAD_ERR_OK, 'image/jpeg');

		$result = $this->validator->validateFile($file, 'image');

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('File size (15 MB) exceeds maximum allowed size (10 MB)', implode(' ', $result['errors']));
	}

	public function testDangerousExtensionFailsValidation(): void
	{
		$dangerousExtensions = ['php', 'php3', 'asp', 'jsp', 'exe', 'bat', 'sh', 'py', 'pl'];

		foreach ($dangerousExtensions as $ext) {
			$file = $this->createMockUploadedFile("malicious.{$ext}", 1024, UPLOAD_ERR_OK, 'text/plain');

			$result = $this->validator->validateFile($file, 'file');

			$this->assertFalse($result['valid'], "Extension .{$ext} should be blocked");
			$this->assertStringContainsString("File extension '.{$ext}' is not allowed for security reasons", implode(' ', $result['errors']));
		}
	}

	public function testInvalidExtensionForCategoryFailsValidation(): void
	{
		// Try to upload a PDF as an image
		$file = $this->createMockUploadedFile('document.pdf', 1024, UPLOAD_ERR_OK, 'application/pdf');

		$result = $this->validator->validateFile($file, 'image');

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString("File extension '.pdf' is not allowed for image files", implode(' ', $result['errors']));
	}

	public function testInvalidMimeTypeFailsValidation(): void
	{
		// Image file with wrong MIME type
		$file = $this->createMockUploadedFile('fake.jpg', 1024, UPLOAD_ERR_OK, 'application/octet-stream');

		$result = $this->validator->validateFile($file, 'image');

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString("MIME type 'application/octet-stream' is not allowed for image files", implode(' ', $result['errors']));
	}

	public function testUploadErrorFailsValidation(): void
	{
		$file = $this->createMockUploadedFile('test.jpg', 1024, UPLOAD_ERR_PARTIAL, 'image/jpeg');

		$result = $this->validator->validateFile($file, 'image');

		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('File was only partially uploaded', implode(' ', $result['errors']));
	}

	public function testFilenameSanitization(): void
	{
		$testCases = [
			'normal-file_123.jpg'               => 'normal-file_123.jpg',
			'../../../etc/passwd'               => 'passwd',
			'file<script>alert(1)</script>.jpg' => 'script_.jpg',
			'file|whoami.jpg'                   => 'file_whoami.jpg',
			'file;rm -rf /.jpg'                 => 'jpg',
			'.hidden-file.jpg'                  => 'hidden-file.jpg',
			'file...with...dots.jpg'            => 'file.with.dots.jpg',
			''                                  => 'unnamed_file_' . time(), // Special case for empty filename
		];

		foreach ($testCases as $input => $expected) {
			if ($input === '') {
				// For empty filename, just check that it starts with 'unnamed_file_'
				$result = $this->validator->sanitizeFilename($input);
				$this->assertStringStartsWith('unnamed_file_', $result);
			} else {
				$result = $this->validator->sanitizeFilename($input);
				$this->assertEquals($expected, $result, "Sanitization of '$input' failed");
			}
		}
	}

	public function testLongFilenameTruncation(): void
	{
		$longFilename = str_repeat('a', 300) . '.jpg';
		$sanitized    = $this->validator->sanitizeFilename($longFilename);

		$this->assertLessThanOrEqual(255, strlen($sanitized));
		$this->assertStringEndsWith('.jpg', $sanitized);
	}

	public function testAllFileCategories(): void
	{
		$categories = $this->validator->getFileCategories();

		$expectedCategories = ['image', 'video', 'audio', 'document', 'archive', 'file'];
		foreach ($expectedCategories as $category) {
			$this->assertArrayHasKey($category, $categories);
			$this->assertArrayHasKey('max_size', $categories[$category]);
			$this->assertArrayHasKey('max_size_formatted', $categories[$category]);
			$this->assertArrayHasKey('extensions', $categories[$category]);
			$this->assertArrayHasKey('mime_types', $categories[$category]);
		}
	}

	public function testValidateVideoFile(): void
	{
		$file = $this->createMockUploadedFile('video.mp4', 50 * 1024 * 1024, UPLOAD_ERR_OK, 'video/mp4');

		$result = $this->validator->validateFile($file, 'video');

		$this->assertTrue($result['valid']);
		$this->assertEmpty($result['errors']);
	}

	public function testValidateDocumentFile(): void
	{
		$file = $this->createMockUploadedFile('document.pdf', 5 * 1024 * 1024, UPLOAD_ERR_OK, 'application/pdf');

		$result = $this->validator->validateFile($file, 'document');

		$this->assertTrue($result['valid']);
		$this->assertEmpty($result['errors']);
	}

	public function testCustomConfiguration(): void
	{
		$config = [
			'max_size'           => 500 * 1024, // 500KB
			'allowed_extensions' => ['txt', 'md'],
			'allowed_mime_types' => ['text/plain', 'text/markdown'],
		];

		// Should pass with small text file
		$file   = $this->createMockUploadedFile('readme.txt', 100 * 1024, UPLOAD_ERR_OK, 'text/plain');
		$result = $this->validator->validateFile($file, 'file', $config);
		$this->assertTrue($result['valid']);

		// Should fail with file too large
		$file   = $this->createMockUploadedFile('large.txt', 600 * 1024, UPLOAD_ERR_OK, 'text/plain');
		$result = $this->validator->validateFile($file, 'file', $config);
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString('exceeds maximum allowed size', $result['errors'][0]);

		// Should fail with disallowed extension
		$file   = $this->createMockUploadedFile('image.jpg', 100 * 1024, UPLOAD_ERR_OK, 'image/jpeg');
		$result = $this->validator->validateFile($file, 'file', $config);
		$this->assertFalse($result['valid']);
		$this->assertStringContainsString("File extension '.jpg' is not allowed", $result['errors'][0]);
	}

	public function testGetUploadErrorMessages(): void
	{
		$errorMessages = [
			UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize directive in php.ini',
			UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE directive in HTML form',
			UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
			UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
			UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
			UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
			UPLOAD_ERR_EXTENSION  => 'File upload stopped by extension',
		];

		foreach ($errorMessages as $errorCode => $expectedMessage) {
			$file   = $this->createMockUploadedFile('test.jpg', 1024, $errorCode, 'image/jpeg');
			$result = $this->validator->validateFile($file, 'image');

			$this->assertFalse($result['valid']);
			$this->assertStringContainsString($expectedMessage, implode(' ', $result['errors']));
		}
	}

	public function testValidateMimeTypeFromFile(): void
	{
		// Create a temporary file
		$tempFile = tempnam(sys_get_temp_dir(), 'upload_test_');
		file_put_contents($tempFile, 'test content');

		try {
			// Test with existing file
			$result = $this->validator->validateMimeTypeFromFile($tempFile, 'file');

			$this->assertArrayHasKey('valid', $result);
			$this->assertArrayHasKey('detected_mime', $result);
			$this->assertArrayHasKey('errors', $result);

			// Test with non-existent file
			$result = $this->validator->validateMimeTypeFromFile('/nonexistent/file.txt', 'file');

			$this->assertFalse($result['valid']);
			$this->assertStringContainsString('File does not exist', implode(' ', $result['errors']));
		} finally {
			unlink($tempFile);
		}
	}

	public function testFormatBytes(): void
	{
		// We can't directly test the private method, but we can test it through the error messages
		$file   = $this->createMockUploadedFile('large.jpg', 15 * 1024 * 1024, UPLOAD_ERR_OK, 'image/jpeg');
		$result = $this->validator->validateFile($file, 'image');

		$this->assertStringContainsString('15 MB', $result['errors'][0]);
		$this->assertStringContainsString('10 MB', $result['errors'][0]);
	}

	public function testMultipleErrors(): void
	{
		// File with multiple issues: too large, dangerous extension, wrong MIME
		$file = $this->createMockUploadedFile('malware.php', 50 * 1024 * 1024, UPLOAD_ERR_OK, 'application/x-php');

		$result = $this->validator->validateFile($file, 'image');

		$this->assertFalse($result['valid']);
		$this->assertGreaterThan(1, count($result['errors']));

		// Should contain errors for:
		// 1. File too large
		// 2. Dangerous extension
		// 3. Wrong extension for category
		// 4. Wrong MIME type for category
		$errorString = implode(' ', $result['errors']);
		$this->assertStringContainsString('exceeds maximum', $errorString);
		$this->assertStringContainsString('not allowed for security reasons', $errorString);
		$this->assertStringContainsString('not allowed for image', $errorString);
	}

	public function testEdgeCases(): void
	{
		// Test with null filename
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getClientFilename')->willReturn(null);
		$file->method('getSize')->willReturn(1024);
		$file->method('getError')->willReturn(UPLOAD_ERR_OK);
		$file->method('getClientMediaType')->willReturn('text/plain');

		$result = $this->validator->validateFile($file, 'file');

		// Should use 'unknown' as filename and sanitize to valid name
		$this->assertEquals('unknown', $result['sanitized_filename']);

		// Test with zero-size file
		$file   = $this->createMockUploadedFile('empty.txt', 0, UPLOAD_ERR_OK, 'text/plain');
		$result = $this->validator->validateFile($file, 'file');

		// Zero-size files should be allowed (they pass the size check) but txt extension is not in 'file' category
		$this->assertFalse($result['valid']); // txt is not in allowed extensions for 'file' category
		$this->assertStringContainsString("File extension '.txt' is not allowed for file files", implode(' ', $result['errors']));
	}

	/**
	 * Test common file extensions for each category.
	 */
	public function testCommonFileExtensions(): void
	{
		$testCases = [
			'image' => [
				['file.jpg', 'image/jpeg', true],
				['file.png', 'image/png', true],
				['file.gif', 'image/gif', true],
				['file.webp', 'image/webp', true],
				['file.svg', 'image/svg+xml', true],
				['file.txt', 'text/plain', false], // Wrong extension for category
			],
			'video' => [
				['video.mp4', 'video/mp4', true],
				['video.avi', 'video/avi', true],
				['video.mov', 'video/quicktime', true],
				['video.webm', 'video/webm', true],
				['video.jpg', 'image/jpeg', false], // Wrong extension for category
			],
			'document' => [
				['doc.pdf', 'application/pdf', true],
				['doc.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', true],
				['doc.txt', 'text/plain', true],
				['doc.mp3', 'audio/mpeg', false], // Wrong extension for category
			],
		];

		foreach ($testCases as $category => $tests) {
			foreach ($tests as [$filename, $mimeType, $shouldPass]) {
				$file   = $this->createMockUploadedFile($filename, 1024, UPLOAD_ERR_OK, $mimeType);
				$result = $this->validator->validateFile($file, $category);

				if ($shouldPass) {
					$this->assertTrue($result['valid'], "File $filename should pass validation for category $category");
				} else {
					$this->assertFalse($result['valid'], "File $filename should fail validation for category $category");
				}
			}
		}
	}
}
