<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Builder\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;

/**
 * Phase 0 of the git-first template workflow (docs/planning/builder-git-workflow.md).
 *
 * BuilderTemplatePaths is the single resolver that owns the builder template
 * read hierarchy, the admin write target, and the edit lock. Pure path/lock
 * logic — no behavior is wired into the app yet.
 */
final class BuilderTemplatePathsTest extends TestCase
{
	private string $tmpRoot;
	private string $projectBuilder;
	private string $dataBuilder;

	protected function setUp(): void
	{
		// Unique scratch dirs per test. The project/builder dir is intentionally
		// NOT created here — individual tests create it to model "git-managed".
		$this->tmpRoot        = sys_get_temp_dir() . '/tcms-btp-' . uniqid('', true);
		$this->projectBuilder = $this->tmpRoot . '/project/builder';
		$this->dataBuilder    = $this->tmpRoot . '/tcms-data/builder';
		mkdir($this->dataBuilder, 0o777, true);
	}

	protected function tearDown(): void
	{
		$this->removeDir($this->tmpRoot);
	}

	/**
	 * @param array<string,mixed> $builder
	 */
	private function makePaths(array $builder = [], string $env = 'prod'): BuilderTemplatePaths
	{
		$config          = $this->createMock(Config::class);
		$config->env     = $env;
		$config->datadir = $this->tmpRoot . '/tcms-data';
		$config->builder = $builder;

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

	// --- projectDir ---

	public function testProjectDirDefaultsToProjectRootBuilder(): void
	{
		$paths = $this->makePaths();

		$this->assertSame(PathResolver::projectRoot() . '/builder', $paths->projectDir());
	}

	public function testProjectDirHonorsAbsoluteOverride(): void
	{
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder]);

		$this->assertSame($this->projectBuilder, $paths->projectDir());
	}

	public function testProjectDirResolvesRelativeOverrideAgainstProjectRoot(): void
	{
		$paths = $this->makePaths(['projectTemplates' => 'site/templates']);

		$this->assertSame(PathResolver::projectRoot() . '/site/templates', $paths->projectDir());
	}

	// --- dataDir / defaultsDir ---

	public function testDataDirIsDatadirBuilder(): void
	{
		$paths = $this->makePaths();

		$this->assertSame($this->tmpRoot . '/tcms-data/builder', $paths->dataDir());
	}

	public function testDefaultsDirIsPackagedUnderResources(): void
	{
		$paths = $this->makePaths();

		$this->assertSame(PathResolver::packageRoot() . '/resources/builder/defaults', $paths->defaultsDir());
	}

	// --- isProjectManaged ---

	public function testIsProjectManagedFalseWhenProjectDirAbsent(): void
	{
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder]);

		$this->assertFalse($paths->isProjectManaged());
	}

	public function testIsProjectManagedTrueWhenProjectDirExists(): void
	{
		mkdir($this->projectBuilder, 0o777, true);
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder]);

		$this->assertTrue($paths->isProjectManaged());
	}

	// --- writeTarget ---

	public function testWriteTargetIsDataDirWhenNotProjectManaged(): void
	{
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder]);

		$this->assertSame($this->dataBuilder, $paths->writeTarget());
	}

	public function testWriteTargetIsProjectDirWhenProjectManaged(): void
	{
		mkdir($this->projectBuilder, 0o777, true);
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder]);

		$this->assertSame($this->projectBuilder, $paths->writeTarget());
	}

	// --- readLayers (highest precedence first, existing dirs only) ---

	public function testReadLayersIsDataOnlyForAdminFirstProject(): void
	{
		// No project dir, no shipped defaults dir → only tcms-data/builder.
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder]);

		$this->assertSame([$this->dataBuilder], $paths->readLayers());
	}

	public function testReadLayersPutsProjectFirstWhenManaged(): void
	{
		mkdir($this->projectBuilder, 0o777, true);
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder]);

		$this->assertSame([$this->projectBuilder, $this->dataBuilder], $paths->readLayers());
	}

	public function testReadLayersExcludesDefaultsWhenNotShipped(): void
	{
		// The shipped defaults dir doesn't exist until Phase 4 — it must be
		// filtered out, never returned as a non-existent path.
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder]);

		$this->assertNotContains($paths->defaultsDir(), $paths->readLayers());
	}

	// --- loaderPaths (reserved admin templates always first) ---

	public function testLoaderPathsPrependReservedTemplates(): void
	{
		mkdir($this->projectBuilder, 0o777, true);
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder]);

		$this->assertSame(
			[
				rtrim(\TotalCMS\Domain\Template\Repository\TemplateRepository::reservedTemplateDir(), '/'),
				$this->projectBuilder,
				$this->dataBuilder,
			],
			$paths->loaderPaths(),
		);
	}

	// --- locked ---

	public function testLockedTrueWhenExplicitlyEnabled(): void
	{
		$paths = $this->makePaths(['lockTemplates' => true], env: 'dev');

		$this->assertTrue($paths->locked());
	}

	public function testLockedFalseWhenExplicitlyDisabledEvenInProd(): void
	{
		mkdir($this->projectBuilder, 0o777, true);
		$paths = $this->makePaths(
			['projectTemplates' => $this->projectBuilder, 'lockTemplates' => false],
			env: 'prod',
		);

		$this->assertFalse($paths->locked());
	}

	public function testLockedAutoOnForGitManagedProductionSite(): void
	{
		mkdir($this->projectBuilder, 0o777, true);
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder], env: 'prod');

		$this->assertTrue($paths->locked());
	}

	public function testLockedAutoOffForGitManagedDevSite(): void
	{
		mkdir($this->projectBuilder, 0o777, true);
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder], env: 'dev');

		$this->assertFalse($paths->locked());
	}

	public function testLockedAutoOffForAdminFirstProductionSite(): void
	{
		// Back-compat keystone: a prod site with no project-root/builder has
		// nothing in git to protect, so auto-lock must stay OFF — otherwise the
		// 200+ existing admin-first sites lose in-admin template editing.
		$paths = $this->makePaths(['projectTemplates' => $this->projectBuilder], env: 'prod');

		$this->assertFalse($paths->locked());
	}
}
