<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Utils;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Utils\TwigDebuggerPageData;
use TotalCMS\Domain\Twig\Service\TwigLintService;

final class TwigDebuggerPageDataTest extends TestCase
{
	private MockObject $twigLintService;
	private MockObject $request;
	private TwigDebuggerPageData $builder;

	protected function setUp(): void
	{
		$this->twigLintService = $this->createMock(TwigLintService::class);
		$this->request         = $this->createMock(ServerRequestInterface::class);
		$this->builder         = new TwigDebuggerPageData($this->twigLintService);
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
