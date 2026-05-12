<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Template\Repository;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Domain\Template\Repository\TemplateRepository;

/**
 * Targeted tests for `TemplateRepository::listBuilderTemplates`. We don't
 * mock the filesystem here — the file-listing behavior IS the contract.
 */
final class TemplateRepositoryTest extends TestCase
{
	private string $tmpRoot;
	private TemplateRepository $repo;

	protected function setUp(): void
	{
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-template-repo-' . uniqid();
		mkdir($this->tmpRoot . '/builder/pages', 0755, true);
		mkdir($this->tmpRoot . '/builder/layouts', 0755, true);
		mkdir($this->tmpRoot . '/builder/.history/pages/about', 0755, true);
		mkdir($this->tmpRoot . '/builder/.history/layouts/default', 0755, true);

		$flysystem = new Filesystem(new LocalFilesystemAdapter($this->tmpRoot));
		$storage   = new StorageFilesystemAdapter($flysystem);

		$this->repo = new TemplateRepository($storage);
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmpRoot);
	}

	public function testRecursiveListingExcludesHistorySnapshots(): void
	{
		// Real templates
		file_put_contents($this->tmpRoot . '/builder/pages/about.twig', '<h1>about</h1>');
		file_put_contents($this->tmpRoot . '/builder/pages/contact.twig', '<h1>contact</h1>');
		file_put_contents($this->tmpRoot . '/builder/layouts/default.twig', '<html></html>');

		// History snapshots — these are real .twig files but they're version
		// payloads, not editable templates. Must not appear in the listing.
		file_put_contents($this->tmpRoot . '/builder/.history/pages/about/1714604700.twig', '<h1>old about</h1>');
		file_put_contents($this->tmpRoot . '/builder/.history/layouts/default/1714604800.twig', '<html>old</html>');

		$result = $this->repo->listBuilderTemplates(null, true);

		$this->assertContains('pages/about', $result);
		$this->assertContains('pages/contact', $result);
		$this->assertContains('layouts/default', $result);

		foreach ($result as $path) {
			$this->assertStringNotContainsString('.history', $path, "history snapshot leaked: $path");
		}
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
