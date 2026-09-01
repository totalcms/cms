<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Mcp\Tool\Admin\TemplateTools;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;

final class TemplateToolsTest extends TestCase
{
	private \PHPUnit\Framework\MockObject\MockObject $lister;
	private \PHPUnit\Framework\MockObject\MockObject $fetcher;
	private \PHPUnit\Framework\MockObject\MockObject $paths;
	private TemplateTools $tool;

	// Knob read by the paths mock's willReturnCallback — set inside a test to
	// flip the install between admin-first and git-managed after setUp() has
	// already registered the mock (see ObjectToolsTest for why stacked
	// willReturn on the same method doesn't work).
	private bool $projectManaged = false;

	protected function setUp(): void
	{
		$this->lister  = $this->createMock(TemplateLister::class);
		$this->fetcher = $this->createMock(TemplateFetcher::class);
		$this->paths   = $this->createMock(BuilderTemplatePaths::class);
		$this->paths->method('isProjectManaged')
			->willReturnCallback(fn (): bool => $this->projectManaged);

		$this->tool = new TemplateTools($this->lister, $this->fetcher, $this->paths);
	}

	private function template(string $id, string $contents): TemplateData
	{
		$template           = new TemplateData();
		$template->id       = $id;
		$template->contents = $contents;

		return $template;
	}

	// ─── registration ────────────────────────────────────────────────────────

	public function testRegistersBothToolsWithAdminAccess(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		foreach (['list_templates', 'get_template'] as $name) {
			$definition = $registry->get($name);
			$this->assertNotNull($definition, $name . ' was not registered');
			$this->assertSame('admin', $definition->access);
		}
	}

	public function testNoSaveToolIsRegistered(): void
	{
		// The read-only scope is the whole point — a write tool arriving by
		// accident is exactly the regression this pins.
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		$this->assertNull($registry->get('save_template'));
		$this->assertNull($registry->get('update_template'));
		$this->assertNull($registry->get('delete_template'));
	}

	public function testBothToolsAnnotatedReadOnly(): void
	{
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		foreach (['list_templates', 'get_template'] as $name) {
			$ann = $registry->get($name)->annotations;
			$this->assertNotNull($ann);
			$this->assertTrue($ann->readOnlyHint, $name . ' should be readOnly');
			$this->assertFalse($ann->destructiveHint);
		}
	}

	public function testToolsDeclareTheBuilderReadRequirementTriple(): void
	{
		// Pin the exact triple: a wrong domain string falls through
		// ToolRequirement's match() default and silently denies everyone.
		$registry = new ToolRegistry();
		$this->tool->register($registry);

		foreach (['list_templates', 'get_template'] as $name) {
			$requires = $registry->get($name)->requires;
			$this->assertNotNull($requires, $name . ' has no requirement');
			$this->assertSame('builder', $requires->domain);
			$this->assertSame('read', $requires->operation);
			// 'builder' is a page-level grant — no per-target argument.
			$this->assertNull($requires->collectionArg);
			$this->assertSame('cms:admin', $requires->requiredScope());
		}
	}

	// ─── list_templates ──────────────────────────────────────────────────────

	public function testListReturnsTemplatesAndTotal(): void
	{
		$this->lister->method('listBuilderTemplates')
			->willReturn(['layouts/base', 'pages/home', 'partials/nav']);

		$result = $this->tool->listHandler();

		$this->assertSame(['layouts/base', 'pages/home', 'partials/nav'], $result['templates']);
		$this->assertSame(3, $result['total']);
	}

	public function testListPassesFolderAndRecursiveThrough(): void
	{
		$this->lister->expects($this->once())
			->method('listBuilderTemplates')
			->with('pages', false)
			->willReturn(['home']);

		$this->tool->listHandler('pages', false);
	}

	public function testListDefaultsToRecursive(): void
	{
		// Nested paths are the shape get_template accepts, so a non-recursive
		// default would hide most of the site from the agent.
		$this->lister->expects($this->once())
			->method('listBuilderTemplates')
			->with(null, true)
			->willReturn([]);

		$this->tool->listHandler();
	}

	public function testListReportsAdminManagedInstall(): void
	{
		$this->lister->method('listBuilderTemplates')->willReturn([]);

		$result = $this->tool->listHandler();

		$this->assertFalse($result['git_managed']);
		$this->assertStringContainsString('admin UI', $result['edit_via']);
	}

	public function testListReportsGitManagedInstall(): void
	{
		$this->projectManaged = true;
		$this->lister->method('listBuilderTemplates')->willReturn([]);

		$result = $this->tool->listHandler();

		$this->assertTrue($result['git_managed']);
		$this->assertStringContainsString('git', $result['edit_via']);
	}

	public function testListRejectsTraversalInFolder(): void
	{
		$this->lister->expects($this->never())->method('listBuilderTemplates');

		$this->expectException(ToolCallException::class);
		$this->tool->listHandler('../../etc');
	}

	// ─── get_template ────────────────────────────────────────────────────────

	public function testGetReturnsTemplateSource(): void
	{
		$this->fetcher->method('fetchBuilderTemplate')
			->willReturn($this->template('home', '<h1>{{ page.data.hero }}</h1>'));
		$this->paths->method('resolveRead')
			->willReturn(['layer' => 'data', 'path' => '/tmp/builder/pages/home.twig']);

		$result = $this->tool->getHandler('pages/home');

		$this->assertSame('<h1>{{ page.data.hero }}</h1>', $result['template']);
		$this->assertSame('pages/home', $result['path']);
	}

	public function testGetSplitsPathIntoFolderAndName(): void
	{
		// The repository addresses templates as (name, folder); the tool takes
		// one path. A split regression here reads the wrong file rather than
		// erroring, so pin it.
		$this->fetcher->expects($this->once())
			->method('fetchBuilderTemplate')
			->with('home', 'pages')
			->willReturn($this->template('home', 'x'));
		$this->paths->method('resolveRead')->willReturn(null);

		$this->tool->getHandler('pages/home');
	}

	public function testGetHandlesBareTemplateNameWithNoFolder(): void
	{
		$this->fetcher->expects($this->once())
			->method('fetchBuilderTemplate')
			->with('home', null)
			->willReturn($this->template('home', 'x'));
		$this->paths->method('resolveRead')->willReturn(null);

		$this->tool->getHandler('home');
	}

	public function testGetReportsResolvedReadLayer(): void
	{
		$this->fetcher->method('fetchBuilderTemplate')
			->willReturn($this->template('home', 'x'));
		$this->paths->method('resolveRead')
			->willReturn(['layer' => 'project', 'path' => '/srv/site/builder/pages/home.twig']);

		$result = $this->tool->getHandler('pages/home');

		$this->assertSame('project', $result['layer']);
	}

	public function testGetToleratesUnresolvableLayer(): void
	{
		$this->fetcher->method('fetchBuilderTemplate')
			->willReturn($this->template('home', 'x'));
		$this->paths->method('resolveRead')->willReturn(null);

		$result = $this->tool->getHandler('pages/home');

		$this->assertNull($result['layer']);
	}

	public function testGetThrowsWithDiscoveryHintWhenMissing(): void
	{
		$this->fetcher->method('fetchBuilderTemplate')->willReturn(null);

		$this->expectException(ToolCallException::class);
		$this->expectExceptionMessageMatches('/list_templates/');
		$this->tool->getHandler('pages/nope');
	}

	public function testGetRejectsTraversalPath(): void
	{
		// Read-only makes traversal MORE dangerous, not less: unguarded, this
		// tool is an arbitrary-file-read primitive for anything ending .twig.
		$this->fetcher->expects($this->never())->method('fetchBuilderTemplate');

		$this->expectException(ToolCallException::class);
		$this->tool->getHandler('../../../../etc/passwd');
	}

	public function testGetRejectsAbsolutePath(): void
	{
		$this->fetcher->expects($this->never())->method('fetchBuilderTemplate');

		$this->expectException(ToolCallException::class);
		$this->tool->getHandler('/etc/passwd');
	}

	public function testGetRejectsBackslashPath(): void
	{
		$this->fetcher->expects($this->never())->method('fetchBuilderTemplate');

		$this->expectException(ToolCallException::class);
		$this->tool->getHandler('pages\\..\\..\\secrets');
	}

	public function testGetUsesBuilderOnlyFetchSoReservedTemplatesStayHidden(): void
	{
		// fetchTemplate() falls back to the reserved admin templates; this
		// surface must never reach them.
		$this->fetcher->expects($this->never())->method('fetchTemplate');
		$this->fetcher->expects($this->once())
			->method('fetchBuilderTemplate')
			->willReturn($this->template('home', 'x'));
		$this->paths->method('resolveRead')->willReturn(null);

		$this->tool->getHandler('pages/home');
	}

	public function testGetCarriesManagementStateForwardToo(): void
	{
		$this->projectManaged = true;
		$this->fetcher->method('fetchBuilderTemplate')
			->willReturn($this->template('home', 'x'));
		$this->paths->method('resolveRead')->willReturn(null);

		$result = $this->tool->getHandler('pages/home');

		$this->assertTrue($result['git_managed']);
	}
}
