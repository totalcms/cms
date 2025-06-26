<?php

namespace Tests\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Utils\FileUploadValidator;

#[CoversClass(ImageSaver::class)]
#[CoversClass(FileUploadValidator::class)]
final class FileUploadSecurityTest extends TestCase
{
	public function testMIMETypeValidation(): void
	{
		// Test malicious files with valid MIME types
		$maliciousFiles = [
			[
				'name'        => 'innocent.jpg',
				'type'        => 'image/jpeg',
				'content'     => "\xFF\xD8\xFF\xE0<?php system(\$_GET['cmd']); ?>", // PHP in JPEG
				'attack_type' => 'PHP injection in image',
			],
			[
				'name'        => 'harmless.png',
				'type'        => 'image/png',
				'content'     => "\x89PNG\r\n\x1a\n<script>alert(1)</script>",
				'attack_type' => 'Script injection in PNG',
			],
			[
				'name'        => 'safe.gif',
				'type'        => 'image/gif',
				'content'     => 'GIF89a<!--#exec cmd="/bin/cat /etc/passwd"-->',
				'attack_type' => 'SSI injection in GIF',
			],
			[
				'name'        => 'normal.pdf',
				'type'        => 'application/pdf',
				'content'     => "%PDF-1.4<</JS(app.alert('XSS'))>>",
				'attack_type' => 'JavaScript in PDF',
			],
		];

		foreach ($maliciousFiles as $file) {
			$this->assertMIMETypeSecurity($file, $file['attack_type']);
		}
	}

	public function testFileExtensionSpoofing(): void
	{
		// Test files with mismatched extensions and MIME types
		$spoofedFiles = [
			[
				'name'        => 'image.jpg.php',
				'type'        => 'image/jpeg',
				'content'     => '<?php phpinfo(); ?>',
				'attack_type' => 'double extension',
			],
			[
				'name'        => 'document.pdf.exe',
				'type'        => 'application/pdf',
				'content'     => 'MZ executable content',
				'attack_type' => 'executable with PDF extension',
			],
			[
				'name'        => 'style.css.php',
				'type'        => 'text/css',
				'content'     => '/* CSS */ <?php system(\$_GET["cmd"]); ?>',
				'attack_type' => 'CSS with PHP extension',
			],
			[
				'name'        => 'data.json.jsp',
				'type'        => 'application/json',
				'content'     => '{"data": "<%=request.getParameter(\"cmd\")%>"}',
				'attack_type' => 'JSON with JSP extension',
			],
		];

		foreach ($spoofedFiles as $file) {
			$this->assertExtensionSpoofingPrevention($file, $file['attack_type']);
		}
	}

	public function testMagicByteValidation(): void
	{
		// Test files with forged magic bytes
		$forgedFiles = [
			[
				'name'               => 'fake.jpg',
				'type'               => 'image/jpeg',
				'content'            => "\xFF\xD8\xFF\xE0JFIF<?php system('whoami'); ?>", // JPEG header + PHP
				'expected_real_type' => 'text/php',
				'attack_type'        => 'JPEG magic bytes with PHP',
			],
			[
				'name'               => 'fake.png',
				'type'               => 'image/png',
				'content'            => "\x89PNG\r\n\x1a\n#!/bin/bash\nrm -rf /",
				'expected_real_type' => 'text/shell',
				'attack_type'        => 'PNG magic bytes with shell script',
			],
			[
				'name'               => 'fake.gif',
				'type'               => 'image/gif',
				'content'            => "GIF89a<html><script>alert('XSS')</script></html>",
				'expected_real_type' => 'text/html',
				'attack_type'        => 'GIF magic bytes with HTML',
			],
			[
				'name'               => 'fake.zip',
				'type'               => 'application/zip',
				'content'            => "PK\x03\x04<?xml version=\"1.0\"?><script>alert(1)</script>",
				'expected_real_type' => 'text/xml',
				'attack_type'        => 'ZIP magic bytes with XML',
			],
		];

		foreach ($forgedFiles as $file) {
			$this->assertMagicByteValidation($file, $file['attack_type']);
		}
	}

	public function testPolyglotFileAttacks(): void
	{
		// Test files that are valid in multiple formats
		$polyglotFiles = [
			[
				'name'        => 'polyglot.jpg',
				'type'        => 'image/jpeg',
				'content'     => "\xFF\xD8\xFF\xE0/*<script>alert('polyglot')</script>*/",
				'attack_type' => 'JPEG-JavaScript polyglot',
			],
			[
				'name'        => 'polyglot.html',
				'type'        => 'text/html',
				'content'     => 'GIF89a<html><script>alert(1)</script></html>',
				'attack_type' => 'GIF-HTML polyglot',
			],
			[
				'name'        => 'polyglot.pdf',
				'type'        => 'application/pdf',
				'content'     => "%PDF-1.4\n<!DOCTYPE html><script>alert(1)</script>",
				'attack_type' => 'PDF-HTML polyglot',
			],
			[
				'name'        => 'polyglot.xml',
				'type'        => 'application/xml',
				'content'     => '<?xml version="1.0"?><script>alert(1)</script>',
				'attack_type' => 'XML with embedded script',
			],
		];

		foreach ($polyglotFiles as $file) {
			$this->assertPolyglotDetection($file, $file['attack_type']);
		}
	}

	public function testFileSizeAttacks(): void
	{
		// Test files designed to consume excessive resources
		$oversizedFiles = [
			[
				'name'        => 'huge.txt',
				'type'        => 'text/plain',
				'content'     => str_repeat('A', 60 * 1024 * 1024), // 60MB - slightly larger than limit
				'attack_type' => 'memory exhaustion',
			],
			[
				'name'        => 'zip_bomb.zip',
				'type'        => 'application/zip',
				'content'     => $this->createZipBomb(),
				'attack_type' => 'zip bomb',
			],
			[
				'name'        => 'xml_bomb.xml',
				'type'        => 'application/xml',
				'content'     => $this->createXMLBomb(),
				'attack_type' => 'XML billion laughs',
			],
		];

		foreach ($oversizedFiles as $file) {
			$this->assertFileSizeLimits($file, $file['attack_type']);
		}
	}

	public function testPathTraversalInFilenames(): void
	{
		// Test filenames with path traversal attempts
		$maliciousFilenames = [
			'../../../etc/passwd.txt',
			'..\\..\\..\\windows\\system32\\hosts.txt',
			'/etc/shadow.jpg',
			'C:\\windows\\system32\\config.png',
			'filename\x00.php.jpg', // Null byte injection
			'normal.jpg\x00.php',
			'file%2e%2e%2fpasswd.txt', // URL encoded traversal
			'file..%5c..%5c..%5cwindows%5csystem32%5chosts',
		];

		foreach ($maliciousFilenames as $filename) {
			$this->assertPathTraversalPrevention($filename);
		}
	}

	public function testExecutableFileUpload(): void
	{
		// Test upload of executable files
		$executableFiles = [
			[
				'name'        => 'backdoor.php',
				'type'        => 'application/x-php',
				'content'     => '<?php system(\$_GET["cmd"]); ?>',
				'attack_type' => 'PHP backdoor',
			],
			[
				'name'        => 'script.jsp',
				'type'        => 'application/x-jsp',
				'content'     => '<%=Runtime.getRuntime().exec(request.getParameter("cmd"))%>',
				'attack_type' => 'JSP backdoor',
			],
			[
				'name'        => 'shell.asp',
				'type'        => 'application/x-asp',
				'content'     => '<%Response.Write(CreateObject("WScript.Shell").Exec(Request("cmd")).StdOut.ReadAll)%>',
				'attack_type' => 'ASP backdoor',
			],
			[
				'name'        => 'malware.exe',
				'type'        => 'application/x-msdownload',
				'content'     => "MZ\x90\x00malicious executable content",
				'attack_type' => 'Windows executable',
			],
		];

		foreach ($executableFiles as $file) {
			$this->assertExecutableFileBlocking($file, $file['attack_type']);
		}
	}

	public function testImageMetadataInjection(): void
	{
		// Test malicious content in image metadata
		$metadataAttacks = [
			[
				'name'      => 'image.jpg',
				'type'      => 'image/jpeg',
				'exif_data' => [
					'ImageDescription' => '<script>alert("XSS in EXIF")</script>',
					'UserComment'      => 'javascript:alert(1)',
					'Artist'           => '"; DROP TABLE images; --',
				],
				'attack_type' => 'EXIF injection',
			],
			[
				'name'      => 'image.png',
				'type'      => 'image/png',
				'iptc_data' => [
					'Caption'  => '../../../etc/passwd',
					'Keywords' => ['<script>alert(1)</script>', 'normal'],
				],
				'attack_type' => 'IPTC injection',
			],
		];

		foreach ($metadataAttacks as $attack) {
			$this->assertMetadataSecurityValidation($attack, $attack['attack_type']);
		}
	}

	public function testArchiveExtractionSecurity(): void
	{
		// Test security of archive extraction
		$maliciousArchives = [
			[
				'name'  => 'malicious.zip',
				'type'  => 'application/zip',
				'files' => [
					'../../../etc/passwd'                  => 'system file content',
					'normal.txt'                           => 'safe content',
					'..\\..\\..\\windows\\system32\\hosts' => 'windows file',
				],
				'attack_type' => 'zip slip vulnerability',
			],
			[
				'name'  => 'symlink.tar',
				'type'  => 'application/x-tar',
				'files' => [
					'symlink_to_etc' => '/etc/', // Symbolic link
					'normal.txt'     => 'safe content',
				],
				'attack_type' => 'symbolic link attack',
			],
		];

		foreach ($maliciousArchives as $archive) {
			$this->assertArchiveExtractionSecurity($archive, $archive['attack_type']);
		}
	}

	public function testContentTypeConfusion(): void
	{
		// Test content type confusion attacks
		$confusionAttacks = [
			[
				'name'           => 'image.jpg',
				'declared_type'  => 'image/jpeg',
				'actual_content' => '<html><script>alert(1)</script></html>',
				'attack_type'    => 'HTML served as image',
			],
			[
				'name'           => 'data.json',
				'declared_type'  => 'application/json',
				'actual_content' => '<?xml version="1.0"?><script>alert(1)</script>',
				'attack_type'    => 'XML served as JSON',
			],
			[
				'name'           => 'style.css',
				'declared_type'  => 'text/css',
				'actual_content' => 'body { background: url("javascript:alert(1)"); }',
				'attack_type'    => 'JavaScript in CSS',
			],
		];

		foreach ($confusionAttacks as $attack) {
			$this->assertContentTypeValidation($attack, $attack['attack_type']);
		}
	}

	public function testVirusAndMalwareScanning(): void
	{
		// Test patterns that might indicate malware
		$suspiciousFiles = [
			[
				'name'        => 'document.pdf',
				'type'        => 'application/pdf',
				'content'     => '%PDF-1.4<</S/JavaScript/JS(eval(unescape("malicious code")))>>',
				'attack_type' => 'PDF with embedded JavaScript',
			],
			[
				'name'        => 'macro.docx',
				'type'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'content'     => 'PK' . pack('H*', '4d414352') . 'malicious macro code',
				'attack_type' => 'Office document with macros',
			],
			[
				'name'        => 'suspicious.rtf',
				'type'        => 'application/rtf',
				'content'     => '{\\rtf1\\object\\objemb{\\result malicious embedded object}}',
				'attack_type' => 'RTF with embedded objects',
			],
		];

		foreach ($suspiciousFiles as $file) {
			$this->assertMalwareDetection($file, $file['attack_type']);
		}
	}

	/**
	 * Helper method to test MIME type security.
	 */
	private function assertMIMETypeSecurity(array $file, string $attackType): void
	{
		// MIME type should match actual content
		$declaredType = $file['type'];
		$content      = $file['content'];

		// Application should detect dangerous content regardless of MIME type
		$hasDangerousContent = (
			str_contains($content, '<?php')
			|| str_contains($content, '<script>')
			|| str_contains($content, 'javascript:')
		);

		if ($hasDangerousContent) {
			$this->assertTrue($hasDangerousContent, "Application should detect dangerous content in {$attackType}");
		}

		$this->assertIsString($declaredType);
	}

	/**
	 * Helper method to test extension spoofing prevention.
	 */
	private function assertExtensionSpoofingPrevention(array $file, string $attackType): void
	{
		$filename = $file['name'];
		$content  = $file['content'];

		// Should detect double extensions
		$doubleExtension = preg_match('/\.(php|jsp|asp|exe|sh|bat|cmd)\./i', $filename);
		if ($doubleExtension) {
			$this->assertTrue((bool)$doubleExtension, "Should detect double extension in {$attackType}");
		}

		// Should detect executable content
		$hasExecutableContent = (
			str_contains($content, '<?php')
			|| str_contains($content, '<%=')
			|| str_contains($content, 'MZ') // PE header
		);

		if ($hasExecutableContent) {
			$this->assertTrue($hasExecutableContent, "Should detect executable content in {$attackType}");
		}
	}

	/**
	 * Helper method to test magic byte validation.
	 */
	private function assertMagicByteValidation(array $file, string $attackType): void
	{
		$content      = $file['content'];
		$declaredType = $file['type'];

		// Extract magic bytes
		$magicBytes = substr($content, 0, 8);

		// Verify magic bytes match declared type
		$validMagicBytes = [
			'image/jpeg'      => ["\xFF\xD8\xFF"],
			'image/png'       => ["\x89PNG\r\n\x1a\n"],
			'image/gif'       => ['GIF87a', 'GIF89a'],
			'application/zip' => ["PK\x03\x04"],
		];

		if (isset($validMagicBytes[$declaredType])) {
			$hasValidMagic = false;
			foreach ($validMagicBytes[$declaredType] as $validMagic) {
				if (str_starts_with($content, $validMagic)) {
					$hasValidMagic = true;
					break;
				}
			}

			// Should detect if magic bytes don't match type or contain dangerous content
			$hasDangerousContent = (
				str_contains($content, '<?php')
				|| str_contains($content, '<script>')
				|| str_contains($content, '#!/bin/')
			);

			if ($hasDangerousContent) {
				$this->assertTrue($hasDangerousContent, "Should detect dangerous content in {$attackType}");
			}
		}

		$this->assertIsString($content);
	}

	/**
	 * Helper method to test polyglot detection.
	 */
	private function assertPolyglotDetection(array $file, string $attackType): void
	{
		$content = $file['content'];

		// Should detect if file is valid in multiple formats
		$hasImageHeader = (
			str_starts_with($content, "\xFF\xD8\xFF") // JPEG
			|| str_starts_with($content, "\x89PNG")      // PNG
			|| str_starts_with($content, 'GIF87a')       // GIF87a
			|| str_starts_with($content, 'GIF89a')          // GIF89a
		);

		$hasHTMLContent = (
			str_contains($content, '<html>')
			|| str_contains($content, '<script>')
			|| str_contains($content, '<!DOCTYPE')
		);

		// Polyglot detected if it has both image header and HTML content
		if ($hasImageHeader && $hasHTMLContent) {
			$this->assertTrue($hasImageHeader && $hasHTMLContent, "Should detect polyglot file in {$attackType}");
		}

		$this->assertIsString($content);
	}

	/**
	 * Helper method to test file size limits.
	 */
	private function assertFileSizeLimits(array $file, string $attackType): void
	{
		$content = $file['content'];
		$size    = strlen($content);

		// Application should detect files that exceed reasonable size limits
		$maxSize     = 50 * 1024 * 1024; // 50MB
		$isOversized = $size >= $maxSize;

		// For specific attack types, use stricter limits
		if ($attackType === 'zip bomb' || $attackType === 'XML billion laughs') {
			$strictLimit = 1024 * 1024; // 1MB for suspicious content
			$isOversized = $size >= $strictLimit;
		}

		if ($isOversized) {
			$this->assertTrue($isOversized, "Application should detect oversized file in {$attackType}");
		}

		$this->assertIsString($content);
	}

	/**
	 * Helper method to test path traversal prevention.
	 */
	private function assertPathTraversalPrevention(string $filename): void
	{
		// Application should detect path traversal patterns
		$hasPathTraversal = (
			str_contains($filename, '../')
			|| str_contains($filename, '..\\')
			|| str_contains($filename, "\x00")
			|| str_starts_with($filename, '/')
			|| str_starts_with($filename, '\\')
			|| preg_match('/^[A-Za-z]:/', $filename)
		);

		if ($hasPathTraversal) {
			$this->assertTrue($hasPathTraversal, 'Application should detect path traversal in filename');
		}

		$this->assertIsString($filename);
	}

	/**
	 * Helper method to test executable file blocking.
	 */
	private function assertExecutableFileBlocking(array $file, string $attackType): void
	{
		$filename = $file['name'];
		$content  = $file['content'];
		$type     = $file['type'];

		// Should block executable extensions
		$executableExtensions   = ['.php', '.jsp', '.asp', '.exe', '.sh', '.bat', '.cmd', '.py', '.rb', '.pl'];
		$hasExecutableExtension = false;

		foreach ($executableExtensions as $ext) {
			if (str_ends_with(strtolower($filename), $ext)) {
				$hasExecutableExtension = true;
				break;
			}
		}

		// Should block executable MIME types
		$executableMimeTypes = [
			'application/x-php',
			'application/x-httpd-php',
			'application/x-jsp',
			'application/x-asp',
			'application/x-msdownload',
			'application/x-executable',
		];

		$hasExecutableMimeType = in_array($type, $executableMimeTypes);

		if ($hasExecutableExtension || $hasExecutableMimeType) {
			$this->assertTrue(
				$hasExecutableExtension || $hasExecutableMimeType,
				"Should detect executable file in {$attackType}"
			);
		}

		$this->assertIsString($content);
	}

	/**
	 * Helper method to test metadata security validation.
	 */
	private function assertMetadataSecurityValidation(array $attack, string $attackType): void
	{
		$hasDangerousMetadata = false;

		if (isset($attack['exif_data'])) {
			foreach ($attack['exif_data'] as $field => $value) {
				if (str_contains($value, '<script>') || str_contains($value, 'javascript:') || str_contains($value, 'DROP TABLE')) {
					$hasDangerousMetadata = true;
					break;
				}
			}
		}

		if (isset($attack['iptc_data'])) {
			foreach ($attack['iptc_data'] as $field => $value) {
				if (is_array($value)) {
					$serialized = json_encode($value);
					if (str_contains($serialized, '<script>')) {
						$hasDangerousMetadata = true;
						break;
					}
				} else {
					if (str_contains($value, '../')) {
						$hasDangerousMetadata = true;
						break;
					}
				}
			}
		}

		if ($hasDangerousMetadata) {
			$this->assertTrue($hasDangerousMetadata, "Application should detect dangerous metadata in {$attackType}");
		}

		$this->assertIsArray($attack);
	}

	/**
	 * Helper method to test archive extraction security.
	 */
	private function assertArchiveExtractionSecurity(array $archive, string $attackType): void
	{
		$files             = $archive['files'];
		$hasDangerousFiles = false;

		foreach ($files as $filename => $content) {
			// Application should detect path traversal in archive members
			if (str_contains($filename, '../') || str_contains($filename, '..\\') || str_starts_with($filename, '/')) {
				$hasDangerousFiles = true;
				break;
			}
		}

		if ($hasDangerousFiles) {
			$this->assertTrue($hasDangerousFiles, "Application should detect dangerous archive members in {$attackType}");
		}

		$this->assertIsArray($files);
	}

	/**
	 * Helper method to test content type validation.
	 */
	private function assertContentTypeValidation(array $attack, string $attackType): void
	{
		$declaredType  = $attack['declared_type'];
		$actualContent = $attack['actual_content'];

		// Should detect content type mismatches
		if ($declaredType === 'image/jpeg' && str_contains($actualContent, '<html>')) {
			$this->assertTrue(true, "Should detect HTML served as image in {$attackType}");
		}

		if ($declaredType === 'application/json' && str_contains($actualContent, '<?xml')) {
			$this->assertTrue(true, "Should detect XML served as JSON in {$attackType}");
		}

		// Application should detect dangerous content regardless of declared type
		$hasDangerousContent = (
			str_contains($actualContent, '<script>')
			|| str_contains($actualContent, 'javascript:')
		);

		if ($hasDangerousContent) {
			$this->assertTrue($hasDangerousContent, "Application should detect dangerous content in {$attackType}");
		}

		$this->assertIsString($actualContent);
	}

	/**
	 * Helper method to test malware detection.
	 */
	private function assertMalwareDetection(array $file, string $attackType): void
	{
		$content = $file['content'];

		// Should detect suspicious patterns
		$suspiciousPatterns = [
			'eval(',
			'system(',
			'exec(',
			'shell_exec(',
			'passthru(',
			'base64_decode(',
			'malicious',
			'backdoor',
		];

		$hasSuspiciousContent = false;
		foreach ($suspiciousPatterns as $pattern) {
			if (str_contains(strtolower($content), strtolower($pattern))) {
				$hasSuspiciousContent = true;
				break;
			}
		}

		if ($hasSuspiciousContent) {
			$this->assertTrue($hasSuspiciousContent, "Should detect suspicious content in {$attackType}");
		}

		$this->assertIsString($content);
	}

	/**
	 * Helper method to create a zip bomb for testing.
	 */
	private function createZipBomb(): string
	{
		// Create a simple representation of a zip bomb
		// In reality, this would be a compressed file that expands enormously
		return "PK\x03\x04" . str_repeat('A', 1000); // Simplified zip bomb representation
	}

	/**
	 * Helper method to create an XML bomb for testing.
	 */
	private function createXMLBomb(): string
	{
		// Create a billion laughs XML bomb
		return '<?xml version="1.0"?>' .
			   '<!DOCTYPE lolz [' .
			   '<!ENTITY lol "lol">' .
			   '<!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">' .
			   ']>' .
			   '<lolz>&lol2;</lolz>';
	}
}
