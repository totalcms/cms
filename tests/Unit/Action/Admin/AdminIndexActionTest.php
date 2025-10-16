<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Admin\AdminIndexAction;
use TotalCMS\Renderer\TwigRenderer;

final class AdminIndexActionTest extends TestCase
{
	private AdminIndexAction $action;
	private TwigRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->renderer = $this->createMock(TwigRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new AdminIndexAction($this->renderer);
	}

	public function testRendersIndexTemplate(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/index.twig',
				$this->callback(function ($data) {
					return isset($data['url']) && $data['url']['page'] === 'index';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesUrlData(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/index');
		$uri->method('getQuery')->willReturn('view=dashboard');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/index.twig',
				$this->callback(function ($data) {
					return isset($data['url']['path'])
						&& isset($data['url']['query'])
						&& $data['url']['path'] === '/admin/index'
						&& $data['url']['query'] === 'view=dashboard';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testSetsPageIdentifier(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/index.twig',
				$this->callback(function ($data) {
					return $data['url']['page'] === 'index';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesRoutingParams(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$args = ['id' => '123', 'action' => 'view'];

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/index.twig',
				$this->callback(function ($data) use ($args) {
					return $data['url']['params'] === $args;
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($expectedResponse, $result);
	}
}
