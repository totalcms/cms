<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Filesystem;

use PHPUnit\Framework\TestCase;
use TotalCMS\Infrastructure\Filesystem\FileUtils;

final class FileUtilsTest extends TestCase
{
	public function testFileSizeStringForBytes(): void
	{
		$this->assertSame('0.0 B', FileUtils::fileSizeString(0));
		$this->assertSame('1.0 B', FileUtils::fileSizeString(1));
		$this->assertSame('512.0 B', FileUtils::fileSizeString(512));
		$this->assertSame('1023.0 B', FileUtils::fileSizeString(1023));
	}

	public function testFileSizeStringForKilobytes(): void
	{
		$this->assertSame('1.0 KB', FileUtils::fileSizeString(1024));
		$this->assertSame('1.5 KB', FileUtils::fileSizeString(1536));
		$this->assertSame('10.0 KB', FileUtils::fileSizeString(10240));
		$this->assertSame('500.0 KB', FileUtils::fileSizeString(512000));
	}

	public function testFileSizeStringForMegabytes(): void
	{
		$this->assertSame('1.0 MB', FileUtils::fileSizeString(1048576));
		$this->assertSame('2.5 MB', FileUtils::fileSizeString(2621440));
		$this->assertSame('100.0 MB', FileUtils::fileSizeString(104857600));
	}

	public function testFileSizeStringForGigabytes(): void
	{
		$this->assertSame('1.0 GB', FileUtils::fileSizeString(1073741824));
		$this->assertSame('2.0 GB', FileUtils::fileSizeString(2147483648));
	}

	public function testFileSizeStringForTerabytes(): void
	{
		$this->assertSame('1.0 TB', FileUtils::fileSizeString(1099511627776));
	}

	public function testFileSizeStringFormatsWithOneDecimal(): void
	{
		// 1.5 KB = 1536 bytes
		$result = FileUtils::fileSizeString(1536);
		$this->assertMatchesRegularExpression('/^\d+\.\d+ \w+$/', $result);
	}
}
