<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Media;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
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
		$first = $this->assembler->receive($this->part('big.bin', 'AAA'), ['dzchunkindex' => 0, 'dztotalchunkcount' => 2]);
		$this->assertNull($first, 'more chunks are expected');
		$this->assertFileExists($this->tmp . '/big.bin.part0');

		$path = $this->assembler->receive($this->part('big.bin', 'BBB'), ['dzchunkindex' => 1, 'dztotalchunkcount' => 2]);

		$this->assertSame($this->tmp . '/big.bin', $path);
		$this->assertSame('AAABBB', file_get_contents($path));
		$this->assertFileDoesNotExist($this->tmp . '/big.bin.part0');
		$this->assertFileDoesNotExist($this->tmp . '/big.bin.part1');
	}

	public function testASinglePartUploadIsTheOneChunkCase(): void
	{
		$path = $this->assembler->receive($this->part('small.txt', 'hi'), []);

		$this->assertSame($this->tmp . '/small.txt', $path);
		$this->assertSame('hi', file_get_contents($path));
	}

	public function testAPhpUploadErrorIsReportedByName(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('File was only partially uploaded');

		$this->assembler->receive($this->part('x.txt', '', UPLOAD_ERR_PARTIAL), []);
	}
}
