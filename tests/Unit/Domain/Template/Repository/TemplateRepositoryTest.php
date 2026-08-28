<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Template\Repository;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Support\Config;

/**
 * Tests for `TemplateRepository` listing and the layer-aware builder reads
 * (git-first template workflow, Phase 1). We don't mock the filesystem —
 * the file behavior IS the contract.
 */
final class TemplateRepositoryTest extends TestCase
{
	private string $tmpRoot;
	private string $projectBuilder;
	private TemplateRepository $repo;

	protected function setUp(): void
	{
		$this->tmpRoot        = sys_get_temp_dir() . '/tcms-template-repo-' . uniqid();
		$this->projectBuilder = $this->tmpRoot . '/project/builder';
		mkdir($this->tmpRoot . '/builder/pages', 0755, true);
		mkdir($this->tmpRoot . '/builder/layouts', 0755, true);
		mkdir($this->tmpRoot . '/builder/.history/pages/about', 0755, true);
		mkdir($this->tmpRoot . '/builder/.history/layouts/default', 0755, true);

		// Default repo: admin-first (no project-root/builder).
		$this->repo = $this->makeRepo();
	}

	/**
	 * Build a repository whose datadir is the temp root. Git-management is
	 * driven by whether `<root>/builder` ($this->projectBuilder) exists —
	 * create it before calling to model a git-managed project.
	 */
	private function makeRepo(): TemplateRepository
	{
		$config          = $this->createMock(Config::class);
		$config->root    = $this->tmpRoot . '/project';
		$config->datadir = $this->tmpRoot;
		$config->builder = [];

		return new TemplateRepository(new BuilderTemplatePaths($config));
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmpRoot);
	}

	public function testTemplateNameCannotTraverseOutOfTheBuilderDirectory(): void
	{
		// A .twig file sitting outside the builder tree. `relativeTemplatePath()`
		// appends the extension, so an unsanitized name is an arbitrary-read
		// primitive for anything ending .twig — the folder argument was already
		// sanitized, the template name was not.
		file_put_contents($this->tmpRoot . '/secret.twig', 'TOP SECRET');

		$this->assertNull($this->repo->fetchBuilderTemplate('../secret'));
		$this->assertNull($this->repo->fetchBuilderTemplate('../../secret'));
		$this->assertNull($this->repo->fetchBuilderTemplate('pages/../../secret'));
		$this->assertFalse($this->repo->builderTemplateExists('../secret'));
	}

	public function testLeadingSlashInTemplateNameDoesNotEscapeToAbsolutePath(): void
	{
		file_put_contents($this->tmpRoot . '/secret.twig', 'TOP SECRET');

		$this->assertNull($this->repo->fetchBuilderTemplate('/' . $this->tmpRoot . '/secret'));
	}

	public function testNestedTemplateNamesStillResolveAfterSanitizing(): void
	{
		// Slashes are legitimate — a recursive listing returns nested names
		// like `pages/about`, and those must round-trip back through a fetch.
		// Over-sanitizing here would break the normal path.
		file_put_contents($this->tmpRoot . '/builder/pages/about.twig', '<h1>about</h1>');

		$template = $this->repo->fetchBuilderTemplate('pages/about');

		$this->assertNotNull($template);
		$this->assertSame('<h1>about</h1>', $template->contents);
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

	// --- layer-aware builder reads (Phase 1) ---

	public function testFetchBuilderTemplateReadsDataLayer(): void
	{
		file_put_contents($this->tmpRoot . '/builder/pages/about.twig', '<h1>about</h1>');

		$template = $this->repo->fetchBuilderTemplate('about', 'pages');

		$this->assertNotNull($template);
		$this->assertSame('<h1>about</h1>', $template->contents);
	}

	public function testFetchBuilderTemplatePrefersProjectLayer(): void
	{
		mkdir($this->projectBuilder . '/pages', 0755, true);
		file_put_contents($this->projectBuilder . '/pages/about.twig', '<h1>project</h1>');
		file_put_contents($this->tmpRoot . '/builder/pages/about.twig', '<h1>data</h1>');

		$repo     = $this->makeRepo();
		$template = $repo->fetchBuilderTemplate('about', 'pages');

		// Project layer wins — proven by the content it returned.
		$this->assertNotNull($template);
		$this->assertSame('<h1>project</h1>', $template->contents);
	}

	public function testFetchBuilderTemplateReturnsNullWhenAbsent(): void
	{
		$this->assertNull($this->repo->fetchBuilderTemplate('nope', 'pages'));
	}

	public function testBuilderTemplateExistsFindsProjectLayer(): void
	{
		mkdir($this->projectBuilder . '/pages', 0755, true);
		file_put_contents($this->projectBuilder . '/pages/about.twig', 'x');

		$repo = $this->makeRepo();

		$this->assertTrue($repo->builderTemplateExists('about', 'pages'));
		$this->assertFalse($repo->builderTemplateExists('missing', 'pages'));
	}

	public function testListMergesProjectAndDataLayersDeduped(): void
	{
		// data layer has about + contact; project layer has about + home.
		file_put_contents($this->tmpRoot . '/builder/pages/about.twig', 'data');
		file_put_contents($this->tmpRoot . '/builder/pages/contact.twig', 'data');
		mkdir($this->projectBuilder . '/pages', 0755, true);
		file_put_contents($this->projectBuilder . '/pages/about.twig', 'project');
		file_put_contents($this->projectBuilder . '/pages/home.twig', 'project');

		$repo   = $this->makeRepo();
		$result = $repo->listBuilderTemplates('pages', true);

		// Union, deduped, sorted — about appears once.
		$this->assertSame(['about', 'contact', 'home'], $result);
	}

	public function testListKeepsNumericTemplateNamesAsStrings(): void
	{
		// A template named "404" (the Not Found page) must come back as the
		// string '404', not int 404 — array-key dedup would coerce it and break
		// NestedFileTree's str_contains() in the builder sidebar.
		file_put_contents($this->tmpRoot . '/builder/pages/404.twig', 'x');
		file_put_contents($this->tmpRoot . '/builder/pages/about.twig', 'x');

		$result = $this->repo->listBuilderTemplates('pages', true);

		$this->assertContainsOnly('string', $result);
		$this->assertContains('404', $result);
	}

	// --- layer-aware writes (Phase 2) ---

	public function testSaveTemplateWritesToDataLayerWhenAdminFirst(): void
	{
		$template           = new \TotalCMS\Domain\Template\Data\TemplateData();
		$template->id       = 'about';
		$template->contents = '<h1>about</h1>';

		$this->repo->saveTemplate($template, 'pages');

		$this->assertFileExists($this->tmpRoot . '/builder/pages/about.twig');
		$this->assertSame('<h1>about</h1>', file_get_contents($this->tmpRoot . '/builder/pages/about.twig'));
	}

	public function testSaveTemplateWritesToProjectWhenManaged(): void
	{
		mkdir($this->projectBuilder, 0755, true);
		$repo               = $this->makeRepo();
		$template           = new \TotalCMS\Domain\Template\Data\TemplateData();
		$template->id       = 'about';
		$template->contents = '<h1>project</h1>';

		$repo->saveTemplate($template, 'pages');

		$this->assertFileExists($this->projectBuilder . '/pages/about.twig');
		$this->assertFileDoesNotExist($this->tmpRoot . '/builder/pages/about.twig');
	}

	public function testDeleteTemplateRemovesFromWriteTarget(): void
	{
		file_put_contents($this->tmpRoot . '/builder/pages/about.twig', 'x');

		$deleted = $this->repo->deleteTemplate('about', 'pages');

		$this->assertTrue($deleted);
		$this->assertFileDoesNotExist($this->tmpRoot . '/builder/pages/about.twig');
	}

	public function testDeleteTemplateIsIdempotentForMissingFile(): void
	{
		// Deleting an absent template reports success (the file is gone), matching
		// the prior Flysystem delete semantics. TemplateDeleteAction maps false to
		// HTTP 500, so a non-idempotent delete would 500 on a no-op delete.
		$this->assertTrue($this->repo->deleteTemplate('never-existed', 'pages'));
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
