<?php

declare(strict_types=1);

namespace Tests\Unit\Property\Service;

use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Property\Service\ImageSaver;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Palette + EXIF extraction is one method shared by the upload and rebuild
 * paths. The rebuild copy used to swallow palette failures that the upload
 * copies logged; both now log and neither is fatal.
 */
final class DerivesImageDataTest extends TestCase
{
	private string $root = '';

	protected function setUp(): void
	{
		$this->root = sys_get_temp_dir() . '/tcms-derive-' . uniqid();
		mkdir($this->root . '/blog/post/photo', 0755, true);
		file_put_contents($this->root . '/blog/post/photo/not-an-image.jpg', 'definitely not a jpeg');
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	public function testRebuildLogsAPaletteFailureAndStillReturnsTheImage(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('warning')
			->with('Palette generation failed', $this->callback(fn (array $ctx): bool => $ctx['property'] === 'photo' && isset($ctx['error'])));

		$storage = $this->createMock(PropertyRepository::class);
		$storage->method('listPropertyFiles')->willReturn([['name' => 'not-an-image.jpg', 'path' => 'blog/post/photo/not-an-image.jpg']]);
		$storage->method('fileSize')->willReturn(21);
		$storage->method('mimeType')->willReturn('text/plain');

		$config          = $this->createMock(Config::class);
		$config->datadir = $this->root;

		$saver = new ImageSaver(
			$storage,
			$this->createMock(PropertyFetcher::class),
			$this->createMock(ObjectSaver::class),
			$this->createMock(ObjectPatcher::class),
			$this->createMock(ObjectFetcher::class),
			new LoggerFactory(['test' => $logger, 'level' => Level::Debug]),
			$config,
		);

		$image = $saver->rebuildFromStorage('blog', 'post', 'photo');

		$this->assertInstanceOf(ImageData::class, $image);
		$this->assertSame('not-an-image.jpg', $image->name);
		$this->assertSame([], $image->palette);
	}
}
