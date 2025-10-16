<?php

namespace Tests\Unit\Action\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TotalCMS\Action\Admin\AdminMailerAction;
use TotalCMS\Renderer\TwigRenderer;

final class AdminMailerActionTest extends TestCase
{
	private AdminMailerAction $action;
	private TwigRenderer $renderer;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->renderer = $this->createMock(TwigRenderer::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new AdminMailerAction($this->renderer);
	}

	public function testRendersMailerTemplate(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/mailer');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/mailer.twig',
				$this->callback(function ($data) {
					return isset($data['url'])
						&& $data['url']['page'] === 'mailer';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesUrlPath(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/mailer/send');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/mailer.twig',
				$this->callback(function ($data) {
					return isset($data['url']['path'])
						&& $data['url']['path'] === '/admin/mailer/send';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesQueryString(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/mailer');
		$uri->method('getQuery')->willReturn('test=true');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/mailer.twig',
				$this->callback(function ($data) {
					return isset($data['url']['query'])
						&& $data['url']['query'] === 'test=true';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testSetsPageIdentifier(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/mailer');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/mailer.twig',
				$this->callback(function ($data) {
					return isset($data['url']['page'])
						&& $data['url']['page'] === 'mailer';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testSetsCollectionIdentifier(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/mailer');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/mailer.twig',
				$this->callback(function ($data) {
					return isset($data['url']['collection'])
						&& $data['url']['collection'] === 'mailer';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesIdParameter(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/mailer/template-123');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$args = ['id' => 'template-123'];

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/mailer.twig',
				$this->callback(function ($data) {
					return isset($data['url']['id'])
						&& $data['url']['id'] === 'template-123';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesMissingIdParameter(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/mailer');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/mailer.twig',
				$this->callback(function ($data) {
					return isset($data['url']['id'])
						&& $data['url']['id'] === '';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testHandlesEmptyQueryString(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/mailer');
		$uri->method('getQuery')->willReturn('');

		$this->request->method('getUri')->willReturn($uri);

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/mailer.twig',
				$this->callback(function ($data) {
					return isset($data['url']['query'])
						&& $data['url']['query'] === '';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, []);

		$this->assertSame($expectedResponse, $result);
	}

	public function testIncludesAllUrlData(): void
	{
		$uri = $this->createMock(UriInterface::class);
		$uri->method('getPath')->willReturn('/admin/mailer/template-456');
		$uri->method('getQuery')->willReturn('mode=edit');

		$this->request->method('getUri')->willReturn($uri);

		$args = ['id' => 'template-456'];

		$expectedResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('template')
			->with(
				$this->response,
				'admin/mailer.twig',
				$this->callback(function ($data) {
					return isset($data['url']['path'])
						&& isset($data['url']['query'])
						&& isset($data['url']['page'])
						&& isset($data['url']['id'])
						&& isset($data['url']['collection'])
						&& $data['url']['path'] === '/admin/mailer/template-456'
						&& $data['url']['query'] === 'mode=edit'
						&& $data['url']['page'] === 'mailer'
						&& $data['url']['id'] === 'template-456'
						&& $data['url']['collection'] === 'mailer';
				})
			)
			->willReturn($expectedResponse);

		$result = ($this->action)($this->request, $this->response, $args);

		$this->assertSame($expectedResponse, $result);
	}
}
