<?php

declare(strict_types=1);

namespace Tests\Unit\CLI\Formatter;

use PHPUnit\Framework\TestCase;
use TotalCMS\CLI\Formatter\FileHelper;

final class FileHelperTest extends TestCase
{
	private string $tmpFile;

	protected function setUp(): void
	{
		$this->tmpFile = tempnam(sys_get_temp_dir(), 'tcms-test-');
		file_put_contents($this->tmpFile, '{"test": true}');
	}

	protected function tearDown(): void
	{
		if (file_exists($this->tmpFile)) {
			unlink($this->tmpFile);
		}
	}

	public function testCreatesUploadedFileFromPath(): void
	{
		$uploaded = FileHelper::createUploadedFile($this->tmpFile);

		expect($uploaded->getError())->toBe(UPLOAD_ERR_OK);
		expect($uploaded->getSize())->toBe(filesize($this->tmpFile));
		expect($uploaded->getClientFilename())->toBe(basename($this->tmpFile));

		$contents = (string) $uploaded->getStream();
		expect($contents)->toBe('{"test": true}');
	}

	public function testThrowsOnMissingFile(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('File not found');

		FileHelper::createUploadedFile('/nonexistent/file.json');
	}
}
