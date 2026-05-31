<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Template\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Template\Service\TemplateMigrationService;
use TotalCMS\Support\Config;

/**
 * importDirectory() scaffolds starter templates into the active write target —
 * `project-root/builder` for a git-managed project, else `tcms-data/builder`
 * (git-first template workflow, Phase 5). So `tcms builder:init` lands a
 * starter's templates where the project can commit them.
 */
final class TemplateMigrationServiceTest extends TestCase
{
	private string $tmpRoot;
	private string $sourceDir;

	protected function setUp(): void
	{
		$this->tmpRoot   = sys_get_temp_dir() . '/tcms-migration-' . uniqid('', true);
		$this->sourceDir = $this->tmpRoot . '/starter/layouts';
		mkdir($this->sourceDir, 0o777, true);
		mkdir($this->tmpRoot . '/project', 0o777, true);
		file_put_contents($this->sourceDir . '/default.twig', '<html>starter</html>');
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

	private function makeService(): TemplateMigrationService
	{
		$config          = $this->createMock(Config::class);
		$config->root    = $this->tmpRoot . '/project';
		$config->datadir = $this->tmpRoot . '/tcms-data';
		$config->builder = [];

		// importDirectory does native I/O via the resolver; the storage adapter
		// is only used by migrateFromLegacyTemplates, which these tests don't hit.
		return new TemplateMigrationService(
			$this->createMock(StorageAdapterInterface::class),
			new BuilderTemplatePaths($config),
		);
	}

	public function testImportDirectoryWritesToDataLayerWhenAdminFirst(): void
	{
		$imported = $this->makeService()->importDirectory($this->sourceDir, 'layouts');

		$this->assertSame(1, $imported);
		$this->assertFileExists($this->tmpRoot . '/tcms-data/builder/layouts/default.twig');
		$this->assertSame('<html>starter</html>', file_get_contents($this->tmpRoot . '/tcms-data/builder/layouts/default.twig'));
	}

	public function testImportDirectoryWritesToProjectWhenGitManaged(): void
	{
		mkdir($this->tmpRoot . '/project/builder', 0o777, true);

		$this->makeService()->importDirectory($this->sourceDir, 'layouts');

		$this->assertFileExists($this->tmpRoot . '/project/builder/layouts/default.twig');
		$this->assertFileDoesNotExist($this->tmpRoot . '/tcms-data/builder/layouts/default.twig');
	}
}
