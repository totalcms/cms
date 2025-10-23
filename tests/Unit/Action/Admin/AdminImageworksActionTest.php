<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\AdminImageworksAction;
use TotalCMS\Renderer\TwigRenderer;

final class AdminImageworksActionTest extends TestCase
{
	private AdminImageworksAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->renderer = $this->createMock(TwigRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new AdminImageworksAction($this->renderer);
	}

	public function testRendersImageworksTemplate(): void
	{
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->expects($this->once())
			->method('template')
			->with($this->response, 'admin/imageworks.twig')
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
				'admin/imageworks.twig',
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

		$result = ($this->action)($request, $this->response);

		$this->assertSame($expectedResponse, $result);
	}

	public function testAcceptsResponseParameter(): void
	{
		$response         = $this->createMock(ResponseInterface::class);
		$expectedResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->expects($this->once())
			->method('template')
			->with($response, 'admin/imageworks.twig')
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $response);

		$this->assertSame($expectedResponse, $result);
	}
}
