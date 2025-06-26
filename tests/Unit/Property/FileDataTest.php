<?php

namespace Tests\Unit\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\ListData;
use TotalCMS\Domain\Property\Data\PasswordData;

#[CoversClass(FileData::class)]
final class FileDataTest extends TestCase
{
	public function testCreatesFileDataWithAllProperties(): void
	{
		$fileData = [
			'name'       => 'document.pdf',
			'download'   => 'My Document.pdf',
			'mime'       => 'application/pdf',
			'size'       => 1024768,
			'comments'   => 'Important document',
			'protected'  => false,
			'tags'       => ['document', 'important'],
			'password'   => 'secret123',
			'uploadDate' => '2023-01-15T10:30:00+00:00',
			'count'      => 5,
		];

		$data = new FileData($fileData);

		$this->assertSame('document.pdf', $data->name);
		$this->assertSame('My Document.pdf', $data->download);
		$this->assertSame('application/pdf', $data->mime);
		$this->assertSame(1024768, $data->size);
		$this->assertSame('Important document', $data->comments);
		$this->assertFalse($data->protected);
		$this->assertSame(5, $data->count);
		$this->assertInstanceOf(ListData::class, $data->tags);
		$this->assertInstanceOf(PasswordData::class, $data->password);
		$this->assertInstanceOf(DateData::class, $data->uploadDate);
	}

	public function testUsesDefaultsForMissingProperties(): void
	{
		$data = new FileData();

		$this->assertSame('', $data->name);
		$this->assertSame('', $data->download); // Same as name when both empty
		$this->assertSame('', $data->mime);
		$this->assertSame(0, $data->size);
		$this->assertSame('', $data->comments);
		$this->assertTrue($data->protected); // Default is protected
		$this->assertSame(0, $data->count);
		$this->assertSame([], $data->tags->list);
		$this->assertSame('', $data->password->hash);
	}

	public function testSetsDownloadNameSameAsFilenameWhenNotProvided(): void
	{
		$fileData = ['name' => 'test.txt'];
		$data     = new FileData($fileData);

		$this->assertSame('test.txt', $data->name);
		$this->assertSame('test.txt', $data->download);
	}

	public function testHandlesDangerousFilenames(): void
	{
		$dangerousNames = [
			'../../../etc/passwd',
			'<script>alert(1)</script>.txt',
			'file with spaces.txt',
			'CON.txt', // Windows reserved name
		];

		foreach ($dangerousNames as $name) {
			$data = new FileData(['name' => $name]);
			// The class doesn't sanitize names, just stores them
			$this->assertSame($name, $data->name);
		}
	}

	public function testHandlesDangerousMimeTypes(): void
	{
		$dangerousMimes = [
			'application/x-executable',
			'text/html', // Could contain scripts
			'text/javascript',
			'application/x-php',
		];

		foreach ($dangerousMimes as $mime) {
			$data = new FileData(['mime' => $mime]);
			$this->assertSame($mime, $data->mime);
		}
	}

	public function testValidatesFileSizeLimits(): void
	{
		$sizes = [0, 1024, 1048576, PHP_INT_MAX];

		foreach ($sizes as $size) {
			$data = new FileData(['size' => $size]);
			$this->assertSame($size, $data->size);
		}

		// Test negative size
		$data = new FileData(['size' => -1]);
		$this->assertSame(-1, $data->size); // FileData doesn't validate, just stores
	}

	public function testHandlesMaliciousComments(): void
	{
		$maliciousComments = [
			'<script>alert("xss")</script>',
			"'; DROP TABLE files; --",
			'<?php system("rm -rf /"); ?>',
		];

		foreach ($maliciousComments as $comment) {
			$data = new FileData(['comments' => $comment]);
			// Comments are stored as-is, sanitization should happen at display time
			$this->assertSame($comment, $data->comments);
		}
	}

	public function testTransformsToArrayCorrectly(): void
	{
		$fileData = [
			'name'      => 'test.txt',
			'download'  => 'Test File.txt',
			'mime'      => 'text/plain',
			'size'      => 1024,
			'comments'  => 'Test comments',
			'protected' => true,
			'tags'      => ['test', 'file'],
			'password'  => 'secret',
			'count'     => 3,
		];

		$data        = new FileData($fileData);
		$transformed = $data->transform();

		$this->assertIsArray($transformed);
		$this->assertSame('test.txt', $transformed['name']);
		$this->assertSame('Test File.txt', $transformed['download']);
		$this->assertSame('text/plain', $transformed['mime']);
		$this->assertSame(1024, $transformed['size']);
		$this->assertSame('Test comments', $transformed['comments']);
		$this->assertTrue($transformed['protected']);
		$this->assertSame(3, $transformed['count']);
		$this->assertIsArray($transformed['tags']);
		$this->assertIsString($transformed['password']);
		$this->assertIsString($transformed['uploadDate']);
	}

	public function testSerializesToJsonStringCorrectly(): void
	{
		$data = new FileData(['name' => 'test.txt']);
		$json = (string)$data;

		$this->assertIsString($json);
		$this->assertStringContainsString('"name":"test.txt"', $json);

		$decoded = json_decode($json, true);
		$this->assertIsArray($decoded);
		$this->assertSame('test.txt', $decoded['name']);
	}

	public function testCreatesPasswordHashWhenProvided(): void
	{
		$data = new FileData(['password' => 'secret123']);

		$this->assertNotSame('secret123', $data->password->hash);
		$this->assertNotEmpty($data->password->hash);
		$this->assertTrue(password_verify('secret123', $data->password->hash));
	}

	public function testProcessesTagsListCorrectly(): void
	{
		$tags = ['document', 'important', 'work'];
		$data = new FileData(['tags' => $tags]);

		$this->assertSame($tags, $data->tags->list);
	}

	public function testHandlesMaliciousTags(): void
	{
		$maliciousTags = [
			'<script>alert(1)</script>',
			'javascript:void(0)',
			"'; DROP TABLE files; --",
		];

		$data = new FileData(['tags' => $maliciousTags]);

		// Tags are stored as strings, sanitization should happen at display
		$this->assertSame($maliciousTags, $data->tags->list);
	}

	public function testHandlesExtremelyLongFilenames(): void
	{
		$longName = str_repeat('a', 1000) . '.txt';
		$data     = new FileData(['name' => $longName]);

		$this->assertSame($longName, $data->name);
	}

	public function testAcceptsSettingsParameter(): void
	{
		$settings = ['validation' => 'strict', 'maxSize' => 1048576];
		$data     = new FileData([], $settings);

		$this->assertSame($settings, $data->settings);
	}

	public function testStoresPathTraversalAttemptsWithoutModification(): void
	{
		$traversalAttempts = [
			'../../../etc/passwd',
			'..\\..\\..\\windows\\system32\\hosts',
			'/etc/shadow',
			'C:\\Windows\\System32\\config\\SAM',
		];

		foreach ($traversalAttempts as $path) {
			$data = new FileData(['name' => $path]);
			// FileData stores the path as-is, validation should happen elsewhere
			$this->assertSame($path, $data->name);
		}
	}
}
