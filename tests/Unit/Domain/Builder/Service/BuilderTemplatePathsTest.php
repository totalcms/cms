<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/**
 * The git-first template workflow (docs/planning/builder-git-workflow.md).
 *
 * BuilderTemplatePaths is the single resolver for the builder template read
 * hierarchy, the admin write target, and the edit lock. Git-management is
 * detected purely by the presence of `<project-root>/builder` — the same
 * directory-existence convention T3 uses for `tcms-data`. No config keys.
 */
final class BuilderTemplatePathsTest extends TestCase
{
	private string $tmpRoot;
	private string $projectRoot;
	private string $projectBuilder;
	private string $dataBuilder;

	protected function setUp(): void
	{
		$this->tmpRoot        = sys_get_temp_dir() . '/tcms-btp-' . uniqid('', true);
		$this->projectRoot    = $this->tmpRoot . '/project';
		$this->projectBuilder = $this->projectRoot . '/builder';
		$this->dataBuilder    = $this->tmpRoot . '/tcms-data/builder';
		// The data-layer builder dir always exists; the project one is created
		// per-test to model a git-managed project.
		mkdir($this->dataBuilder, 0o777, true);
		mkdir($this->projectRoot, 0o777, true);
	}

	protected function tearDown(): void
	{
		$this->removeDir($this->tmpRoot);
	}

	private function makePaths(): BuilderTemplatePaths
	{
		$config          = $this->createMock(Config::class);
		$config->root    = $this->projectRoot;
		$config->datadir = $this->tmpRoot . '/tcms-data';
		$config->builder = [];

		return new BuilderTemplatePaths($config);
	}

	private function removeDir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($dir);
	}

	// --- directory locations ---

	public function testProjectDirIsRootBuilder(): void
	{
		$this->assertSame($this->projectBuilder, $this->makePaths()->projectDir());
	}

	public function testDataDirIsDatadirBuilder(): void
	{
		$this->assertSame($this->dataBuilder, $this->makePaths()->dataDir());
	}

	public function testDefaultsDirIsPackagedUnderResources(): void
	{
		$this->assertSame(
			PathResolver::packageRoot() . '/resources/builder/defaults',
			$this->makePaths()->defaultsDir(),
		);
	}

	// --- isProjectManaged (directory existence) ---

	public function testIsProjectManagedFalseWhenProjectBuilderAbsent(): void
	{
		$this->assertFalse($this->makePaths()->isProjectManaged());
	}

	public function testIsProjectManagedTrueWhenProjectBuilderExists(): void
	{
		mkdir($this->projectBuilder, 0o777, true);

		$this->assertTrue($this->makePaths()->isProjectManaged());
	}

	// --- locked == isProjectManaged (no env, no setting) ---

	public function testLockedFalseForAdminFirstProject(): void
	{
		$this->assertFalse($this->makePaths()->locked());
	}

	public function testLockedTrueWheneverGitManaged(): void
	{
		mkdir($this->projectBuilder, 0o777, true);

		// Locked everywhere once git-managed — environment is irrelevant.
		$this->assertTrue($this->makePaths()->locked());
	}

	// --- writeTarget ---

	public function testWriteTargetIsDataDirWhenAdminFirst(): void
	{
		$this->assertSame($this->dataBuilder, $this->makePaths()->writeTarget());
	}

	public function testWriteTargetIsProjectDirWhenGitManaged(): void
	{
		mkdir($this->projectBuilder, 0o777, true);

		$this->assertSame($this->projectBuilder, $this->makePaths()->writeTarget());
	}

	// --- readLayers (highest precedence first, existing dirs only) ---

	public function testReadLayersIsDataOnlyForAdminFirstProject(): void
	{
		$this->assertSame([$this->dataBuilder], $this->makePaths()->readLayers());
	}

	public function testReadLayersPutsProjectFirstWhenGitManaged(): void
	{
		mkdir($this->projectBuilder, 0o777, true);

		$this->assertSame([$this->projectBuilder, $this->dataBuilder], $this->makePaths()->readLayers());
	}

	public function testReadLayersExcludesDefaultsWhenNotShipped(): void
	{
		$paths = $this->makePaths();

		$this->assertNotContains($paths->defaultsDir(), $paths->readLayers());
	}

	public function testReadLayersLabeledTagsSources(): void
	{
		mkdir($this->projectBuilder, 0o777, true);

		$this->assertSame(
			[
				['layer' => BuilderTemplatePaths::LAYER_PROJECT, 'dir' => $this->projectBuilder],
				['layer' => BuilderTemplatePaths::LAYER_DATA, 'dir' => $this->dataBuilder],
			],
			$this->makePaths()->readLayersLabeled(),
		);
	}

	// --- resolveRead ---

	public function testResolveReadReturnsNullWhenAbsentEverywhere(): void
	{
		$this->assertNull($this->makePaths()->resolveRead('pages/about.twig'));
	}

	public function testResolveReadFindsFileInDataLayer(): void
	{
		mkdir($this->dataBuilder . '/pages', 0o777, true);
		file_put_contents($this->dataBuilder . '/pages/about.twig', 'x');

		$this->assertSame(
			['layer' => BuilderTemplatePaths::LAYER_DATA, 'path' => $this->dataBuilder . '/pages/about.twig'],
			$this->makePaths()->resolveRead('pages/about.twig'),
		);
	}

	public function testResolveReadPrefersProjectOverData(): void
	{
		mkdir($this->projectBuilder . '/pages', 0o777, true);
		mkdir($this->dataBuilder . '/pages', 0o777, true);
		file_put_contents($this->projectBuilder . '/pages/about.twig', 'project');
		file_put_contents($this->dataBuilder . '/pages/about.twig', 'data');

		$this->assertSame(
			['layer' => BuilderTemplatePaths::LAYER_PROJECT, 'path' => $this->projectBuilder . '/pages/about.twig'],
			$this->makePaths()->resolveRead('pages/about.twig'),
		);
	}

	// --- writePath ---

	public function testWritePathTargetsDataWhenAdminFirst(): void
	{
		$this->assertSame($this->dataBuilder . '/pages/about.twig', $this->makePaths()->writePath('pages/about.twig'));
	}

	public function testWritePathTargetsProjectWhenGitManaged(): void
	{
		mkdir($this->projectBuilder, 0o777, true);

		$this->assertSame($this->projectBuilder . '/pages/about.twig', $this->makePaths()->writePath('pages/about.twig'));
	}

	// --- loaderPaths (reserved admin templates always first) ---

	public function testLoaderPathsPrependReservedTemplates(): void
	{
		mkdir($this->projectBuilder, 0o777, true);

		$this->assertSame(
			[
				rtrim(\TotalCMS\Domain\Template\Repository\TemplateRepository::reservedTemplateDir(), '/'),
				$this->projectBuilder,
				$this->dataBuilder,
			],
			$this->makePaths()->loaderPaths(),
		);
	}
}
