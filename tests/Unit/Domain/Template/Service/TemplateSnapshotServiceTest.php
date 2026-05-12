<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Template\Service;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Template\Repository\TemplateSnapshotRepository;
use TotalCMS\Domain\Template\Service\TemplateSnapshotService;

final class TemplateSnapshotServiceTest extends TestCase
{
	private string $tmpDir;
	private TemplateSnapshotService $service;
	private StorageFilesystemAdapter $adapter;

	protected function setUp(): void
	{
		$this->tmpDir  = sys_get_temp_dir() . '/tcms-snapshot-test-' . uniqid();
		mkdir($this->tmpDir, 0755, true);

		$flysystem     = new Filesystem(new LocalFilesystemAdapter($this->tmpDir));
		$this->adapter = new StorageFilesystemAdapter($flysystem);
		$this->service = new TemplateSnapshotService(new TemplateSnapshotRepository($this->adapter));
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmpDir);
	}

	public function testCaptureWritesSnapshot(): void
	{
		$this->service->capture('about', 'pages', '<h1>About</h1>');

		$versions = $this->service->listVersions('about', 'pages');
		$this->assertCount(1, $versions);

		$content = $this->service->readVersion('about', 'pages', $versions[0]);
		$this->assertSame('<h1>About</h1>', $content);
	}

	public function testCaptureSkipsEmptyContent(): void
	{
		$this->service->capture('about', 'pages', '');

		$this->assertSame([], $this->service->listVersions('about', 'pages'));
	}

	public function testCaptureRejectsPathTraversal(): void
	{
		$this->service->capture('../etc/passwd', 'pages', 'leak');
		$this->service->capture('about', '../../../etc', 'leak');

		// Nothing should have been written under .history/
		$this->assertSame([], $this->service->listVersions('../etc/passwd', 'pages'));
	}

	public function testListVersionsReturnsTimestampsNewestFirst(): void
	{
		$this->service->capture('about', 'pages', 'v1');
		// Force distinct timestamps even on a fast machine
		sleep(1);
		$this->service->capture('about', 'pages', 'v2');

		$versions = $this->service->listVersions('about', 'pages');
		$this->assertCount(2, $versions);
		$this->assertGreaterThan($versions[1], $versions[0]);
	}

	public function testListVersionsReturnsEmptyForUnknownTemplate(): void
	{
		$this->assertSame([], $this->service->listVersions('never-saved', 'pages'));
	}

	public function testReadVersionThrowsForMissingSnapshot(): void
	{
		$this->expectException(\DomainException::class);
		$this->service->readVersion('about', 'pages', 1);
	}

	public function testNestedPathWritesIntoNestedHistoryFolder(): void
	{
		$this->service->capture('post', 'pages/blog', 'nested');

		$versions = $this->service->listVersions('post', 'pages/blog');
		$this->assertCount(1, $versions);
		$this->assertSame('nested', $this->service->readVersion('post', 'pages/blog', $versions[0]));
	}

	public function testCoincidingPathsKeepSeparateHistories(): void
	{
		// pages/blog.twig vs pages/blog/post.twig — two different templates that
		// would land near each other in `.history/`. Their histories must stay
		// independent.
		$this->service->capture('blog', 'pages', 'category index content');
		$this->service->capture('post', 'pages/blog', 'inner post content');

		$blogVersions = $this->service->listVersions('blog', 'pages');
		$postVersions = $this->service->listVersions('post', 'pages/blog');

		$this->assertCount(1, $blogVersions);
		$this->assertCount(1, $postVersions);
		$this->assertSame('category index content', $this->service->readVersion('blog', 'pages', $blogVersions[0]));
		$this->assertSame('inner post content', $this->service->readVersion('post', 'pages/blog', $postVersions[0]));
	}

	public function testRapidDoubleCaptureKeepsBothSnapshots(): void
	{
		// Two captures within the same wall-clock second: timestamps must
		// stay distinct so neither snapshot is silently overwritten.
		$this->service->capture('about', 'pages', 'first');
		$this->service->capture('about', 'pages', 'second');

		$versions = $this->service->listVersions('about', 'pages');
		$this->assertCount(2, $versions);
	}

	public function testPruneKeepsMaxVersionsMostRecent(): void
	{
		$max = TemplateSnapshotService::MAX_VERSIONS;

		for ($i = 0; $i < $max + 5; $i++) {
			$this->service->capture('about', 'pages', "version $i");
		}

		$versions = $this->service->listVersions('about', 'pages');
		$this->assertCount($max, $versions);
	}

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = scandir($dir);
		if ($items === false) {
			return;
		}
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}
}
