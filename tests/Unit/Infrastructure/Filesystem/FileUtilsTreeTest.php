<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Filesystem;

use PHPUnit\Framework\TestCase;
use TotalCMS\Infrastructure\Filesystem\FileUtils;

/**
 * The tree copy three installers used to hand-roll, and the php.ini size
 * parser two callers used to carry.
 */
final class FileUtilsTreeTest extends TestCase
{
	private string $root = '';

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/tcms-tree-' . uniqid();
		mkdir($this->root . '/src/sub', 0755, true);
		file_put_contents($this->root . '/src/a.txt', 'a');
		file_put_contents($this->root . '/src/sub/b.txt', 'b');
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	public function testCopiesEveryFileUnderTheTarget(): void
	{
		$result = FileUtils::copyTree($this->root . '/src', $this->root . '/dst');

		// Directory iteration order is filesystem-dependent.
		sort($result['copied']);
		$this->assertSame(['a.txt', 'sub/b.txt'], $result['copied']);
		$this->assertSame([], $result['skipped']);
		$this->assertSame([], $result['failed']);
		$this->assertSame('b', file_get_contents($this->root . '/dst/sub/b.txt'));
	}

	public function testWithoutForceExistingFilesAreSkippedNotOverwritten(): void
	{
		mkdir($this->root . '/dst', 0755, true);
		file_put_contents($this->root . '/dst/a.txt', 'keep');

		$result = FileUtils::copyTree($this->root . '/src', $this->root . '/dst', force: false);

		$this->assertSame(['sub/b.txt'], $result['copied']);
		$this->assertSame(['a.txt'], $result['skipped']);
		$this->assertSame('keep', file_get_contents($this->root . '/dst/a.txt'));
	}

	public function testACustomCopierCanTransformAndFail(): void
	{
		$result = FileUtils::copyTree($this->root . '/src', $this->root . '/dst', copy: static function (string $from, string $to): bool {
			if (str_ends_with($from, 'b.txt')) {
				return false;
			}

			return file_put_contents($to, strtoupper((string)file_get_contents($from))) !== false;
		});

		$this->assertSame(['a.txt'], $result['copied']);
		$this->assertSame(['sub/b.txt'], $result['failed']);
		$this->assertSame('A', file_get_contents($this->root . '/dst/a.txt'));
	}

	public function testAMissingSourceCopiesNothing(): void
	{
		$this->assertSame(['copied' => [], 'skipped' => [], 'failed' => []], FileUtils::copyTree($this->root . '/none', $this->root . '/dst'));
	}

	/** @return iterable<string, array{string, int}> */
	public static function iniSizes(): iterable
	{
		yield 'megabytes' => ['8M', 8 * 1024 * 1024];
		yield 'kilobytes' => ['512K', 512 * 1024];
		yield 'gigabytes' => ['1G', 1024 * 1024 * 1024];
		yield 'lowercase' => ['2m', 2 * 1024 * 1024];
		yield 'bare bytes' => ['1000', 1000];
		yield 'padded' => [' 4M ', 4 * 1024 * 1024];
		yield 'empty' => ['', 0];
	}

	/** @dataProvider iniSizes */
	public function testIniSizeToBytes(string $ini, int $bytes): void
	{
		$this->assertSame($bytes, FileUtils::iniSizeToBytes($ini));
	}
}
