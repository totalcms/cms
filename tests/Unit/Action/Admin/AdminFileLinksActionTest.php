<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\AdminFileLinksAction;
use TotalCMS\Renderer\TwigRenderer;

final class AdminFileLinksActionTest extends TestCase
{
	private AdminFileLinksAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->renderer = $this->createMock(TwigRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new AdminFileLinksAction($this->renderer);
	}

	public function testRendersFileLinksTemplate(): void
	{
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->expects($this->once())
			->method('template')
			->with($this->response, 'admin/filelinks.twig')
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($expectedResponse, $result);
	}

	public function testDoesNotRequireUrlData(): void
	{
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/filelinks.twig',
				$this->anything()
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($expectedResponse, $result);
	}

	public function testReturnsResponseFromRenderer(): void
	{
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->method('template')->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	public function testAcceptsRequestParameter(): void
	{
		$request          = $this->createMock(ServerRequestInterface::class);
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->method('template')->willReturn($expectedResponse);

		// Should not throw exception
		$result = ($this->action)($request, $this->response);

		$this->assertSame($expectedResponse, $result);
	}

	public function testAcceptsResponseParameter(): void
	{
		$response         = $this->createMock(ResponseInterface::class);
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->expects($this->once())
			->method('template')
			->with($response, 'admin/filelinks.twig')
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $response);

		$this->assertSame($expectedResponse, $result);
	}
}
