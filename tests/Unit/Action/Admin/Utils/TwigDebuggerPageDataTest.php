<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Utils;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Utils\TwigDebuggerPageData;
use TotalCMS\Domain\Twig\Service\TwigLintService;
use TotalCMS\Support\Config;
use TotalCMS\Support\OperationResult;

final class TwigDebuggerPageDataTest extends TestCase
{
	private MockObject $twigLintService;
	private MockObject $request;
	private Config $config;
	private TwigDebuggerPageData $builder;
	private string $docroot;

	protected function setUp(): void
	{
		$this->docroot = sys_get_temp_dir() . '/twig-debugger-' . uniqid('', true);
		mkdir($this->docroot . '/templates', 0777, true);
		file_put_contents($this->docroot . '/templates/ok.twig', '{{ ok }}');
		// A sibling whose name merely starts with the docroot's: a prefix
		// check without the separator would let it through.
		mkdir($this->docroot . '-evil', 0777, true);
		file_put_contents($this->docroot . '-evil/steal.twig', '{{ no }}');

		$this->twigLintService = $this->createMock(TwigLintService::class);
		$this->request         = $this->createMock(ServerRequestInterface::class);
		$this->config          = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$this->config->docroot = $this->docroot;
		$this->builder         = new TwigDebuggerPageData($this->twigLintService, $this->config);
	}

	protected function tearDown(): void
	{
		foreach ([$this->docroot . '/templates/ok.twig', $this->docroot . '-evil/steal.twig'] as $file) {
			@unlink($file);
		}
		@rmdir($this->docroot . '/templates');
		@rmdir($this->docroot . '-evil');
		@rmdir($this->docroot);
	}

	private function get(string $filepath): void
	{
		$this->request->method('getMethod')->willReturn('GET');
		$this->request->method('getQueryParams')->willReturn(['filepath' => $filepath]);
	}

	public function testDebuggerWithoutAFilepathHasNoLintResults(): void
	{
		$this->request->method('getMethod')->willReturn('GET');
		$this->request->method('getQueryParams')->willReturn([]);
		$this->twigLintService->expects($this->never())->method('lintFile');

		$data = $this->builder->build($this->request, 'twig-debugger', '');

		$this->assertNull($data['lintResults']);
	}

	public function testDebuggerRejectsAPathThatDoesNotExist(): void
	{
		$this->request->method('getMethod')->willReturn('GET');
		$this->request->method('getQueryParams')->willReturn(['filepath' => 'nope/does-not-exist.twig']);
		$this->twigLintService->expects($this->never())->method('lintFile');

		$data = $this->builder->build($this->request, 'twig-debugger', '');

		$this->assertFalse($data['lintResults']['success']);
		$this->assertStringContainsString('File not found', $data['lintResults']['error']['message']);
		$this->assertSame('nope/does-not-exist.twig', $data['lintResults']['file']);
	}

	public function testDebuggerLintsAFileUnderTheDocumentRoot(): void
	{
		$this->get('/templates/ok.twig');
		$this->twigLintService->expects($this->once())->method('lintFile')
			->with(realpath($this->docroot . '/templates/ok.twig'))
			->willReturn(OperationResult::success('ok'));

		$data = $this->builder->build($this->request, 'twig-debugger', '');

		$this->assertTrue($data['lintResults']['success']);
	}

	public function testDebuggerDeniesATraversalOutsideTheDocumentRoot(): void
	{
		$this->get(str_repeat('../', 8) . 'etc/hosts');
		$this->twigLintService->expects($this->never())->method('lintFile');

		$data = $this->builder->build($this->request, 'twig-debugger', '');

		$this->assertStringContainsString('Access denied', $data['lintResults']['error']['message']);
	}

	public function testDebuggerDeniesASiblingWhoseNameStartsWithTheDocumentRoot(): void
	{
		// <docroot>-evil/steal.twig resolves fine and starts with the docroot
		// string; only the separator-aware check keeps it out.
		$this->get('../' . basename($this->docroot) . '-evil/steal.twig');
		$this->twigLintService->expects($this->never())->method('lintFile');

		$data = $this->builder->build($this->request, 'twig-debugger', '');

		$this->assertSame('Access denied: path outside document root', $data['lintResults']['error']['message']);
	}

	public function testDebuggerDeniesEverythingWhenThereIsNoDocumentRoot(): void
	{
		// The bug this pins: with an empty root every realpath "started with"
		// the root, and ../../etc/passwd was handed to the linter.
		$this->config->docroot = '';
		$this->get('../../etc/passwd');
		$this->twigLintService->expects($this->never())->method('lintFile');

		$data = $this->builder->build($this->request, 'twig-debugger', '');

		$this->assertSame('Access denied: no document root', $data['lintResults']['error']['message']);
	}

	public function testDebuggerReadsFilepathFromThePostBody(): void
	{
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn(['filepath' => 'nope.twig']);
		$this->twigLintService->expects($this->never())->method('lintFile');

		$data = $this->builder->build($this->request, 'twig-debugger', '');

		$this->assertFalse($data['lintResults']['success']);
		$this->assertStringContainsString('File not found: nope.twig', $data['lintResults']['error']['message']);
	}
}
