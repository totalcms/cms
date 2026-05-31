<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Support\Config;

/**
 * Phase 3 of the git-first template workflow: the admin exposes the edit lock
 * and per-template source layer to its Twig views. AdminTwigAdapter is a large
 * readonly adapter, so we instantiate it without the constructor and inject
 * only the two collaborators these methods touch.
 */
final class AdminTwigAdapterTemplatesTest extends TestCase
{
	private string $tmpRoot;

	protected function setUp(): void
	{
		$this->tmpRoot = sys_get_temp_dir() . '/tcms-ata-' . uniqid('', true);
		mkdir($this->tmpRoot . '/tcms-data/builder/pages', 0o777, true);
		mkdir($this->tmpRoot . '/project', 0o777, true);
	}

	protected function tearDown(): void
	{
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($this->tmpRoot);
	}

	private function makePaths(): BuilderTemplatePaths
	{
		$config          = $this->createMock(Config::class);
		$config->root    = $this->tmpRoot . '/project';
		$config->datadir = $this->tmpRoot . '/tcms-data';
		$config->builder = [];

		return new BuilderTemplatePaths($config);
	}

	private function makeAdapter(BuilderTemplatePaths $paths, TemplateLister $lister): AdminTwigAdapter
	{
		$adapter    = (new \ReflectionClass(AdminTwigAdapter::class))->newInstanceWithoutConstructor();
		$reflection = new \ReflectionClass($adapter);
		$reflection->getProperty('paths')->setValue($adapter, $paths);
		$reflection->getProperty('templateLister')->setValue($adapter, $lister);

		return $adapter;
	}

	public function testTemplatesLockedReflectsResolver(): void
	{
		$lister = $this->createMock(TemplateLister::class);

		$unlocked = $this->makeAdapter($this->makePaths(), $lister);
		$this->assertFalse($unlocked->templatesLocked());

		// Git-managed: the project builder dir exists -> locked.
		mkdir($this->tmpRoot . '/project/builder', 0o777, true);
		$locked = $this->makeAdapter($this->makePaths(), $lister);
		$this->assertTrue($locked->templatesLocked());
	}

	public function testTemplatesByFolderTagsSourceLayer(): void
	{
		file_put_contents($this->tmpRoot . '/tcms-data/builder/pages/about.twig', '<h1>about</h1>');

		$lister = $this->createMock(TemplateLister::class);
		$lister->method('listBuilderTemplates')->willReturn(['pages/about']);

		$adapter = $this->makeAdapter($this->makePaths(), $lister);
		$folders = $adapter->templatesByFolder();

		$entry = $folders['Pages'][0];
		$this->assertSame('about', $entry['id']);
		$this->assertSame('pages/about', $entry['path']);
		$this->assertSame(BuilderTemplatePaths::LAYER_DATA, $entry['source']);
	}
}
