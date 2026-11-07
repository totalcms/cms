<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Media;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\UploadedFile;
use TotalCMS\Domain\Media\Exception\UploadRejectedException;
use TotalCMS\Domain\Media\Service\ChunkedUploadAssembler;
use TotalCMS\Support\Config;

final class ChunkedUploadAssemblerTest extends TestCase
{
	private string $tmp = '';
	private ChunkedUploadAssembler $assembler;

	protected function setUp(): void
	{
		$this->tmp      = sys_get_temp_dir() . '/tcms-chunks-' . uniqid();
		$config         = $this->createMock(Config::class);
		$config->tmpdir = $this->tmp;

		$this->assembler = new ChunkedUploadAssembler($config);
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->tmp));
	}

	private function part(string $name, string $content, int $error = UPLOAD_ERR_OK): UploadedFileInterface
	{
		$file = $this->createMock(UploadedFileInterface::class);
		$file->method('getError')->willReturn($error);
		$file->method('getClientFilename')->willReturn($name);
		$file->method('moveTo')->willReturnCallback(static function (string $target) use ($content): void {
			file_put_contents($target, $content);
		});

		return $file;
	}

	public function testChunksAreHeldUntilTheLastOneThenJoinedInOrder(): void
	{
		$first = $this->assembler->receive($this->part('big.bin', 'AAA'), ['dzuuid' => 'u1', 'dzchunkindex' => 0, 'dztotalchunkcount' => 2]);
		$this->assertNull($first, 'more chunks are expected');
		$dir = $this->tmp . '/upload-' . hash('sha256', 'u1');
		$this->assertFileExists($dir . '/big.bin.part0');

		$path = $this->assembler->receive($this->part('big.bin', 'BBB'), ['dzuuid' => 'u1', 'dzchunkindex' => 1, 'dztotalchunkcount' => 2]);

		$this->assertSame($dir . '/big.bin', $path);
		$this->assertSame('AAABBB', file_get_contents($path));
		$this->assertFileDoesNotExist($dir . '/big.bin.part0');
		$this->assertFileDoesNotExist($dir . '/big.bin.part1');
	}

	public function testTwoUploadsWithOneFilenameAndDifferentUuidsDoNotMix(): void
	{
		$a = ['dzuuid' => 'A', 'dztotalchunkcount' => 2];
		$b = ['dzuuid' => 'B', 'dztotalchunkcount' => 2];

		$this->assembler->receive($this->part('same.txt', 'a1'), $a + ['dzchunkindex' => 0]);
		$this->assembler->receive($this->part('same.txt', 'b1'), $b + ['dzchunkindex' => 0]);
		$pathA = $this->assembler->receive($this->part('same.txt', 'a2'), $a + ['dzchunkindex' => 1]);
		$pathB = $this->assembler->receive($this->part('same.txt', 'b2'), $b + ['dzchunkindex' => 1]);

		$this->assertNotSame($pathA, $pathB);
		$this->assertSame('a1a2', file_get_contents((string)$pathA));
		$this->assertSame('b1b2', file_get_contents((string)$pathB));
	}

	public function testAChunkedUploadWithoutUuidAssemblesByFilename(): void
	{
		$first = $this->assembler->receive($this->part('legacy.bin', 'AA'), ['chunkindex' => 0, 'totalchunkcount' => 2]);
		$this->assertNull($first);

		$path = $this->assembler->receive($this->part('legacy.bin', 'BB'), ['chunkindex' => 1, 'totalchunkcount' => 2]);

		$this->assertSame('AABB', file_get_contents((string)$path));
	}

	public function testAnUntrustedUuidNeverReachesThePath(): void
	{
		$path = $this->assembler->receive($this->part('x.txt', 'hi'), ['dzuuid' => '../../evil']);

		$this->assertStringStartsWith($this->tmp . '/upload-', (string)$path);
		$this->assertSame(hash('sha256', '../../evil'), substr(basename(dirname((string)$path)), 7));
	}

	public function testCleanupRemovesTheUploadFolderAndIsSafeTwice(): void
	{
		$path = (string)$this->assembler->receive($this->part('x.txt', 'hi'), ['dzuuid' => 'c1']);
		$dir  = dirname($path);

		unlink($path);
		$this->assembler->cleanup($path);
		$this->assembler->cleanup($path);

		$this->assertDirectoryDoesNotExist($dir);
	}

	public function testCleanupNeverTouchesAPathOutsideAnUploadFolder(): void
	{
		mkdir($this->tmp, 0700, true);
		$outside = $this->tmp . '/fetched.bin';
		file_put_contents($outside, 'x');
		mkdir($this->tmp . '/keep');
		file_put_contents($this->tmp . '/keep/f.bin', 'y');

		$this->assembler->cleanup($outside);
		$this->assembler->cleanup($this->tmp . '/keep/f.bin');
		$this->assembler->cleanup('/etc/hosts');

		$this->assertFileExists($outside);
		$this->assertFileExists($this->tmp . '/keep/f.bin');
	}

	public function testStaleUploadFoldersAreSweptOnReceive(): void
	{
		$old = $this->tmp . '/upload-old';
		$new = $this->tmp . '/upload-new';
		mkdir($old, 0700, true);
		mkdir($new, 0700, true);
		touch($old, time() - 90000);

		$this->assembler->receive($this->part('x.txt', 'hi'), []);

		$this->assertDirectoryDoesNotExist($old);
		$this->assertDirectoryExists($new);
	}

	public function testASinglePartUploadIsTheOneChunkCase(): void
	{
		$path = $this->assembler->receive($this->part('small.txt', 'hi'), []);

		$this->assertStringStartsWith($this->tmp . '/upload-', (string)$path);
		$this->assertSame('small.txt', basename((string)$path));
		$this->assertSame('hi', file_get_contents((string)$path));
	}

	public function testAPhpUploadErrorIsReportedByName(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('File was only partially uploaded');

		$this->assembler->receive($this->part('x.txt', '', UPLOAD_ERR_PARTIAL), []);
	}

	public function testALastPartWithEarlierPartsMissingIsRefusedAndLeavesNothingBehind(): void
	{
		mkdir($this->tmp);
		$path = $this->tmp . '/incoming';
		file_put_contents($path, 'tail');
		$part = new UploadedFile($path, 'a.txt', 'text/plain', 4, UPLOAD_ERR_OK);

		try {
			$this->assembler->receive($part, ['dzuuid' => 'u', 'dzchunkindex' => '2', 'dztotalchunkcount' => '3']);
			$this->fail('A partial upload was assembled.');
		} catch (UploadRejectedException) {
			$this->assertSame([], glob($this->tmp . '/upload-*'));
		}
	}
}
