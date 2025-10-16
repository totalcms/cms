<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Admin\AdminEditProfileAction;
use TotalCMS\Renderer\TwigRenderer;

final class AdminEditProfileActionTest extends TestCase
{
	private AdminEditProfileAction $action;
	private \PHPUnit\Framework\MockObject\MockObject $renderer;
	private \PHPUnit\Framework\MockObject\MockObject $request;
	private \PHPUnit\Framework\MockObject\MockObject $response;

	protected function setUp(): void
	{
		$this->renderer = $this->createMock(TwigRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new AdminEditProfileAction($this->renderer);
	}

	public function testRendersProfileTemplate(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/profile');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/profile.twig',
				$this->callback(fn ($data): bool => isset($data['url'])
						&& $data['url']['page'] === 'profile')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesUrlPath(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/profile/edit');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/profile.twig',
				$this->callback(fn ($data): bool => isset($data['url']['path'])
						&& $data['url']['path'] === '/admin/profile/edit')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesQueryString(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/profile');
		$uri->method('getQuery')->willReturn('tab=settings');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/profile.twig',
				$this->callback(fn ($data): bool => isset($data['url']['query'])
						&& $data['url']['query'] === 'tab=settings')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesRoutingParams(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/profile');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$args = ['id' => '123', 'action' => 'edit'];

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/profile.twig',
				$this->callback(fn ($data): bool => isset($data['url']['params'])
						&& $data['url']['params'] === $args)
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($expectedResponse, $result);
	}

	public function testSetsCorrectPageIdentifier(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/profile');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/profile.twig',
				$this->callback(fn ($data): bool => isset($data['url']['page'])
						&& $data['url']['page'] === 'profile')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesEmptyQueryString(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/profile');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/profile.twig',
				$this->callback(fn ($data): bool => isset($data['url']['query'])
						&& $data['url']['query'] === '')
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesEmptyParams(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/profile');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/profile.twig',
				$this->callback(fn ($data): bool => isset($data['url']['params'])
						&& $data['url']['params'] === [])
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}
}
